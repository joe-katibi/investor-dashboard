<?php

namespace Tests\Feature;

use App\Models\Outage;
use App\Models\OutageAttachment;
use App\Models\User;
use App\Services\Outages\OutageWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Moving the outages raised on the old server after its database was copied to the new one:
 * export there, import here — the ones already here are left alone, new ones come with everything
 * attached (history, assignments, OLTs, photos), and a ticket number already used here is replaced.
 */
class OutageTransferTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->seedReference();
        Mail::fake();
        Storage::fake('local');
        $this->folder = storage_path('framework/testing/outage-export-'.uniqid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function raise(User $by, string $title, array $olts): Outage
    {
        return $this->reportOutage($by, ['title' => $title, 'olt_ids' => $olts]);
    }

    public function test_only_the_outages_raised_after_the_copy_are_imported_with_everything_attached(): void
    {
        $noc = $this->makeUser('NOC Tier I', 'NOC');
        $olt = $this->makeOlt('SEGUKU');

        // In the database when it was copied to the new server…
        $before = $this->raise($noc, 'Before the copy', [$olt->id]);

        // …and raised on the old server afterwards.
        Carbon::setTestNow('2026-10-15 12:00:00');
        $first = $this->raise($noc, 'Fibre cut on Entebbe road', [$olt->id]);
        app(OutageWorkflow::class)->addUpdate($first, $noc, 'Team on site');
        Storage::disk('local')->put($photo = 'outage-attachments/2026/10/cut.jpg', 'jpeg-bytes');
        OutageAttachment::query()->create(['outage_id' => $first->id, 'path' => $photo, 'original_name' => 'cut.jpg', 'user_id' => $noc->id]);
        $second = $this->raise($noc, 'OLT power failure', [$olt->id]);
        $numbers = [$first->ticket_number, $second->ticket_number];
        $counts = fn (Outage $o) => [
            DB::table('outage_status_histories')->where('outage_id', $o->id)->count(),
            DB::table('outage_updates')->where('outage_id', $o->id)->count(),
            DB::table('outage_queue_entries')->where('outage_id', $o->id)->count(),
            DB::table('outage_assignments')->where('outage_id', $o->id)->count(),
            DB::table('outage_olt')->where('outage_id', $o->id)->pluck('olt_id')->all(),
        ];
        $expected = [$counts($first), $counts($second)];

        // On the old server: the export.
        $this->artisan('oms:export-outages', ['folder' => $this->folder, '--days' => 3])->assertSuccessful();
        $this->assertFileExists($this->folder.'/data.json');
        $this->assertFileExists($this->folder.'/files/'.$photo);

        // The new server: it has the copy (the outage from before), not the two raised later, and has raised one of its own
        // since — which took the number the first one has on the old server.
        Outage::query()->whereIn('id', [$first->id, $second->id])->get()->each->delete();
        Storage::disk('local')->delete($photo);
        DB::table('ticket_sequences')->update(['last_number' => DB::raw('last_number - 2')]);
        Carbon::setTestNow('2026-10-15 15:00:00');
        $own = $this->raise($noc, 'Raised on the new server', [$olt->id]);
        $this->assertSame($first->ticket_number, $own->ticket_number);

        // The plan: nothing changes.
        $this->artisan('oms:import-outages', ['folder' => $this->folder])->expectsOutputToContain('Preview only')->assertSuccessful();
        $this->assertSame(2, Outage::query()->count());

        $this->artisan('oms:import-outages', ['folder' => $this->folder, '--apply' => true])->assertSuccessful();

        $importedFirst = Outage::query()->where('title', 'Fibre cut on Entebbe road')->sole();
        $importedSecond = Outage::query()->where('title', 'OLT power failure')->sole();
        $this->assertNotSame($first->ticket_number, $importedFirst->ticket_number, 'taken here by another outage: renumbered');
        $this->assertSame($numbers[1], $importedSecond->ticket_number, 'free here: kept');
        $this->assertSame([$expected[0], $expected[1]], [$counts($importedFirst), $counts($importedSecond)], 'history, updates, queue, assignments and OLTs came along');
        $this->assertTrue(Storage::disk('local')->exists($photo), 'the photo too');
        $this->assertSame($photo, OutageAttachment::query()->where('outage_id', $importedFirst->id)->value('path'));
        $this->assertSame('Before the copy', Outage::query()->whereKey($before->id)->value('title'));
        $this->assertSame(4, Outage::query()->count(), 'the outage from before the copy is not duplicated');

        // The next outage here does not reuse an imported number.
        $next = $this->raise($noc, 'Next one', [$olt->id]);
        $this->assertNotContains($next->ticket_number, Outage::query()->whereKeyNot($next->id)->pluck('ticket_number')->all());

        // Run again: nothing new.
        $this->artisan('oms:import-outages', ['folder' => $this->folder])->expectsOutputToContain('Nothing new to import')->assertSuccessful();
    }

    public function test_a_user_missing_here_is_named_and_nothing_is_imported(): void
    {
        $noc = $this->makeUser('NOC Tier I', 'NOC', attributes: ['email' => 'late.joiner@savannafibre.co.ug']);
        $outage = $this->raise($noc, 'Raised by someone the new server does not know', [$this->makeOlt()->id]);

        $this->artisan('oms:export-outages', ['folder' => $this->folder])->assertSuccessful();
        // The new server: the outage is not there, and nobody has that e-mail address.
        $outage->delete();
        DB::table('users')->where('id', $noc->id)->update(['email' => 'someone.else@savannafibre.co.ug']);

        $this->artisan('oms:import-outages', ['folder' => $this->folder, '--apply' => true])
            ->expectsOutputToContain('late.joiner@savannafibre.co.ug')->assertFailed();
        $this->assertSame(0, Outage::query()->count());
    }
}
