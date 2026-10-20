<?php

namespace Tests\Feature;

use App\Console\Commands\CloseHistoricalCommand;
use App\Enums\StatusCode;
use App\Models\NotificationLog;
use App\Models\Outage;
use App\Models\OutageAssignment;
use App\Models\OutageQueueEntry;
use App\Models\Ticket;
use App\Models\TicketSubcategory;
use App\Models\VisitType;
use App\Services\Outages\OutageWorkflow;
use App\Services\Tickets\TicketFollowUpMonitor;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * oms:close-historical: old customer tickets and site visits from a list are closed properly (queues, assignments,
 * SLA), silently — no SMS, e-mail or app notification — and the visits still get their 72-hour follow-up call.
 */
class CloseHistoricalTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_old_tickets_and_visits_are_closed_silently_and_visits_are_called_after_72_hours(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');
        $this->seedReference();
        Mail::fake();
        app(Settings::class)->set(['ticketing.sms_customer' => true]);
        $workflow = app(OutageWorkflow::class);

        // Offline: holding every permission, they would otherwise be given the tickets below.
        $admin = $this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline']);
        $frontline = $this->makeUser('CX Frontline', 'CX');
        $tier2 = $this->makeUser('CX Tier II', 'CX');
        $dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $technician = $this->makeUser('Site Visit Technician', 'DISPATCH', attributes: ['phone' => '0700555666']);
        $this->makeUser('CX Follow-up – Site Visit Agent', 'CX');
        Auth::setUser($frontline);

        $raise = fn (string $account) => $workflow->createTicket(['subcategory_id' => TicketSubcategory::query()->where('name', 'LOS Flashing Red')->value('id'),
            'account_number' => $account, 'customer_name' => 'Jane Customer', 'calling_number' => '0772000001', 'description' => 'No light'], $frontline);

        // A customer ticket waiting for its site visit, which is with a technician; and a ticket on its own.
        $parent = $raise('SF1');
        $visit = $workflow->raiseSiteVisit($parent, $tier2, ['visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id')]);
        $visit = $workflow->assignVisit($visit, $dispatcher, ['technician_id' => $technician->id]);
        $alone = $raise('SF2');
        $outage = $this->reportOutage($this->makeUser('CX Agent', 'CX'));

        $file = tempnam(sys_get_temp_dir(), 'close').'.csv';
        file_put_contents($file, "Ticket No,Kind\n{$visit->ticket_number},Site visit\n{$parent->ticket_number},Customer ticket\n{$alone->ticket_number},Customer ticket\n{$outage->ticket_number},Outage\nTKT-999999,Customer ticket\n");

        // The plan changes nothing.
        $this->artisan('oms:close-historical', ['file' => $file])->expectsOutputToContain('Preview only')->assertSuccessful();
        $this->assertFalse($visit->refresh()->is_closed);

        $logs = NotificationLog::query()->count();
        $inApp = DB::table('notifications')->count();

        $this->artisan('oms:close-historical', ['file' => $file, '--apply' => true, '--by' => $admin->email])
            ->expectsOutputToContain('Closed 1 site visit(s), 1 customer ticket(s) with their visit and 1 more customer ticket(s)')
            ->assertSuccessful();

        // Closed properly, with the clean-up reason; the outage in the list is left alone.
        $this->assertSame(StatusCode::VisitClosed->value, $visit->refresh()->status->code);
        $this->assertSame(StatusCode::TicketClosed->value, $parent->refresh()->status->code);
        $this->assertTrue($alone->refresh()->is_closed);
        $this->assertFalse($outage->refresh()->is_closed);
        $this->assertSame(CloseHistoricalCommand::REASON, Ticket::query()->where('outage_id', $visit->id)->first()->finalReason?->name);
        $ids = [$visit->id, $parent->id, $alone->id];
        $this->assertSame(0, OutageAssignment::query()->whereIn('outage_id', $ids)->active()->count(), 'out of everyone’s My Work');
        $this->assertSame(0, OutageQueueEntry::query()->whereIn('outage_id', $ids)->open()->count(), 'out of every queue');

        // Silently: no SMS to the customer, no e-mail, no app notification.
        $this->assertSame($logs, NotificationLog::query()->count());
        $this->assertSame($inApp, DB::table('notifications')->count());

        // The 72-hour follow-up call still comes due for the visit.
        Carbon::setTestNow(now()->addHours(71));
        app(TicketFollowUpMonitor::class)->tick();
        $this->assertNull(Ticket::query()->where('outage_id', $visit->id)->value('aftercare_due_at'));
        Carbon::setTestNow(now()->addHours(2));
        app(TicketFollowUpMonitor::class)->tick();
        $this->assertNotNull(Ticket::query()->where('outage_id', $visit->id)->value('aftercare_due_at'));
    }
}
