<?php

namespace Tests\Feature;

use App\Enums\AvailabilityStatus;
use App\Filament\Pages\AvailabilityTime;
use App\Filament\Pages\Reports;
use App\Models\User;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Reports\AvailabilityTimeReport;
use App\Services\Reports\ReportRegistry;
use App\Support\Settings;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * How long people were Available, Busy, On break and Offline — day by day, week by week, month by
 * month — from the changes of availability they (or a manager) made.
 */
class AvailabilityTimeTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        app(Settings::class)->set(['fifo.respect_shift' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Change someone's availability at a given moment, the way the website and the app do. */
    private function at(string $moment, User $user, AvailabilityStatus $status): void
    {
        Carbon::setTestNow($moment);
        app(FifoAssignmentEngine::class)->setAvailability($user->refresh(), $status, $user);
    }

    /** Two working days for Tess; one stretch for Tom. */
    private function history(): array
    {
        Carbon::setTestNow('2026-10-01 07:00:00');
        $tess = $this->makeUser('CX Tier II', 'CX', attributes: ['name' => 'Tess Tier', 'availability_status' => 'offline', 'availability_changed_at' => null]);
        $tom = $this->makeUser('CX Tier II', 'CX', attributes: ['name' => 'Tom Tier', 'availability_status' => 'offline', 'availability_changed_at' => null]);

        // Monday 12 Oct: available 08–12, on break 12–13, available 13–15, busy 15–17, offline from 17.
        $this->at('2026-10-12 08:00:00', $tess, AvailabilityStatus::Available);
        $this->at('2026-10-12 12:00:00', $tess, AvailabilityStatus::OnBreak);
        $this->at('2026-10-12 13:00:00', $tess, AvailabilityStatus::Available);
        $this->at('2026-10-12 15:00:00', $tess, AvailabilityStatus::Busy);
        $this->at('2026-10-12 17:00:00', $tess, AvailabilityStatus::Offline);
        // Tuesday 13 Oct: available from 09:00 until "now" (Tuesday 11:30).
        $this->at('2026-10-13 09:00:00', $tess, AvailabilityStatus::Available);
        $this->at('2026-10-13 10:00:00', $tom, AvailabilityStatus::Available);
        Carbon::setTestNow('2026-10-13 11:30:00');

        return [$tess, $tom];
    }

    public function test_time_is_counted_per_day_from_the_changes_of_availability(): void
    {
        [$tess] = $this->history();

        $rows = app(AvailabilityTimeReport::class)->rows(Carbon::parse('2026-10-12')->toImmutable(), Carbon::parse('2026-10-13 23:59:59')->toImmutable(), 'day', [$tess->id])
            ->keyBy('start');

        $hours = fn (array $row) => array_map(fn (int $seconds) => round($seconds / 3600, 2), array_intersect_key($row, array_flip(['available', 'busy', 'on_break', 'offline', 'unrecorded'])));

        // Monday: before 08:00 nothing was recorded yet; 6 h available, 2 h busy, 1 h break, offline 17:00–24:00.
        $this->assertSame(['available' => 6.0, 'busy' => 2.0, 'on_break' => 1.0, 'offline' => 7.0, 'unrecorded' => 8.0], $hours($rows['2026-10-12']));
        // Tuesday, up to now (11:30): offline until 09:00, then available. Nothing counted after now.
        $this->assertSame(['available' => 2.5, 'busy' => 0.0, 'on_break' => 0.0, 'offline' => 9.0, 'unrecorded' => 0.0], $hours($rows['2026-10-13']));
        $this->assertSame('Mon 12 Oct 2026', $rows['2026-10-12']['period']);
    }

    public function test_weeks_and_months_add_the_days_up_and_the_report_says_it_in_hours_and_minutes(): void
    {
        [$tess, $tom] = $this->history();
        $report = app(AvailabilityTimeReport::class);

        $week = collect($report->run(['group' => 'week', 'from' => '2026-10-12', 'to' => '2026-10-18'])->rowsArray())->keyBy('person');
        $this->assertSame(['Tess Tier', 'Tom Tier'], $week->keys()->all());
        $this->assertSame([
            'period' => 'Week 42 · 12 Oct – 18 Oct 2026', 'person' => 'Tess Tier', 'available' => '08:30', 'busy' => '02:00', 'on_break' => '01:00',
            'offline' => '16:00', 'available_share' => '31%', 'unrecorded' => '08:00',
        ], $week['Tess Tier']);
        $this->assertSame(['01:30', '00:00', '100%'], [$week['Tom Tier']['available'], $week['Tom Tier']['offline'], $week['Tom Tier']['available_share']]);

        $month = $report->run(['group' => 'month', 'from' => '2026-10-01', 'to' => '2026-10-31', 'user_ids' => [$tess->id]]);
        $this->assertSame([['October 2026', '08:30', '16:00']], array_map(fn (array $row) => [$row['period'], $row['available'], $row['offline']], $month->rowsArray()));
        $this->assertSame(['People' => 1, 'Available' => '08:30', 'Busy' => '02:00', 'On break' => '01:00', 'Offline' => '16:00'], $month->summary);
        $this->assertSame(['Period', 'Person', 'Available', 'Busy', 'On break', 'Offline', 'Available %', 'Not recorded'], array_values($month->columns));

        // A period with nothing recorded for anyone: no rows.
        $this->assertSame([], $report->run(['group' => 'day', 'from' => '2026-09-01', 'to' => '2026-09-30'])->rowsArray());
    }

    public function test_each_person_sees_their_own_time_and_whoever_reads_reports_sees_everyone(): void
    {
        [$tess, $tom] = $this->history();

        // Tess (no reports): her own time, whatever she asks for.
        $this->actingAs($tess->refresh());
        $this->assertFalse($tess->can('reports.view'));
        Livewire::test(AvailabilityTime::class)
            ->assertSee('How long you were available, busy, on break and offline.')
            ->set('data.from', '2026-10-12')->set('data.to', '2026-10-13')
            ->set('data.user_ids', [$tom->id])
            ->assertSeeHtml('<td>Tess Tier</td>')->assertDontSeeHtml('<td>Tom Tier</td>')
            ->assertSee('Mon 12 Oct 2026')
            ->assertActionHidden('export');

        // A manager: everyone, a choice of people, and the export.
        $manager = $this->makeUser('CX Manager', 'CX', attributes: ['availability_status' => 'offline']);
        $manager->givePermissionTo(['reports.view', 'reports.export']);
        $this->actingAs($manager);
        Livewire::test(AvailabilityTime::class)
            ->set('data.from', '2026-10-12')->set('data.to', '2026-10-13')
            ->assertSee('Tess Tier')->assertSee('Tom Tier')
            ->set('data.group', 'week')->assertSee('Week 42')
            ->set('data.user_ids', [$tom->id])->assertSeeHtml('<td>Tom Tier</td>')->assertDontSeeHtml('<td>Tess Tier</td>')
            ->assertActionVisible('export')
            ->assertActionHasUrl('export', Reports::getUrl(['report' => AvailabilityTimeReport::KEY]));

        // On Reports too — opened on it from the link, with its own "group by" choices and the people.
        $this->assertArrayHasKey(AvailabilityTimeReport::KEY, ReportRegistry::availableTo($manager));
        Livewire::withQueryParams(['report' => AvailabilityTimeReport::KEY])->test(Reports::class)
            ->assertSet('data.report', AvailabilityTimeReport::KEY)
            ->assertFormFieldExists('group', fn (Select $field) => array_keys($field->getOptions()) === ['day', 'week', 'month'])
            ->assertFormFieldIsVisible('user_ids')
            ->set('data.from', '2026-10-12')->set('data.to', '2026-10-13')
            ->assertSee('Tess Tier');
    }

    public function test_the_report_list_can_be_searched(): void
    {
        $this->actingAs($this->makeUser('NOC Manager', 'NOC', attributes: ['availability_status' => 'offline']));

        Livewire::test(Reports::class)
            ->assertFormFieldExists('report', fn (Select $field) => $field->isSearchable() && ! $field->isNative() && count($field->getOptions()) > 20)
            ->assertSee('Type part of the report name');
    }
}
