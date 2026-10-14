<?php

namespace Tests\Feature;

use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Filament\Pages\MyWork;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ManageCustomers;
use App\Filament\Resources\Tickets\Pages\CreateTicket;
use App\Filament\Resources\Tickets\TicketActions;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Customer;
use App\Models\FinalReason;
use App\Models\Outage;
use App\Models\OutageAttachment;
use App\Models\OutagePhoto;
use App\Models\OutageType;
use App\Models\OutageUpdate;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\Vertical;
use App\Services\Outages\OutageWorkflow;
use App\Support\Settings;
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
 * Day-to-day conveniences of the ticket screens: finding a ticket by the customer's account,
 * a customer's tickets from the customer list, attachments while raising a ticket, final reasons
 * by desk, photos made mandatory or optional, and My Work announcing new work by itself.
 */
class TicketUsabilityTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private OutageWorkflow $workflow;

    private User $frontline;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->seedReference();
        Mail::fake();
        Storage::fake('local');
        $this->workflow = app(OutageWorkflow::class);
        $this->frontline = $this->makeUser('CX Frontline', 'CX');
        Auth::setUser($this->frontline);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ticket(string $issue = 'Wrong bill / double charge', array $overrides = []): Outage
    {
        return $this->workflow->createTicket(array_merge([
            'subcategory_id' => TicketSubcategory::query()->where('name', $issue)->value('id'),
            'account_number' => 'SF2577916', 'customer_name' => 'Jane Customer', 'calling_number' => '0772000001',
            'address' => 'Seguku', 'description' => 'Charged twice in September',
        ], $overrides), $this->frontline);
    }

    public function test_the_search_box_finds_a_ticket_by_account_number_customer_or_phone(): void
    {
        $ticket = $this->ticket();
        $other = $this->ticket(overrides: ['account_number' => 'SF9000009', 'customer_name' => 'Other Person', 'calling_number' => '0701999999']);
        $this->actingAs($this->makeUser('CX Manager', 'CX'));

        $found = fn (string $search) => TicketResource::getGlobalSearchResults($search)->map(fn ($result) => $result->title)->all();

        $this->assertSame([$ticket->ticket_number], $found('SF2577916'));
        $this->assertSame([$ticket->ticket_number], $found('sf2577'), 'part of the account number, in any case');
        $this->assertSame([$ticket->ticket_number], $found('Jane Customer'));
        $this->assertSame([$other->ticket_number], $found('0701999999'));
        $this->assertSame([$other->ticket_number], $found($other->ticket_number));
        $this->assertSame([], $found('SF0000000'));

        // The result says whose ticket it is.
        $details = TicketResource::getGlobalSearchResults('SF2577916')->first()->details;
        $this->assertSame(['Account' => 'SF2577916', 'Customer' => 'Jane Customer'], array_intersect_key($details, ['Account' => 1, 'Customer' => 1]));

        // The ticket list itself is searched the same way.
        $this->get(TicketResource::getUrl('index').'?search=SF9000009')->assertOk();
    }

    public function test_a_customers_tickets_open_from_the_customer_list_with_their_details(): void
    {
        $billing = $this->makeUser('Billing Agent', 'BILLING');
        $first = $this->ticket();
        $second = $this->ticket('Wrong bill / double charge', ['description' => 'Still not reversed']);
        $this->workflow->closeTicket($first, $billing, [
            'final_reason_id' => FinalReason::query()->where('name', 'Billing query resolved')->value('id'),
            'resolution_notes' => 'Second charge reversed', 'customer_confirmed' => true,
        ]);
        $customer = Customer::query()->where('account_number', 'SF2577916')->firstOrFail();
        $stranger = Customer::query()->create(['account_number' => 'SF1', 'name' => 'No Tickets']);

        $this->actingAs($this->makeUser('CX Manager', 'CX'));
        $this->get(CustomerResource::getUrl())->assertOk();

        Livewire::test(ManageCustomers::class)
            ->assertActionVisible(TestAction::make('tickets')->table($customer))
            ->assertActionHasLabel(TestAction::make('tickets')->table($customer), 'Tickets');

        // Both tickets, newest first: what was reported, where each one is, how the closed one ended — and a way in.
        $panel = CustomerResource::ticketsPanel($customer)->render();
        $this->assertStringContainsString('Still not reversed', $panel);
        $this->assertStringContainsString('Charged twice in September', $panel);
        $this->assertStringContainsString('Billing query resolved', $panel);
        $this->assertStringContainsString('Second charge reversed', $panel);
        $this->assertStringContainsString('Open the full ticket', $panel);
        $this->assertStringContainsString(e(TicketResource::getUrl('view', ['record' => $second])), $panel);
        $this->assertLessThan(strpos($panel, $first->ticket_number), strpos($panel, $second->ticket_number), 'newest first');
        $this->assertMatchesRegularExpression('/Open now<\/div><div class="oms-stat-value [a-z-]+">1</', $panel);

        $this->assertStringContainsString('No ticket or site visit has been raised for this customer.', CustomerResource::ticketsPanel($stranger)->render());

        // Someone who may not open tickets gets the facts without the links.
        $this->actingAs($this->makeUser('IT Engineer', 'IT'));
        $this->assertStringNotContainsString('Open the full ticket', CustomerResource::ticketsPanel($customer)->render());
        $this->actingAs($this->makeUser('CX Manager', 'CX'));
        // The count in the list is the way in.
        $this->assertSame(2, Customer::query()->withCount('tickets')->find($customer->id)->tickets_count);
    }

    public function test_files_can_be_attached_while_raising_a_ticket(): void
    {
        $this->actingAs($this->frontline);

        Livewire::test(CreateTicket::class)
            ->fillForm([
                'subcategory_id' => TicketSubcategory::query()->where('name', 'Wrong bill / double charge')->value('id'),
                'account_number' => 'SF300', 'customer_name' => 'Receipt Holder', 'calling_number' => '0772333444',
                'description' => 'Paid twice, receipts attached', 'external_reference' => 'TKT-200', 'preferred_time' => 'Mornings',
                'attachments' => [UploadedFile::fake()->image('receipt.jpg', 640, 480), UploadedFile::fake()->create('statement.pdf', 120, 'application/pdf')],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $ticket = Outage::query()->cxTickets()->latest('id')->firstOrFail();
        $files = OutageAttachment::query()->where('outage_id', $ticket->id)->orderBy('id')->get();

        $this->assertSame(['receipt.jpg', 'statement.pdf'], $files->pluck('original_name')->all());
        $this->assertSame([$this->frontline->id], $files->pluck('user_id')->unique()->all());
        $files->each(fn (OutageAttachment $file) => Storage::disk('local')->assertExists($file->path));
        $this->assertTrue(OutageUpdate::query()->where('outage_id', $ticket->id)->where('body', 'Attached when raised: receipt.jpg, statement.pdf.')->exists(), 'on the timeline');

        // Nothing attached: nothing added, nothing said.
        $plain = $this->ticket();
        $this->assertSame(0, OutageAttachment::query()->where('outage_id', $plain->id)->count());
        // A path that was not uploaded through the form is ignored.
        $forged = $this->ticket(overrides: ['attachments' => ['../.env', 'outage-attachments/nothing-here.pdf']]);
        $this->assertSame(0, OutageAttachment::query()->where('outage_id', $forged->id)->count());
    }

    public function test_each_desk_is_offered_its_own_final_reasons(): void
    {
        $billing = $this->makeUser('Billing Agent', 'BILLING');
        $billingReason = FinalReason::query()->where('name', 'Billing query resolved')->firstOrFail();
        $nocReason = FinalReason::query()->create(['name' => 'Line restored by NOC', 'applies_to' => 'ticket', 'desks' => [QueueType::TicketNoc->value], 'is_active' => true]);
        $everywhere = FinalReason::query()->create(['name' => 'Customer withdrew', 'applies_to' => 'ticket', 'is_active' => true]);

        // Until desks are ticked, a reason is offered everywhere — nothing changes for existing reasons.
        $this->assertArrayHasKey($billingReason->id, TicketActions::finalReasons('ticket', QueueType::TicketNoc));

        $billingReason->update(['desks' => [QueueType::Billing->value, QueueType::BillingManager->value]]);
        $this->assertSame('Billing, Billing Manager', $billingReason->desksLabel());

        $atBilling = TicketActions::finalReasons('ticket', QueueType::Billing);
        $atNoc = TicketActions::finalReasons('ticket', QueueType::TicketNoc);
        $this->assertArrayHasKey($billingReason->id, $atBilling);
        $this->assertArrayNotHasKey($nocReason->id, $atBilling, 'a billing agent does not see the NOC reasons');
        $this->assertArrayHasKey($nocReason->id, $atNoc);
        $this->assertArrayNotHasKey($billingReason->id, $atNoc);
        $this->assertArrayHasKey($everywhere->id, $atBilling);
        $this->assertArrayHasKey($everywhere->id, $atNoc);

        // The workflow holds the same line, whatever a form sent.
        $ticket = $this->ticket();
        $this->assertSame(QueueType::Billing, $ticket->current_queue);

        try {
            $this->workflow->closeTicket($ticket, $billing, ['final_reason_id' => $nocReason->id]);
            $this->fail('A NOC reason is not accepted at the Billing desk');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('is not a final reason of the Billing desk', $e->errors()['final_reason_id'][0]);
        }

        $closed = $this->workflow->closeTicket($ticket, $billing, ['final_reason_id' => $billingReason->id, 'customer_confirmed' => true]);
        $this->assertTrue($closed->is_closed);
    }

    public function test_photos_can_be_made_optional_for_outages_and_maintenance(): void
    {
        $team = $this->makeTeam('Field Ops', 'INFRA');
        $crew = $this->makeTeam('Crew', 'INFRA', $team);
        $noc = $this->makeUser('NOC Tier II', 'NOC');
        $dispatcher = $this->makeUser('Infrastructure Dispatcher', 'INFRA', [$team]);
        $tech = $this->makeUser('Outage Technician', 'INFRA', [$crew]);
        $settings = app(Settings::class);

        $dispatched = function () use ($noc, $dispatcher, $team, $crew, $tech): Outage {
            $outage = $this->reportOutage($this->makeUser('CX Agent', 'CX'));
            Auth::setUser($noc);
            $outage = $this->workflow->confirmByNoc($outage, $noc);
            Auth::setUser($dispatcher);
            $outage = $this->workflow->dispatch($outage, ['team_id' => $team->id, 'sub_team_id' => $crew->id], $dispatcher);
            Auth::setUser($tech);

            return $outage;
        };

        // Mandatory by default.
        $outage = $dispatched();
        $this->assertTrue($outage->requiresSitePhotos());

        // Made optional: still offered — the technician can add them — but no longer in the way.
        $settings->set(['photos.outage_required' => false]);
        $this->assertTrue($outage->offersSitePhotos());
        $this->assertFalse($outage->requiresSitePhotos());
        $this->assertTrue($tech->can('addSitePhotos', $outage));
        $path = UploadedFile::fake()->image('findings.jpg')->store('outage-photos/test', 'local');
        $this->workflow->addSitePhotos($outage, $tech, ['findings' => $path]);
        $outage = $this->workflow->reportRestored($outage, $tech, 'Spliced');
        $this->assertTrue($outage->status->isCode(StatusCode::RestoreReported));
        $this->assertSame(1, $outage->photos()->where('stage', OutagePhoto::SITE)->count(), 'the photo that was taken is kept');

        // Mandatory again.
        $settings->set(['photos.outage_required' => true]);
        $again = $dispatched();
        $this->expectExceptionOnce(fn () => $this->workflow->reportRestored($again, $tech, 'Spliced'), 'photos');

        // Field maintenance: raised and completed without photos once they are optional.
        $raise = fn (array $photos) => $this->workflow->raiseFieldMaintenance([
            'kind' => OutageType::CORRECTIVE, 'title' => 'Pole down', 'description' => 'Found on site', 'site_label' => 'FAT-KBW-017',
            'vertical_id' => Vertical::query()->where('code', Vertical::FTTH)->value('id'), 'customers_affected' => 4, 'photos' => $photos,
        ], $tech);

        $this->expectExceptionOnce(fn () => $raise([]), 'photos.outside');

        $settings->set(['photos.maintenance_required' => false]);
        $maintenance = $raise(['outside' => UploadedFile::fake()->image('before.jpg')->store('outage-photos/test', 'local')]);
        $this->assertSame(['outside'], $maintenance->photos()->where('stage', OutagePhoto::BEFORE)->pluck('position')->all(), 'one of the two is fine when optional');
        $maintenance = $this->workflow->reportRestored($maintenance, $tech, 'Pole replaced');
        $this->assertTrue($maintenance->status->isCode(StatusCode::RestoreReported));
    }

    public function test_my_work_looks_for_new_work_by_itself_and_announces_it(): void
    {
        $billing = $this->makeUser('Billing Agent', 'BILLING');
        $this->actingAs($billing);

        $page = Livewire::test(MyWork::class)
            ->assertSee('Nothing assigned to you')
            ->assertSeeHtml('wire:poll.keep-alive.'.MyWork::REFRESH_SECONDS.'s="checkForNewWork"');
        $this->assertSame(40, MyWork::REFRESH_SECONDS);

        // Nothing new: the refresh says nothing.
        $page->call('checkForNewWork')->assertNotNotified();

        // A ticket lands on the billing desk and FIFO gives it to this agent.
        Auth::setUser($this->frontline);
        $ticket = $this->ticket();
        $this->assertSame($billing->id, $ticket->current_assignee_id);
        $this->actingAs($billing);

        $page->call('checkForNewWork')
            ->assertNotified('New ticket for you: '.$ticket->ticket_number)
            ->assertSee('Jane Customer');

        // Announced once, not at every refresh.
        $page->call('checkForNewWork')->assertNotNotified('New ticket for you: '.$ticket->ticket_number);
    }

    /** The callback is refused with a validation error on $key. */
    private function expectExceptionOnce(callable $attempt, string $key): void
    {
        try {
            $attempt();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());

            return;
        }

        $this->fail("Expected to be refused on [{$key}]");
    }
}
