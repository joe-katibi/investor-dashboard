<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\Region;
use App\Models\Ticket;
use App\Models\TicketSubcategory;
use App\Services\Outages\OutageWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * Regions were set on the OLTs after tickets had been raised on them: oms:fill-ticket-regions gives
 * those tickets (and their customers) the OLT's region, and leaves alone what already has one.
 */
class FillTicketRegionsTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
    }

    private function ticketOn(?Olt $olt, string $account): Ticket
    {
        $frontline = $this->makeUser('CX Frontline', 'CX');
        Auth::setUser($frontline);
        $outage = app(OutageWorkflow::class)->createTicket(['subcategory_id' => TicketSubcategory::query()->where('name', 'Slow Speeds')->value('id'),
            'account_number' => $account, 'calling_number' => '0772000001', 'description' => 'Slow', 'olt_id' => $olt?->id], $frontline);

        return Ticket::query()->where('outage_id', $outage->id)->firstOrFail();
    }

    public function test_tickets_and_customers_take_the_region_set_on_their_olt_afterwards(): void
    {
        $seguku = Olt::query()->create(['name' => 'SEGUKU', 'is_active' => true]);
        $kira = Olt::query()->create(['name' => 'KIRA', 'is_active' => true]);
        $customer = Customer::query()->create(['account_number' => 'SF1', 'name' => 'SF1', 'olt_id' => $seguku->id, 'source' => Customer::SOURCE_IMPORT, 'is_active' => true]);

        // Raised while the OLTs had no region.
        $onSeguku = $this->ticketOn($seguku, 'SF1');
        $onKira = $this->ticketOn($kira, 'SF2');
        $noOlt = $this->ticketOn(null, 'SF3');
        $this->assertNull($onSeguku->region_id);

        // Later: SEGUKU gets a region; KIRA still has none. One ticket was given a region by hand.
        $central = Region::query()->create(['name' => 'Central', 'is_active' => true]);
        $wakiso = Region::query()->create(['name' => 'Wakiso', 'is_active' => true]);
        $seguku->update(['region_id' => $central->id]);
        $byHand = $this->ticketOn($seguku, 'SF4');
        $byHand->forceFill(['region_id' => $wakiso->id])->save();

        $this->artisan('oms:fill-ticket-regions')
            ->expectsOutputToContain('Preview only')
            ->expectsOutputToContain('on OLTs that have no region yet')
            ->assertSuccessful();
        $this->assertNull($onSeguku->refresh()->region_id, 'the preview changes nothing');

        $this->artisan('oms:fill-ticket-regions', ['--apply' => true])->assertSuccessful();

        $this->assertSame($central->id, $onSeguku->refresh()->region_id);
        $this->assertSame($central->id, $customer->refresh()->region_id);
        $this->assertSame($wakiso->id, $byHand->refresh()->region_id, 'a region already set is kept');
        $this->assertNull($onKira->refresh()->region_id, 'its OLT has no region');
        $this->assertNull($noOlt->refresh()->region_id);

        // Run again: nothing left that it can fill.
        $this->artisan('oms:fill-ticket-regions')->expectsOutputToContain('nothing to fill')->assertSuccessful();
    }
}
