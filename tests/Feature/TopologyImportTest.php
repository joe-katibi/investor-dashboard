<?php

namespace Tests\Feature;

use App\Filament\Resources\Olts\Pages\ManageOlts;
use App\Models\Fdt;
use App\Models\Olt;
use App\Models\Port;
use App\Models\Slot;
use App\Services\Topology\TopologyImporter;
use Database\Seeders\TopologySeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** OLT → FDT → Slot → Port import from the mapping spreadsheet (columns OLT | FDT | SLOT | PORT). */
class TopologyImportTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private const CSV = <<<'CSV'
OLT,FDT,SLOT,PORT
KIIRA,KIRA FDT 1,1,1
KIIRA,KIRA FDT 1,1,2
KIIRA,KIRA FDT 2,1,8
KIIRA,FDT 24 ,2,1
NAGURU,Ngr FDT 9,3,4
NAGURU,NGR FDT 9,3,5
NAGURU,FDT 24,4,1
CSV;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/topology'));
        parent::tearDown();
    }

    private function file(string $content = self::CSV, string $name = 'mapping.csv'): string
    {
        $path = storage_path('framework/testing/topology/'.uniqid().'-'.$name);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);

        return $path;
    }

    public function test_import_builds_the_hierarchy_tidies_names_and_is_safe_to_rerun(): void
    {
        $importer = app(TopologyImporter::class);

        $preview = $importer->import($this->file(), dryRun: true);
        $this->assertTrue($preview['dry_run']);
        $this->assertSame(0, Olt::query()->count(), 'a preview saves nothing');

        $report = $importer->import($this->file());
        $this->assertSame(['olts' => 2, 'fdts' => 5, 'slots' => 5, 'ports' => 7], $report['created']);

        $this->assertSame(1, Fdt::query()->where('name', 'NGR FDT 9')->count(), '"Ngr FDT 9" and "NGR FDT 9" are one FDT');
        $this->assertSame(2, Fdt::query()->where('name', 'FDT 24')->count(), 'same FDT name under two OLTs stays separate');
        $this->assertSame('KIIRA › KIRA FDT 1 › Slot 1 › Port 2', Port::query()->with('slot.fdt.olt')->where('name', '2')->first()->path);

        $again = $importer->import($this->file());
        $this->assertSame(['olts' => 0, 'fdts' => 0, 'slots' => 0, 'ports' => 0], $again['created']);
        $this->assertSame(7, Port::query()->count());
    }

    public function test_existing_items_are_reused_case_insensitively_and_missing_ones_can_be_deactivated(): void
    {
        $old = Olt::query()->create(['name' => 'Kiira', 'is_active' => true]);
        $retired = Olt::query()->create(['name' => 'OLD OLT', 'is_active' => true]);

        app(TopologyImporter::class)->import($this->file(), missing: TopologyImporter::DEACTIVATE);

        $this->assertSame(3, Olt::query()->count(), 'KIIRA matched the existing "Kiira"');
        $this->assertSame(3, $old->fdts()->count());
        $this->assertFalse($retired->refresh()->is_active, 'deactivate mode keeps the row');
        $this->assertSame(7, Port::query()->where('is_active', true)->count());
    }

    public function test_remove_deletes_old_topology_but_keeps_what_tickets_use(): void
    {
        // Old OLT with a full chain, unused → removed completely.
        $unused = $this->makeOlt('OLD UNUSED');
        $unusedFdt = Fdt::query()->create(['olt_id' => $unused->id, 'name' => 'FDT X', 'is_active' => true]);
        Port::query()->create(['slot_id' => Slot::query()->create(['fdt_id' => $unusedFdt->id, 'name' => '9', 'is_active' => true])->id, 'name' => '1', 'is_active' => true]);

        // Old OLT on a ticket → kept for history but deactivated; its unused FDT is removed.
        $onTicket = $this->makeOlt('OLD ON TICKET');
        Fdt::query()->create(['olt_id' => $onTicket->id, 'name' => 'FDT Y', 'is_active' => true]);
        $this->makeUser('NOC Tier I', 'NOC');
        $outage = $this->reportOutage($this->makeUser('CX Agent', 'CX'), ['olt_ids' => [$onTicket->id]]);

        $report = app(TopologyImporter::class)->import($this->file(), missing: TopologyImporter::REMOVE);

        $this->assertNull(Olt::query()->find($unused->id), 'unused old OLT removed');
        $this->assertSame(0, Fdt::query()->where('name', 'FDT X')->count(), 'with its FDT, slot and port');
        $this->assertFalse($onTicket->refresh()->is_active, 'on a ticket → deactivated, not deleted');
        $this->assertSame(0, Fdt::query()->where('name', 'FDT Y')->count());
        $this->assertSame([$onTicket->id], $outage->olts()->pluck('olts.id')->all(), 'ticket history intact');
        $this->assertSame(['olts' => 1, 'fdts' => 2, 'slots' => 1, 'ports' => 1], $report['removed']);
        $this->assertSame(1, $report['deactivated']['olts']);
        $this->assertSame(['KIIRA', 'NAGURU'], Olt::query()->where('is_active', true)->orderBy('name')->pluck('name')->all());
    }

    public function test_bad_rows_stop_the_whole_import(): void
    {
        $report = app(TopologyImporter::class)->import($this->file("OLT,FDT,SLOT,PORT\nKIIRA,FDT 1,1,1\nKIIRA,,1,2\nKIIRA,FDT 1,x,3\n"));

        $this->assertCount(2, $report['errors']);
        $this->assertStringContainsString('Row 3', $report['errors'][0]);
        $this->assertSame(0, Olt::query()->count());

        $this->expectException(\InvalidArgumentException::class);
        app(TopologyImporter::class)->import($this->file("NAME,FDT\nx,y\n"));
    }

    public function test_command_and_seeder(): void
    {
        $this->artisan('oms:import-topology', ['file' => $this->file(), '--dry-run' => true])->expectsOutputToContain('DRY RUN')->assertSuccessful();
        $this->assertSame(0, Slot::query()->count());

        $this->artisan('oms:import-topology', ['file' => $this->file()])->assertSuccessful();
        $this->assertSame(5, Slot::query()->count());

        // Seeder (normally reads storage/app/private/topology/mapping.xlsx, kept out of git) — temp file here
        // so a real mapping on this machine is never touched.
        TopologySeeder::$path = $this->file("OLT,FDT,SLOT,PORT\nSEGUKU,SGK FDT 1,1,1\n");

        try {
            $this->seed(TopologySeeder::class);
            $this->assertTrue(Olt::query()->where('name', 'SEGUKU')->exists());
        } finally {
            TopologySeeder::$path = null;
        }
    }

    public function test_bundled_mapping_seeds_the_real_network(): void
    {
        [$rows, $errors] = app(TopologyImporter::class)->read(TopologySeeder::bundledPath());
        $this->assertSame([], $errors, 'the bundled mapping has no bad rows');
        $this->assertCount(3527, $rows);

        $this->seed(TopologySeeder::class);
        $this->seed(TopologySeeder::class); // re-seeding adds nothing

        $this->assertSame([20, 425, 840, 3527], [Olt::query()->count(), Fdt::query()->count(), Slot::query()->count(), Port::query()->count()]);
        $this->assertSame('KIIRA › KIRA FDT 1 › Slot 1 › Port 1', Port::query()->with('slot.fdt.olt')
            ->whereHas('slot.fdt.olt', fn ($q) => $q->where('name', 'KIIRA'))->orderBy('id')->first()->path);
        $this->assertSame(8, Fdt::query()->where('name', 'FDT 24')->count(), 'shared FDT names stay separate per OLT');
    }

    public function test_olt_page_import_button(): void
    {
        $this->actingAs($this->makeUser('Infrastructure Manager', 'INFRA', attributes: ['availability_status' => 'offline']));

        Livewire::test(ManageOlts::class)
            ->callAction(TestAction::make('importTopology'), [
                'file' => UploadedFile::fake()->createWithContent('mapping.csv', self::CSV),
                'dry_run' => false,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame(7, Port::query()->count());
    }
}
