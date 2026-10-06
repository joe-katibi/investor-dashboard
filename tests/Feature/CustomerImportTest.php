<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\Pages\ManageCustomers;
use App\Models\Customer;
use App\Models\Fdt;
use App\Models\Olt;
use App\Services\Tickets\CustomerImporter;
use App\Services\Tickets\CustomerImportRun;
use App\Support\InMemorySharedStrings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\FileBasedStrategy;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\InMemoryStrategy;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Importing a full customer list: files of any size are read quickly, a large import runs in the
 * background, and rows whose network position is incomplete are either listed or imported with the
 * part that is complete — as the person chooses.
 */
class CustomerImportTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private const HEADER = "Alias Name,ONU Name,OLT,FDT,SLOT,PORT,Bandwidth\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
    }

    private function csv(string $rows): string
    {
        file_put_contents($path = tempnam(sys_get_temp_dir(), 'cust').'.csv', self::HEADER.$rows);

        return $path;
    }

    public function test_rows_with_an_incomplete_position_are_listed_or_imported_with_what_is_complete(): void
    {
        $path = $this->csv(
            "Complete Customer,SF100,Kireka,FDT 1,3,7,\n"
            ."No Fdt,SF101,Kireka,,8,4,\n"          // slot and port, but no FDT to hang them on
            ."No Slot,SF102,Kireka,FDT 1,,9,\n"     // port without a slot
            ."No Olt,SF103,,FDT 9,2,1,\n"           // FDT without an OLT
            ."Olt Only,SF104,Kireka,,,,\n"          // nothing wrong with this one
        );
        $importer = app(CustomerImporter::class);

        // By default nothing is imported and the rows are named.
        $report = $importer->import($path);
        $this->assertSame(3, $report['error_rows']);
        $this->assertSame(['Row 3: SLOT given without an FDT', 'Row 4: PORT given without a SLOT', 'Row 5: FDT given without an OLT'], $report['errors']);
        $this->assertSame(0, Customer::query()->count());
        $outcome = CustomerImportRun::outcome($report);
        $this->assertSame('Nothing imported — 3 of 5 rows need fixing', $outcome['title']);
        $this->assertStringContainsString('can be imported anyway', end($outcome['lines']));

        // On request the customers are kept, each with the part of the position that is complete.
        $preview = $importer->import($path, dryRun: true, keepIncomplete: true);
        $this->assertSame([5, 5, 3, []], [$preview['rows'], $preview['created'], $preview['incomplete'], $preview['errors']]);
        $this->assertSame(0, Customer::query()->count(), 'a preview saves nothing');

        $report = $importer->import($path, keepIncomplete: true);
        @unlink($path);
        $this->assertSame([5, 5, 3], [$report['rows'], $report['created'], $report['incomplete']]);
        $this->assertSame(['olts' => 1, 'fdts' => 1, 'slots' => 1, 'ports' => 1], $report['topology'], 'nothing is invented for the incomplete rows');

        $customer = fn (string $account) => Customer::query()->where('account_number', $account)->firstOrFail();
        $kireka = Olt::query()->where('name', 'Kireka')->value('id');
        $fdt = Fdt::query()->where('name', 'FDT 1')->value('id');
        $this->assertSame('Kireka › FDT 1 › Slot 3 › Port 7', $customer('SF100')->topologyPath());
        $this->assertSame([$kireka, null, null, null], array_values($customer('SF101')->only(['olt_id', 'fdt_id', 'slot_id', 'port_id'])), 'OLT only');
        $this->assertSame([$kireka, $fdt, null, null], array_values($customer('SF102')->only(['olt_id', 'fdt_id', 'slot_id', 'port_id'])), 'down to the FDT');
        $this->assertSame([null, null, null, null], array_values($customer('SF103')->only(['olt_id', 'fdt_id', 'slot_id', 'port_id'])), 'no position at all');
        $this->assertSame('No Olt', $customer('SF103')->name);

        $outcome = CustomerImportRun::outcome($report);
        $this->assertSame('Customers imported and saved', $outcome['title']);
        $this->assertStringContainsString('3 customers have an incomplete position', implode(' ', $outcome['lines']));

        // Other mistakes are never waved through.
        $bad = $this->csv("Bad Slot,SF200,Kireka,FDT 1,x,1,\n,,Kireka,FDT 1,1,1,\nTwice,SF100,Kireka,FDT 1,1,1,\nTwice,sf100,Kireka,FDT 1,1,1,\n");
        $report = $importer->import($bad, keepIncomplete: true);
        @unlink($bad);
        $this->assertSame(3, $report['error_rows']);
        $this->assertFalse(Customer::query()->where('account_number', 'SF200')->exists());
    }

    public function test_every_problem_row_is_counted_though_only_the_first_are_listed(): void
    {
        $rows = '';
        for ($i = 1; $i <= 260; $i++) {
            $rows .= "Customer {$i},SF{$i},Kireka,,5,2,\n";
        }
        $path = $this->csv($rows."Fine,SF9999,Kireka,FDT 1,1,1,\n");

        $report = app(CustomerImporter::class)->import($path);
        @unlink($path);

        $this->assertSame(261, $report['rows']);
        $this->assertSame(260, $report['error_rows']);
        $this->assertCount(CustomerImporter::ERRORS_LISTED, $report['errors']);

        $outcome = CustomerImportRun::outcome($report);
        $this->assertSame('Nothing imported — 260 of 261 rows need fixing', $outcome['title']);
        $this->assertContains('… and 245 more.', $outcome['lines']);
    }

    public function test_a_name_longer_than_the_field_is_cut_to_fit_not_a_reason_to_stop(): void
    {
        $long = str_repeat('Very long customer name ', 9); // 216 characters: notes typed into the name
        $path = $this->csv("{$long},SF1,Kireka,FDT 1,1,1,\nShort Name,SF2,Kireka,FDT 1,1,2,\n");

        $report = app(CustomerImporter::class)->import($path);
        @unlink($path);

        $this->assertSame([2, 2, 1, []], [$report['rows'], $report['created'], $report['shortened'], $report['errors']]);
        $name = Customer::query()->where('account_number', 'SF1')->value('name');
        $this->assertSame(rtrim(mb_substr(trim($long), 0, 190)), $name);
        $this->assertLessThanOrEqual(190, mb_strlen($name));
        $this->assertStringStartsWith('Very long customer name Very long', $name);
        $this->assertStringContainsString('1 value(s) were longer than the field holds', implode(' ', CustomerImportRun::outcome($report)['lines']));

        // What says where the customer is cannot be cut: that would point at another OLT.
        $path = $this->csv('Name,SF3,'.str_repeat('O', 121).",FDT 1,1,1,\n");
        $report = app(CustomerImporter::class)->import($path);
        @unlink($path);
        $this->assertSame(['Row 2: the OLT name is longer than 120 characters'], $report['errors']);
    }

    public function test_a_workbook_with_a_large_text_table_is_read_from_memory_not_from_temporary_files(): void
    {
        $factory = new InMemorySharedStrings;
        // OpenSpout itself would move this table to temporary files and reload one for almost every cell.
        $this->assertInstanceOf(InMemoryStrategy::class, $factory->createBestCachingStrategy(280_000, sys_get_temp_dir()));
        $this->assertInstanceOf(FileBasedStrategy::class, $factory->createBestCachingStrategy(null, sys_get_temp_dir()), 'size unknown: the careful way');
        $this->assertInstanceOf(FileBasedStrategy::class, $factory->createBestCachingStrategy(InMemorySharedStrings::CEILING + 1, sys_get_temp_dir()));

        // An .xlsx of a few thousand different names and accounts goes through the importer.
        $path = tempnam(sys_get_temp_dir(), 'cust').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Alias Name', 'ONU Name', 'OLT', 'FDT', 'SLOT', 'PORT', 'Bandwidth']));
        for ($i = 1; $i <= 3000; $i++) {
            $writer->addRow(Row::fromValues(["Customer number {$i}", 'SF'.(7000000 + $i), 'OLT '.($i % 4), 'FDT '.($i % 25), $i % 16 + 1, $i % 16 + 1, '']));
        }
        $writer->close();

        $report = app(CustomerImporter::class)->import($path);
        @unlink($path);

        $this->assertSame([3000, 3000, []], [$report['rows'], $report['created'], $report['errors']]);
        $this->assertSame(3000, Customer::query()->count(), 'written 500 at a time as the file is walked');
        $this->assertSame('Customer number 2999', Customer::query()->where('account_number', 'SF7002999')->value('name'));
    }

    public function test_the_website_runs_the_import_in_the_background_and_tells_the_person_the_outcome(): void
    {
        Storage::fake('local');
        // In a test the deferred work runs at once instead of after the page has answered.
        $this->withoutDefer();
        $manager = $this->makeUser('CX Manager', 'CX');
        $this->actingAs($manager);

        $file = fn (string $rows) => UploadedFile::fake()->createWithContent('customers.csv', self::HEADER.$rows);
        // The notification (bell) with this title; several can be written within the same second.
        $told = fn (string $title) => $manager->notifications()->get()->first(fn ($notification) => $notification->data['title'] === $title)?->data;

        // The page answers straight away; the result comes as a notification.
        Livewire::test(ManageCustomers::class)
            ->callAction(TestAction::make('importCustomers'), ['file' => [$file("Good,SF1,Kireka,FDT 1,1,1,\nNo Fdt,SF2,Kireka,,4,2,\n")], 'dry_run' => 0])
            ->assertHasNoFormErrors()
            ->assertNotified('Import started');

        $this->assertSame(0, Customer::query()->count());
        $this->assertStringContainsString('Row 3: SLOT given without an FDT', $told('Nothing imported — 1 of 2 rows need fixing')['body']);
        $this->assertSame([], Storage::disk('local')->allFiles('customer-imports'), 'the uploaded file is not kept');

        // The same file, keeping the customers whose position is incomplete.
        Livewire::test(ManageCustomers::class)
            ->callAction(TestAction::make('importCustomers'), ['file' => [$file("Good,SF1,Kireka,FDT 1,1,1,\nNo Fdt,SF2,Kireka,,4,2,\n")], 'dry_run' => 0, 'keep_incomplete' => 1])
            ->assertHasNoFormErrors();

        $this->assertSame(2, Customer::query()->count());
        $saved = $told('Customers imported and saved');
        $this->assertStringContainsString('2 rows: 2 new customers', $saved['body']);
        $this->assertStringContainsString('1 customers have an incomplete position', $saved['body']);

        // One import at a time: a second one is turned away while the first is running.
        $running = CustomerImportRun::lock();
        $this->assertNotNull($running);
        Livewire::test(ManageCustomers::class)
            ->callAction(TestAction::make('importCustomers'), ['file' => [$file("Other,SF3,Kireka,FDT 1,1,2,\n")], 'dry_run' => 0])
            ->assertNotified('Another customer import is still running');
        $this->assertFalse(Customer::query()->where('account_number', 'SF3')->exists());
        $running->release();

        // …and the lock is given back when an import ends, whatever its outcome.
        Livewire::test(ManageCustomers::class)
            ->callAction(TestAction::make('importCustomers'), ['file' => [$file("Other,SF3,Kireka,FDT 1,1,2,\n")], 'dry_run' => 1]);
        $this->assertNotNull($told('PREVIEW ONLY — nothing was saved'));
        $this->assertFalse(Customer::query()->where('account_number', 'SF3')->exists());
        $this->assertTrue(Cache::lock(CustomerImportRun::LOCK, 5)->get());
    }

    public function test_a_file_too_large_to_upload_is_imported_from_the_command_line(): void
    {
        $path = $this->csv("Good,SF1,Kireka,FDT 1,1,1,\nNo Fdt,SF2,Kireka,,4,2,\n");

        $this->artisan('oms:import-customers', ['file' => $path])
            ->expectsOutputToContain('Nothing imported — 1 of 2 rows need fixing')
            ->expectsOutputToContain('Row 3: SLOT given without an FDT')
            ->assertFailed();

        $this->artisan('oms:import-customers', ['file' => $path, '--preview' => true, '--keep-incomplete' => true])
            ->expectsOutputToContain('PREVIEW ONLY — nothing was saved')
            ->assertSuccessful();
        $this->assertSame(0, Customer::query()->count());

        $this->artisan('oms:import-customers', ['file' => $path, '--keep-incomplete' => true])
            ->expectsOutputToContain('Customers imported and saved')
            ->expectsOutputToContain('2 rows: 2 new customers')
            ->assertSuccessful();
        $this->assertSame(2, Customer::query()->count());

        $this->artisan('oms:import-customers', ['file' => $path.'.missing'])->expectsOutputToContain('File not found')->assertFailed();
        @unlink($path);
    }
}
