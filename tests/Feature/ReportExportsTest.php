<?php

namespace Tests\Feature;

use App\Filament\Pages\ReportDownloads;
use App\Filament\Pages\Reports;
use App\Jobs\GenerateReportExportJob;
use App\Models\AuditLog;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Report exports are prepared in the background and collected from Report Downloads: the page that
 * asks for one never waits for the file, the file is kept six hours, and only the person who asked
 * (or someone who reads the audit trail and may run that report) can download it.
 */
class ReportExportsTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
        Storage::fake(ReportExport::DISK);
    }

    private function export(User $user, string $format = 'csv', string $report = 'outage_register'): ReportExport
    {
        $this->actingAs($user);

        Livewire::test(Reports::class)
            ->set('data.report', $report)
            ->set('data.from', '2026-10-01')
            ->set('data.to', '2026-10-05')
            ->call('export', $format)
            ->assertNotified('Your report is being prepared');

        return ReportExport::query()->latest('id')->firstOrFail();
    }

    public function test_an_export_is_only_queued_by_the_page_and_written_by_a_worker(): void
    {
        Queue::fake();
        $manager = $this->makeUser('NOC Manager', 'NOC', attributes: ['availability_status' => 'offline']);

        $export = $this->export($manager, 'xlsx');

        // The page did no more than note the request and hand it to the queue.
        Queue::assertPushed(GenerateReportExportJob::class, fn (GenerateReportExportJob $job) => $job->exportId === $export->id);
        $this->assertSame([ReportExport::QUEUED, null, 'outage_register', 'Outage Register', 'xlsx', '2026-10-01', '2026-10-05'], [
            $export->status, $export->path, $export->report_key, $export->title, $export->format, $export->date_from->toDateString(), $export->date_to->toDateString(),
        ]);
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-05'], $export->filters);
        $this->assertSame([], Storage::disk(ReportExport::DISK)->files(ReportExport::DIRECTORY));

        // The list shows it straight away: period, report, who, when — and nothing to download yet.
        Livewire::test(ReportDownloads::class)
            ->assertCanSeeTableRecords([$export])
            ->assertSee('01 Oct 2026 – 05 Oct 2026')->assertSee('Outage Register')->assertSee($manager->name)
            ->assertSee('Excel · Waiting to be prepared')->assertSee('Preparing…');
        $this->get(route('report-exports.download', $export))->assertStatus(410);

        // The worker writes the file; the person is told in the bell.
        (new GenerateReportExportJob($export->id))->handle(app(\App\Services\Reports\ReportExporter::class), app(\App\Services\Reports\ReportRegistry::class));
        $export->refresh();
        $this->assertSame(ReportExport::READY, $export->status);
        $this->assertGreaterThan(1000, $export->size_bytes);
        $this->assertStringEndsWith('.xlsx', $export->file_name);
        Storage::disk(ReportExport::DISK)->assertExists($export->path);
        $this->assertEqualsWithDelta(now()->addHours(6)->getTimestamp(), $export->expires_at->getTimestamp(), 5);
        $this->assertSame('Your report is ready', $manager->notifications()->latest()->first()->data['title']);
        $this->assertSame($manager->id, auth()->id(), 'the worker leaves the session as it found it');

        // A second worker handed the same job does not write it again.
        $path = $export->path;
        (new GenerateReportExportJob($export->id))->handle(app(\App\Services\Reports\ReportExporter::class), app(\App\Services\Reports\ReportRegistry::class));
        $this->assertSame($path, $export->refresh()->path);
        $this->assertCount(1, Storage::disk(ReportExport::DISK)->files(ReportExport::DIRECTORY));

        Livewire::test(ReportDownloads::class)->assertSee('Excel · Ready — kept until '.$export->expires_at->format('H:i'))->assertSee('Download');
    }

    public function test_every_format_is_prepared_and_downloaded_by_the_right_people_only(): void
    {
        $manager = $this->makeUser('NOC Manager', 'NOC', attributes: ['availability_status' => 'offline']);
        $other = $this->makeUser('NOC Tier I', 'NOC', attributes: ['availability_status' => 'offline']);
        $other->givePermissionTo(['reports.view', 'reports.export']);
        $this->assertFalse($other->can('audit.view'));

        // The queue runs in line in the tests: by the time the page answers, the files are there.
        $csv = $this->export($manager, 'csv');
        $pdf = $this->export($manager, 'pdf');
        $theirs = $this->export($other, 'csv');

        foreach ([$csv, $pdf, $theirs] as $export) {
            $this->assertSame(ReportExport::READY, $export->status, $export->format);
            Storage::disk(ReportExport::DISK)->assertExists($export->path);
        }
        $this->assertStringStartsWith("\xEF\xBB\xBF", Storage::disk(ReportExport::DISK)->get($csv->path));
        $this->assertStringStartsWith('%PDF', Storage::disk(ReportExport::DISK)->get($pdf->path));

        // Each person sees and downloads their own…
        $this->actingAs($other);
        Livewire::test(ReportDownloads::class)->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$csv, $pdf])
            // The button hands over the file itself (a link would be opened by the panel as a page).
            ->assertTableActionEnabled('download', $theirs)
            ->callTableAction('download', $theirs)
            ->assertFileDownloaded($theirs->file_name);
        $this->get(route('report-exports.download', $theirs))->assertOk()->assertDownload($theirs->file_name);
        $this->get(route('report-exports.download', $csv))->assertNotFound();

        // …someone who reads the audit trail sees who pulled what, and may take a report they could run themselves.
        $this->actingAs($manager);
        Livewire::test(ReportDownloads::class)->assertCanSeeTableRecords([$csv, $pdf, $theirs]);
        $this->get(route('report-exports.download', $theirs))->assertOk();
        $downloads = AuditLog::query()->where('event', 'report_downloaded')->orderBy('id')->get();
        $this->assertSame([[$other->id, null], [$other->id, null], [$manager->id, $other->name]], $downloads->map(fn (AuditLog $log) => [$log->user_id, $log->new_values['pulled_by'] ?? null])->all());
        $this->assertSame('Report downloaded', $downloads->first()->eventLabel());

        // Not signed in, or not allowed to export: no file.
        auth()->logout();
        $this->get(route('report-exports.download', $csv))->assertRedirect();
        $this->actingAs($this->makeUser('NOC Tier I', 'NOC', attributes: ['availability_status' => 'offline']));
        $this->get(route('report-exports.download', $csv))->assertForbidden();
    }

    public function test_a_file_is_removed_six_hours_after_it_was_prepared(): void
    {
        $manager = $this->makeUser('NOC Manager', 'NOC', attributes: ['availability_status' => 'offline']);
        $export = $this->export($manager);
        $path = $export->path;

        $this->travel(5)->hours();
        $this->artisan('oms:prune-report-exports')->assertSuccessful();
        Storage::disk(ReportExport::DISK)->assertExists($path);
        $this->get(route('report-exports.download', $export))->assertOk();

        $this->travel(61)->minutes();
        // Past its time it is refused even before the clean-up has run…
        $this->get(route('report-exports.download', $export))->assertStatus(410);
        Livewire::test(ReportDownloads::class)->assertCanNotSeeTableRecords([$export]);

        // …and the clean-up removes the file and the row.
        $this->artisan('oms:prune-report-exports')->assertSuccessful();
        Storage::disk(ReportExport::DISK)->assertMissing($path);
        $this->assertSame(0, ReportExport::query()->count());
        $this->get(route('report-exports.download', $export->id))->assertNotFound();
    }

    public function test_an_export_that_cannot_be_prepared_says_so(): void
    {
        Queue::fake();
        $manager = $this->makeUser('NOC Manager', 'NOC', attributes: ['availability_status' => 'offline']);
        $export = $this->export($manager);
        $run = fn () => (new GenerateReportExportJob($export->id))->handle(app(\App\Services\Reports\ReportExporter::class), app(\App\Services\Reports\ReportRegistry::class));

        // The account was closed between asking and preparing: nothing is written.
        $manager->forceFill(['is_active' => false])->save();
        $run();
        $export->refresh();
        $this->assertSame([ReportExport::FAILED, 'You are no longer allowed to export this report.', null], [$export->status, $export->error, $export->path]);
        $this->assertSame([], Storage::disk(ReportExport::DISK)->files(ReportExport::DIRECTORY));

        // A worker that died half-way: the clean-up closes the export instead of leaving "being prepared" for ever.
        $manager->forceFill(['is_active' => true])->save();
        $stuck = ReportExport::query()->create([
            'user_id' => $manager->id, 'report_key' => 'outage_register', 'title' => 'Outage Register', 'format' => 'csv',
            'status' => ReportExport::RUNNING, 'started_at' => now(), 'expires_at' => now()->addHours(6),
        ]);
        $this->travel(ReportExport::STUCK_MINUTES + 1)->minutes();
        $this->artisan('oms:prune-report-exports')->assertSuccessful();
        $this->assertSame(ReportExport::FAILED, $stuck->refresh()->status);

        $this->actingAs($manager);
        Livewire::test(ReportDownloads::class)->assertSee('Could not be prepared')->assertSee('Not available');
    }
}
