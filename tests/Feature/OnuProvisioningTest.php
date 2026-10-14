<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Filament\Resources\SiteVisits\Pages\ViewSiteVisit;
use App\Models\FinalReason;
use App\Models\NotificationLog;
use App\Models\Outage;
use App\Models\Ticket;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Outages\OutageWorkflow;
use App\Support\VisitPhotos;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * A new ONU on site — Faulty ONU, ONU Upgrade, ONU Delivery, ONU Swap, or "ONU replaced" on any
 * support visit: the technician sends the visit to the FTTH NOC with a photo of the ONU MAC address,
 * the NOC provisions it and sends it back to the same technician, who then takes the speed test and
 * the smart-device photos; dispatcher verifies, CX Field Support confirms. "Existing ONU": as before.
 */
class OnuProvisioningTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    private User $dispatcher;

    private User $technician;

    private User $noc;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->seedReference();
        Mail::fake();
        Storage::fake('local');
        $this->workflow = app(OutageWorkflow::class);
        $this->dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $this->technician = $this->makeUser('Site Visit Technician', 'DISPATCH', attributes: ['phone' => '0700555666']);
        $this->noc = $this->makeUser('NOC Tier II', 'NOC');
        Auth::setUser($this->dispatcher);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function photo(string $name): string
    {
        Storage::disk('local')->put($path = 'outage-attachments/2026/10/'.$name, 'image');

        return $path;
    }

    /** A support visit for the issue, assigned to the technician, who is on site. */
    private function onSite(string $issue): Outage
    {
        $visit = $this->workflow->createSiteVisit([
            'visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id'),
            'subcategory_id' => TicketSubcategory::query()->where('name', $issue)->value('id'),
            'account_number' => 'SF2659301', 'customer_name' => 'John Customer', 'calling_number' => '0772000010', 'address' => 'Lukuli',
        ], $this->dispatcher);
        $visit = $this->workflow->assignVisit($visit, $this->dispatcher, ['technician_id' => $this->technician->id]);

        return $this->workflow->visitArrive($visit, $this->technician);
    }

    private function reason(string $name): int
    {
        return FinalReason::query()->where('name', $name)->value('id');
    }

    private function details(Outage $outage): Ticket
    {
        return Ticket::query()->where('outage_id', $outage->id)->firstOrFail();
    }

    public function test_an_onu_issue_is_provisioned_by_the_noc_from_the_mac_photo_before_the_speed_test(): void
    {
        $this->assertSame(['Faulty ONU', 'ONU Upgrade', 'ONU Swap', 'Onu Delivery'],
            TicketSubcategory::query()->where('needs_onu_provisioning', true)->orderBy('sort_order')->pluck('name')->all());

        $fieldSupport = $this->makeUser('CX Field Support', 'CX');
        $visit = $this->onSite('Faulty ONU');
        $texts = NotificationLog::query()->where('outage_id', $visit->id)->where('event', 'ticket_customer_sms')->count();

        $this->assertTrue(VisitPhotos::onuChange($visit));
        $this->assertSame(['geo_photo', 'onu_mac', 'speed_test', 'devices_ok'], array_keys(VisitPhotos::of($visit)));
        $this->assertSame(['speed_test', 'devices_ok'], VisitPhotos::locked($visit));

        // The MAC address photo is required to ask for provisioning.
        try {
            $this->workflow->requestProvisioning($visit, $this->technician, 'New ONU');
            $this->fail('Sent to the NOC without the MAC address photo.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('onu_mac', $e->errors());
        }

        $visit = $this->workflow->requestProvisioning($visit, $this->technician, 'New ONU HWTC5678', ['onu_mac' => $this->photo('mac.jpg'), 'onu_mac_name' => 'mac.jpg']);
        $this->assertSame(QueueType::TicketNoc, $visit->current_queue);
        $this->assertNotNull(VisitPhotos::taken($visit)['onu_mac']);

        $visit = $this->workflow->confirmProvisioning($visit, $this->noc, 'ONU provisioned');
        $this->assertSame($this->technician->id, $visit->current_assignee_id, 'back to the same technician');
        $this->assertSame([], VisitPhotos::locked($visit));
        $this->assertSame($texts, NotificationLog::query()->where('outage_id', $visit->id)->where('event', 'ticket_customer_sms')->count());

        // The smart devices are needed too.
        try {
            $this->workflow->completeVisit($visit, $this->technician, ['final_reason_id' => $this->reason('ONU not powering - Replaced'), 'resolution_notes' => 'Swapped',
                'fat_number' => 'FAT-1', 'onu_status' => 'replaced', 'geo_photo' => $this->photo('geo.jpg'), 'speed_photo' => $this->photo('speed.jpg')]);
            $this->fail('Completed without the smart-device photo.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('devices_ok', $e->errors());
        }

        $visit = $this->workflow->completeVisit($visit, $this->technician, ['final_reason_id' => $this->reason('ONU not powering - Replaced'), 'resolution_notes' => 'Swapped',
            'fat_number' => 'FAT-1', 'onu_status' => 'replaced', 'geo_photo' => $this->photo('geo.jpg'), 'speed_photo' => $this->photo('speed.jpg'), 'devices_ok' => $this->photo('tv.jpg')]);
        $this->assertSame('replaced', $this->details($visit)->onu_status);
        $visit = $this->workflow->verifyVisit($visit, $this->dispatcher, 'Online');
        $this->assertSame($fieldSupport->id, $visit->current_assignee_id);
        $visit = $this->workflow->confirmVisit($visit, $fieldSupport, ['outcome' => 'confirmed', 'notes' => 'OK']);
        $this->assertSame(StatusCode::VisitClosed->value, $visit->status->code, 'the NOC step is not an escalation');
    }

    public function test_a_support_visit_with_the_existing_onu_completes_as_before(): void
    {
        $visit = $this->onSite('LOS Flashing Red');

        $this->assertFalse(VisitPhotos::onuChange($visit));
        $this->assertSame(['geo_photo', 'speed_test'], array_keys(VisitPhotos::of($visit)));

        $visit = $this->workflow->completeVisit($visit, $this->technician, ['final_reason_id' => $this->reason('ONU reset / configured'), 'resolution_notes' => 'Reset',
            'fat_number' => 'FAT-1', 'onu_status' => 'existing', 'geo_photo' => $this->photo('geo.jpg'), 'speed_photo' => $this->photo('speed.jpg')]);
        $this->assertSame(QueueType::VisitDispatch, $visit->current_queue);
        $this->assertSame('existing', $this->details($visit)->onu_status);
    }

    public function test_onu_replaced_on_a_support_visit_takes_the_noc_flow(): void
    {
        $visit = $this->onSite('LOS Flashing Red');

        // "ONU replaced" is only accepted once the NOC has provisioned the new ONU.
        try {
            $this->workflow->completeVisit($visit, $this->technician, ['final_reason_id' => $this->reason('ONU not powering - Replaced'), 'resolution_notes' => 'Swapped',
                'fat_number' => 'FAT-1', 'onu_status' => 'replaced', 'geo_photo' => $this->photo('geo.jpg'), 'speed_photo' => $this->photo('speed.jpg')]);
            $this->fail('An ONU replaced without provisioning.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('onu_status', $e->errors());
        }

        $visit = $this->workflow->requestProvisioning($visit, $this->technician, 'ONU swapped', ['onu_mac' => $this->photo('mac.jpg')]);
        $this->assertSame('replaced', $this->details($visit)->onu_status);
        $visit = $this->workflow->confirmProvisioning($visit, $this->noc);
        $this->assertTrue(VisitPhotos::onuChange($visit));
        $this->assertSame($this->technician->id, $visit->current_assignee_id);

        $visit = $this->workflow->completeVisit($visit, $this->technician, ['final_reason_id' => $this->reason('ONU not powering - Replaced'), 'resolution_notes' => 'Swapped',
            'fat_number' => 'FAT-1', 'onu_status' => 'replaced', 'geo_photo' => $this->photo('geo.jpg'), 'speed_photo' => $this->photo('speed.jpg'), 'devices_ok' => $this->photo('tv.jpg')]);
        $this->assertSame(QueueType::VisitDispatch, $visit->current_queue);
    }

    public function test_the_buttons_on_the_visit_page(): void
    {
        $visit = $this->onSite('LOS Flashing Red');

        $this->actingAs($this->technician);
        Livewire::test(ViewSiteVisit::class, ['record' => $visit->id])
            ->assertActionVisible(TestAction::make('visitProvisionRequest'))
            ->assertActionHasLabel(TestAction::make('visitProvisionRequest'), 'ONU replaced — send to NOC for provisioning')
            ->callAction(TestAction::make('visitProvisionRequest'), ['onu_mac' => UploadedFile::fake()->image('mac.jpg'), 'note' => 'ONU swapped'])
            ->assertHasNoActionErrors();

        $this->assertSame(QueueType::TicketNoc, $visit->refresh()->current_queue);
        $this->assertNotNull(VisitPhotos::taken($visit)['onu_mac']);
    }
}
