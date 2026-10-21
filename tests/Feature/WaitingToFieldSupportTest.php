<?php

namespace Tests\Feature;

use App\Console\Commands\CloseHistoricalCommand;
use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Models\FinalReason;
use App\Models\OutageQueueEntry;
use App\Models\TicketSubcategory;
use App\Models\VisitType;
use App\Services\Outages\OutageWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * oms:waiting-to-field-support: customer tickets waiting for their site visit past N SLA hours go to CX Field
 * Support; the newer ones keep waiting; a moved ticket still closes when its visit closes.
 */
class WaitingToFieldSupportTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_tickets_waiting_too_long_go_to_cx_field_support_and_still_close_with_their_visit(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');
        $this->seedReference();
        Mail::fake();
        $workflow = app(OutageWorkflow::class);

        $admin = $this->makeUser('Super Admin', 'MGMT', attributes: ['availability_status' => 'offline']);
        $frontline = $this->makeUser('CX Frontline', 'CX');
        $this->makeUser('CX Tier II', 'CX');
        $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        Auth::setUser($frontline);

        // Raised by the admin (override): whoever holds the ticket at CX Tier II does not matter here.
        $waiting = function (string $account) use ($workflow, $frontline, $admin): array {
            $ticket = $workflow->createTicket(['subcategory_id' => TicketSubcategory::query()->where('name', 'LOS Flashing Red')->value('id'),
                'account_number' => $account, 'customer_name' => 'Jane Customer', 'calling_number' => '0772000001', 'description' => 'No light'], $frontline);
            $visit = $workflow->raiseSiteVisit($ticket, $admin, ['visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id')]);

            return [$ticket->refresh(), $visit];
        };

        [$old, $oldVisit] = $waiting('SF1');
        Carbon::setTestNow('2026-10-22 10:00:00');
        [$new] = $waiting('SF2');
        $this->assertSame(StatusCode::TicketSiteVisit->value, $old->status->code);

        // The plan changes nothing.
        $this->artisan('oms:waiting-to-field-support')->expectsOutputToContain('Preview only')->assertSuccessful();
        $this->assertNull($old->refresh()->current_queue);

        $this->artisan('oms:waiting-to-field-support', ['--apply' => true, '--by' => $admin->email])
            ->expectsOutputToContain('Moved 1 customer ticket(s) to CX Field Support')
            ->assertSuccessful();

        // The old one is in the CX Field Support queue; the new one keeps waiting.
        $this->assertSame(QueueType::CxFieldSupport, $old->refresh()->current_queue);
        $this->assertTrue(OutageQueueEntry::query()->where('outage_id', $old->id)->where('queue_type', QueueType::CxFieldSupport->value)->open()->exists());
        $this->assertSame(StatusCode::TicketSiteVisit->value, $new->refresh()->status->code);
        $this->assertNull($new->current_queue);

        // Run again: nothing left to move.
        $this->artisan('oms:waiting-to-field-support', ['--apply' => true])->expectsOutputToContain('Moved 0 customer ticket(s)')->assertSuccessful();

        // Its visit closes: the customer ticket closes with it, out of the CX Field Support queue.
        $reason = FinalReason::query()->create(['name' => CloseHistoricalCommand::REASON, 'is_active' => false, 'sort_order' => 999]);
        $workflow->closeHistorical($oldVisit, $admin, $reason, 'Done');
        $this->assertTrue($old->refresh()->is_closed);
        $this->assertSame(StatusCode::TicketClosed->value, $old->status->code);
        $this->assertFalse(OutageQueueEntry::query()->where('outage_id', $old->id)->open()->exists());
    }
}
