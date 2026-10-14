<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Fdt;
use App\Models\Olt;
use App\Models\Port;
use App\Models\Slot;
use App\Models\Ticket;
use App\Models\TicketSubcategory;
use App\Services\Outages\OutageWorkflow;
use App\Services\Tickets\CustomerImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * A customer file that spelt the OLTs its own way ("Seguku-OLT" for SEGUKU) created them a second
 * time with their FDTs, slots and ports. oms:merge-olts folds them back into the originals, and the
 * imports match those spellings from then on.
 */
class MergeOltsTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReference();
        Mail::fake();
    }

    /** @return array{0: Olt, 1: Fdt, 2: Slot, 3: Port} */
    private function topology(string $olt, string $fdt, string $slot, array $ports, string $createdAt): array
    {
        $o = Olt::query()->where('name', $olt)->first();
        if (! $o) {
            $o = Olt::query()->create(['name' => $olt, 'is_active' => true]);
            $o->forceFill(['created_at' => $createdAt])->save();
        }
        $f = Fdt::query()->firstOrCreate(['olt_id' => $o->id, 'name' => $fdt], ['is_active' => true]);
        $s = Slot::query()->firstOrCreate(['fdt_id' => $f->id, 'name' => $slot], ['is_active' => true]);
        $p = null;
        foreach ($ports as $port) {
            $p = Port::query()->firstOrCreate(['slot_id' => $s->id, 'name' => $port], ['is_active' => true]);
        }

        return [$o, $f, $s, $p];
    }

    private function customer(string $account, Olt $olt, Fdt $fdt, Slot $slot, Port $port): Customer
    {
        return Customer::query()->create(['account_number' => $account, 'name' => $account, 'olt_id' => $olt->id, 'fdt_id' => $fdt->id,
            'slot_id' => $slot->id, 'port_id' => $port->id, 'source' => Customer::SOURCE_IMPORT, 'is_active' => true]);
    }

    public function test_duplicates_are_folded_into_the_originals_and_imports_match_them_afterwards(): void
    {
        // The originals…
        [$seguku, $kbw1, $slot1] = $this->topology('SEGUKU', 'KBW FDT 1', '1', ['1', '2'], '2026-10-01 07:00');
        [$salaama] = $this->topology('Salaama', 'FDT 1', '3', ['9'], '2026-09-25 12:00');
        // …and what the customer file created next to them.
        [$dup, $dupKbw1, $dupSlot1, $dupPort2] = $this->topology('Seguku-OLT', 'KBW FDT 1', '1', ['2'], '2026-10-06 22:47');
        [, , , $dupPort3] = $this->topology('Seguku-OLT', 'KBW FDT 1', '1', ['3'], '2026-10-06 22:47');
        [, , $dupSlot5, $dupSlot5Port] = $this->topology('Seguku-OLT', 'KBW FDT 1', '5', ['1'], '2026-10-06 22:47');
        [, $dupKbw9, $dupKbw9Slot, $dupKbw9Port] = $this->topology('Seguku-OLT', 'KBW FDT 9', '2', ['4'], '2026-10-06 22:47');
        [$salama, $salamaFdt, $salamaSlot, $salamaPort] = $this->topology('Salama-OLT', 'FDT 1', '3', ['9'], '2026-10-06 22:47');

        $onTwin = $this->customer('SF1', $dup, $dupKbw1, $dupSlot1, $dupPort2);
        $onNewPort = $this->customer('SF2', $dup, $dupKbw1, $dupSlot1, $dupPort3);
        $onNewFdt = $this->customer('SF3', $dup, $dupKbw9, $dupKbw9Slot, $dupKbw9Port);
        $onSalama = $this->customer('SF4', $salama, $salamaFdt, $salamaSlot, $salamaPort);

        // A ticket and an outage on the duplicate; one outage on both.
        $frontline = $this->makeUser('CX Frontline', 'CX');
        Auth::setUser($frontline);
        $ticket = app(OutageWorkflow::class)->createTicket(['subcategory_id' => TicketSubcategory::query()->where('name', 'Slow Speeds')->value('id'),
            'account_number' => 'SF1', 'calling_number' => '0772000001', 'description' => 'Slow',
            'olt_id' => $dup->id, 'fdt_id' => $dupKbw1->id, 'slot_id' => $dupSlot1->id, 'port_id' => $dupPort2->id], $frontline);
        $outage = $this->reportOutage($this->makeUser('NOC Tier I', 'NOC'), ['olt_ids' => [$dup->id], 'fdt_ids' => [$dupKbw1->id]]);
        DB::table('outage_olt')->insert(['outage_id' => $outage->id, 'olt_id' => $seguku->id]);
        $team = $this->makeTeam('Field', 'INFRA');
        $team->olts()->attach([$dup->id, $seguku->id]);

        // The plan: Seguku-OLT found by its name; Salama-OLT given as a pair. Nothing changes yet.
        $this->artisan('oms:merge-olts', ['--pair' => ['Salama-OLT=Salaama']])
            ->expectsOutputToContain('Preview only')->assertSuccessful();
        $this->assertSame(4, Olt::query()->count());

        $this->artisan('oms:merge-olts', ['--pair' => ['Salama-OLT=Salaama'], '--apply' => true])->assertSuccessful();

        $this->assertSame(['SEGUKU', 'Salaama'], Olt::query()->orderBy('name')->pluck('name')->sort()->values()->all());
        $this->assertSame(['Seguku-OLT'], $seguku->refresh()->aliases);
        $this->assertSame(['Salama-OLT'], $salaama->refresh()->aliases);

        // FDT / slot / port with a twin: merged; without one: moved across.
        $this->assertSame(['KBW FDT 1', 'KBW FDT 9'], Fdt::query()->where('olt_id', $seguku->id)->orderBy('name')->pluck('name')->all());
        $this->assertSame(['1', '5'], Slot::query()->where('fdt_id', $kbw1->id)->orderBy('name')->pluck('name')->all());
        $this->assertSame(['1', '2', '3'], Port::query()->where('slot_id', $slot1->id)->orderBy('name')->pluck('name')->all());

        $twinPort = Port::query()->where('slot_id', $slot1->id)->where('name', '2')->value('id');
        $this->assertSame([$seguku->id, $kbw1->id, $slot1->id, $twinPort], array_values($onTwin->refresh()->only(['olt_id', 'fdt_id', 'slot_id', 'port_id'])));
        $this->assertSame([$seguku->id, $kbw1->id, $slot1->id, $dupPort3->id], array_values($onNewPort->refresh()->only(['olt_id', 'fdt_id', 'slot_id', 'port_id'])), 'the port moved across, same id');
        $this->assertSame([$seguku->id, $dupKbw9->id], array_values($onNewFdt->refresh()->only(['olt_id', 'fdt_id'])), 'the FDT moved across, same id');
        $this->assertSame($salaama->id, $onSalama->refresh()->olt_id);
        $this->assertSame($seguku->id, Ticket::query()->where('outage_id', $ticket->id)->value('olt_id'));
        $this->assertSame([$seguku->id], DB::table('outage_olt')->where('outage_id', $outage->id)->pluck('olt_id')->all(), 'linked once');
        $this->assertSame([$kbw1->id], DB::table('outage_fdt')->where('outage_id', $outage->id)->pluck('fdt_id')->all());
        $this->assertSame([$seguku->id], DB::table('olt_team')->where('team_id', $team->id)->pluck('olt_id')->all());

        // The next customer file, with its own spellings: nothing is created again.
        file_put_contents($path = tempnam(sys_get_temp_dir(), 'cust').'.csv',
            "Alias Name,ONU Name,OLT,FDT,SLOT,PORT,Bandwidth\nAgain,SF1,Seguku-OLT,KBW FDT 1,1,2,\nOther,SF4,Salama-OLT,FDT 1,3,9,\n");
        app(CustomerImporter::class)->import($path);
        $this->assertSame(2, Olt::query()->count());
        $this->assertSame($seguku->id, Customer::query()->where('account_number', 'SF1')->value('olt_id'));
        $this->assertSame($salaama->id, Customer::query()->where('account_number', 'SF4')->value('olt_id'));

        // Run again: nothing left to merge.
        $this->artisan('oms:merge-olts')->expectsOutputToContain('No duplicate OLTs')->assertSuccessful();
    }

    public function test_names_are_matched_loosely(): void
    {
        $this->assertSame('seguku', Olt::matchKey('Seguku-OLT'));
        $this->assertSame(Olt::matchKey('KABOWA FH'), Olt::matchKey('Kabowa-FH-OLT'));
        $this->assertSame(Olt::matchKey('KIBIRI OLT'), Olt::matchKey('Kibiri-OLT'));
        $this->assertSame(Olt::matchKey('NSAMBYA 2-OLT'), Olt::matchKey('Nsambya-OLT_2'));
        $this->assertNotSame(Olt::matchKey('KITENDE-OLT_1'), Olt::matchKey('KITENDE-OLT_2'));
        $this->assertNotSame(Olt::matchKey('KABOWA FH'), Olt::matchKey('KABOWA-HW-OLT'));
        $this->assertSame('voltage', Olt::matchKey('Voltage'), 'only the word OLT is dropped');
    }
}
