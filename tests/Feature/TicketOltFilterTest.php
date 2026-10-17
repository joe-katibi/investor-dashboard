<?php

namespace Tests\Feature;

use App\Filament\Resources\SiteVisits\Pages\ListSiteVisits;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Models\Olt;
use App\Models\Outage;
use App\Models\TicketSubcategory;
use App\Models\VisitType;
use App\Services\Outages\OutageWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** The customer ticket and site visit lists filter on several OLTs at once. */
class TicketOltFilterTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
    }

    public function test_tickets_and_visits_are_filtered_on_several_olts(): void
    {
        [$seguku, $kira, $salaama] = array_map(fn (string $name) => Olt::query()->create(['name' => $name, 'is_active' => true]), ['SEGUKU', 'KIRA', 'SALAAMA']);
        $frontline = $this->makeUser('CX Frontline', 'CX');
        $dispatcher = $this->makeUser('Site Visit Dispatcher', 'DISPATCH');
        Auth::setUser($frontline);

        $ticket = fn (Olt $olt, string $account): Outage => app(OutageWorkflow::class)->createTicket(['subcategory_id' => TicketSubcategory::query()->where('name', 'Slow Speeds')->value('id'),
            'account_number' => $account, 'calling_number' => '0772000001', 'description' => 'Slow', 'olt_id' => $olt->id], $frontline);
        $visit = fn (Olt $olt, string $account): Outage => app(OutageWorkflow::class)->createSiteVisit([
            'visit_type_id' => VisitType::query()->where('code_prefix', 'SUP')->value('id'),
            'subcategory_id' => TicketSubcategory::query()->where('name', 'LOS Flashing Red')->value('id'),
            'account_number' => $account, 'customer_name' => $account, 'calling_number' => '0772000010', 'address' => 'Kampala', 'olt_id' => $olt->id,
        ], $dispatcher);

        $tickets = [$ticket($seguku, 'SF1'), $ticket($kira, 'SF2'), $ticket($salaama, 'SF3')];
        $visits = [$visit($seguku, 'SF4'), $visit($kira, 'SF5'), $visit($salaama, 'SF6')];

        $this->actingAs($this->makeUser('Super Admin', 'MGMT'));

        Livewire::test(ListTickets::class)
            ->filterTable('olt', [$seguku->id, $kira->id])
            ->assertCanSeeTableRecords([$tickets[0], $tickets[1]])
            ->assertCanNotSeeTableRecords([$tickets[2]]);

        Livewire::test(ListSiteVisits::class)
            ->filterTable('olt', [$kira->id, $salaama->id])
            ->assertCanSeeTableRecords([$visits[1], $visits[2]])
            ->assertCanNotSeeTableRecords([$visits[0]]);
    }
}
