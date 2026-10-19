<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Filament\Resources\SiteVisits\Pages\ViewSiteVisit;
use App\Models\FinalReason;
use App\Models\NotificationLog;
use App\Models\Outage;
use App\Models\Region;
use App\Models\Ticket;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Outages\OutageWorkflow;
use App\Support\VisitPhotos;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Shifting / relocation: the technician sends the visit to the FTTH NOC for provisioning before the
 * speed tests and the smart-device photos, the NOC sends it back to the same technician, and the
 * existing flow continues; and a relocation to another OLT / region goes to a dispatcher there.
 */
class ShiftingRelocationTest extends TestCase
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
        $this->dispatcher = $this->makeUser('Shifting / Relocation Dispatcher', 'DISPATCH');
        $this->technician = $this->makeUser('Shifting / Relocation Technician', 'DISPATCH', attributes: ['phone' => '0700555666']);
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

    private function shifting(array $overrides = []): Outage
    {
        return $this->workflow->createSiteVisit($overrides + [
            'visit_type_id' => VisitType::query()->where('code_prefix', 'SHI')->value('id'),
            'subcategory_id' => TicketSubcategory::query()->where('name', 'Shifting')->value('id'),
            'account_number' => 'SF2659301', 'customer_name' => 'John Customer', 'calling_number' => '0772000010', 'address' => 'Old house, Lukuli',
        ], $this->dispatcher);
    }

    private function details(Outage $outage): Ticket
    {
        return Ticket::query()->where('outage_id', $outage->id)->firstOrFail();
    }

    /** The seven photos taken before provisioning. */
    private function photosBeforeProvisioning(): array
    {
        return collect(['fat_outside', 'fat_inside', 'fat_power', 'cable_entry', 'atb_onu', 'atb_power', 'onu_serial'])
            ->mapWithKeys(fn (string $kind) => [$kind => $this->photo("{$kind}.jpg")])->all();
    }

    public function test_the_noc_provisions_before_the_speed_tests_and_the_visit_goes_back_to_the_same_technician(): void
    {
        $fieldSupport = $this->makeUser('CX Field Support', 'CX');
        $visit = $this->workflow->assignVisit($this->shifting(), $this->dispatcher, ['technician_id' => $this->technician->id]);
        $texts = fn () => NotificationLog::query()->where('outage_id', $visit->id)->where('event', 'ticket_customer_sms')->count();
        $textsWhenAssigned = $texts();

        // Not before the technician is on site.
        $this->assertFalse($this->technician->can('visitProvisionRequest', $visit));
        $visit = $this->workflow->visitArrive($visit, $this->technician);
        $this->assertTrue($this->technician->can('visitProvisionRequest', $visit));

        // The speed tests and the smart-device photo wait for the NOC.
        $this->assertSame(['speed_test', 'devices_ok'], VisitPhotos::locked($visit));
        $this->workflow->addVisitPhotos($visit, $this->technician, $this->photosBeforeProvisioning());
        try {
            $this->workflow->addVisitPhotos($visit, $this->technician, ['speed_photo' => [$this->photo('speed.jpg')]]);
            $this->fail('A speed test was taken before provisioning.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('speed_photo', $e->errors());
        }
        try {
            $this->workflow->completeVisit($visit, $this->technician, ['final_reason_id' => FinalReason::query()->where('name', 'Shifting done successfully')->value('id'),
                'resolution_notes' => 'Moved', 'fat_number' => 'FAT-9']);
            $this->fail('Completed before provisioning.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('provisioned', $e->errors()['speed_photo'][0]);
        }

        // To the FTTH NOC…
        $visit = $this->workflow->requestProvisioning($visit, $this->technician, 'ONU HWTC1234 on the new port');
        $this->assertSame(QueueType::TicketNoc, $visit->current_queue);
        $this->assertSame($this->noc->id, $visit->current_assignee_id);
        $this->assertFalse($this->technician->can('visitComplete', $visit));

        // …and back to the same technician once provisioned. The customer is not texted again.
        $visit = $this->workflow->confirmProvisioning($visit, $this->noc, 'Provisioned on OLT-B 0/1/3');
        $this->assertSame(QueueType::VisitTechnician, $visit->current_queue);
        $this->assertSame($this->technician->id, $visit->current_assignee_id);
        $this->assertNotNull($this->details($visit)->provisioned_at);
        $this->assertSame([], VisitPhotos::locked($visit));
        $this->assertSame($textsWhenAssigned, $texts());

        // Speed tests and devices, then the existing flow: dispatcher closes, CX Field Support confirms.
        $visit = $this->workflow->completeVisit($visit, $this->technician, [
            'final_reason_id' => FinalReason::query()->where('name', 'Shifting done successfully')->value('id'), 'resolution_notes' => 'Moved and tested', 'fat_number' => 'FAT-9',
            'speed_photo' => [$this->photo('wired.jpg'), $this->photo('wifi.jpg')], 'devices_ok' => $this->photo('tv.jpg'),
        ]);
        // Back at Shifting Dispatch, with the dispatcher who dispatched it.
        $this->assertSame(QueueType::ShiftingDispatch, $visit->current_queue);
        $this->assertSame($this->dispatcher->id, $visit->current_assignee_id);
        $visit = $this->workflow->verifyVisit($visit, $this->dispatcher, 'Customer online');
        $this->assertSame(QueueType::CxFieldSupport, $visit->current_queue);
        $this->assertSame($fieldSupport->id, $visit->current_assignee_id);
        $visit = $this->workflow->confirmVisit($visit, $fieldSupport, ['outcome' => 'confirmed', 'notes' => 'Happy']);
        $this->assertSame(StatusCode::VisitClosed->value, $visit->status->code);
    }

    public function test_other_visits_have_no_provisioning_step_unless_the_onu_is_replaced(): void
    {
        $siteDispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        $support = $this->workflow->createSiteVisit([
            'visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id'),
            'subcategory_id' => TicketSubcategory::query()->where('name', 'LOS Flashing Red')->value('id'),
            'account_number' => 'SF2659302', 'customer_name' => 'Jane', 'calling_number' => '0772000011', 'address' => 'Lukuli',
        ], $siteDispatcher);
        // A Support visit goes to a Site Visit Technician (the shifting technician only does shifting).
        $technician = $this->makeUser('Site Visit Technician', 'DISPATCH');
        $support = $this->workflow->assignVisit($support, $siteDispatcher, ['technician_id' => $technician->id]);
        $support = $this->workflow->visitArrive($support, $technician);

        $this->assertSame([], VisitPhotos::locked($support));
        $this->assertFalse(VisitPhotos::needsProvisioning($support));
        // Offered as "ONU replaced — send to NOC for provisioning" (OnuProvisioningTest).
        $this->assertTrue($technician->can('visitProvisionRequest', $support));
        $this->assertFalse($siteDispatcher->can('visitRelocate', $support));
    }

    public function test_a_relocation_to_another_olt_goes_to_a_dispatcher_of_the_new_area(): void
    {
        $north = Region::query()->create(['name' => 'North', 'is_active' => true]);
        $south = Region::query()->create(['name' => 'South', 'is_active' => true]);
        $oldOlt = $this->makeOlt('OLT-NORTH');
        $oldOlt->forceFill(['region_id' => $north->id])->save();
        $newOlt = $this->makeOlt('OLT-SOUTH');
        $newOlt->forceFill(['region_id' => $south->id])->save();
        $this->dispatcher->regions()->sync([$north->id]);
        $southDispatcher = $this->makeUser('Shifting / Relocation Dispatcher', 'DISPATCH');
        $southDispatcher->regions()->sync([$south->id]);

        $visit = $this->shifting(['olt_id' => $oldOlt->id, 'region_id' => $north->id]);
        $this->assertSame($this->dispatcher->id, $visit->current_assignee_id);

        $visit = $this->workflow->relocateVisit($visit, $this->dispatcher, [
            'olt_id' => $newOlt->id, 'address' => 'New house, Kansanga', 'road' => 'Ggaba Road', 'landmark' => 'Opposite the church', 'note' => 'Customer moving on Friday',
        ]);
        $ticket = $this->details($visit);

        $this->assertSame([$newOlt->id, $south->id, 'New house, Kansanga', 'Ggaba Road', 'Opposite the church'],
            [$ticket->olt_id, $ticket->region_id, $ticket->address, $ticket->road, $ticket->landmark]);
        $this->assertNotNull($ticket->relocated_at);
        $this->assertSame(QueueType::ShiftingDispatch, $visit->current_queue);
        $this->assertSame($southDispatcher->id, $visit->current_assignee_id, 'the dispatcher of the new region gets it');

        // Or straight to a named dispatcher.
        $other = $this->makeUser('Shifting / Relocation Dispatcher', 'DISPATCH');
        $visit = $this->workflow->relocateVisit($visit, $southDispatcher, ['olt_id' => $newOlt->id, 'address' => 'New house, Kansanga', 'dispatcher_id' => $other->id]);
        $this->assertSame($other->id, $visit->current_assignee_id);
    }

    public function test_the_buttons_on_the_visit_page(): void
    {
        $visit = $this->workflow->assignVisit($this->shifting(), $this->dispatcher, ['technician_id' => $this->technician->id]);
        $visit = $this->workflow->visitArrive($visit, $this->technician);

        $this->actingAs($this->technician);
        Livewire::test(ViewSiteVisit::class, ['record' => $visit->id])
            ->assertActionVisible(TestAction::make('visitProvisionRequest'))
            ->callAction(TestAction::make('visitProvisionRequest'), ['note' => 'ONU HWTC1234'])
            ->assertHasNoActionErrors();

        $this->actingAs($this->noc);
        Livewire::test(ViewSiteVisit::class, ['record' => $visit->id])
            ->assertActionVisible(TestAction::make('visitProvisioned'))
            ->callAction(TestAction::make('visitProvisioned'), ['note' => 'Done'])
            ->assertHasNoActionErrors();
        $this->assertSame($this->technician->id, $visit->refresh()->current_assignee_id);

        $newOlt = $this->makeOlt('OLT-NEW');
        $this->actingAs($this->dispatcher);
        Livewire::test(ViewSiteVisit::class, ['record' => $visit->id])
            ->assertActionVisible(TestAction::make('visitRelocate'))
            ->callAction(TestAction::make('visitRelocate'), ['olt_id' => $newOlt->id, 'address' => 'Plot 5, Ntinda', 'road' => 'Kigoowa Road'])
            ->assertHasNoActionErrors();
        $this->assertSame('Kigoowa Road', $this->details($visit)->road);
    }
}
