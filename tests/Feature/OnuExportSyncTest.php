<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerOnuChange;
use App\Models\Fdt;
use App\Models\Olt;
use App\Models\OnuSyncRun;
use App\Models\Port;
use App\Models\Slot;
use App\Services\Customers\OnuExportSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/**
 * oms:sync-onu-export: the newest ONU export brings the customers up to date — OLT (by name or alias), slot, port,
 * ONU type and physical address — writing only what changed and keeping each change in the history.
 */
class OnuExportSyncTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'onu-export-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        config(['oms.onu_exports.fiberhome.path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_newest_dump_updates_what_changed_and_keeps_the_history(): void
    {
        $this->seedReference();
        // "Zeta-OLT" in the export is ZETA by its name; "Omega-OLT" is OMEGA FH by its alias.
        $zeta = Olt::query()->create(['name' => 'ZETA', 'is_active' => true]);
        $omega = Olt::query()->create(['name' => 'OMEGA FH', 'aliases' => ['Omega-OLT'], 'is_active' => true]);
        $fdt = Fdt::query()->create(['olt_id' => $zeta->id, 'name' => 'FDT 1', 'is_active' => true]);
        $slot = Slot::query()->create(['fdt_id' => $fdt->id, 'name' => '3', 'is_active' => true]);
        $port = Port::query()->create(['slot_id' => $slot->id, 'name' => '5', 'is_active' => true]);
        $onZeta = ['olt_id' => $zeta->id, 'fdt_id' => $fdt->id, 'slot_id' => $slot->id, 'port_id' => $port->id, 'source' => 'import'];

        $same = Customer::query()->create(['account_number' => 'SF1000001', 'name' => 'Same Place'] + $onZeta);
        $moved = Customer::query()->create(['account_number' => 'SF1000002', 'name' => 'New Port'] + $onZeta);
        $otherOlt = Customer::query()->create(['account_number' => 'SF1000003', 'name' => 'Other OLT'] + $onZeta);
        $unknown = Customer::query()->create(['account_number' => 'SF1000006', 'name' => 'Unknown OLT'] + $onZeta);

        // Older dump: never read when a newer one is there.
        $this->dump('08_00_00_20261021_080000', [['Zeta-OLT', 9, 9, 'SF1000001', 'X', 'OLD', 'OLD1', '2026-01-01 00:00:00']]);
        $this->dump('12_00_00_20261021_120000', [
            ['Zeta-OLT', 3, 5, 'SF1000001', 'Same Place', 'HG6145F3', 'FHTT0001', '2026-03-01 10:00:00'],
            ['Zeta-OLT', 3, 5, 'SF1000002', 'New Port', 'HG6145F3', 'FHTT0002', '2026-02-01 10:00:00'],
            ['Zeta-OLT', 4, 7, 'SF1000002', 'New Port', 'HG6543C7', 'FHTT0022', '2026-09-01 10:00:00'], // newer ONU of the same account
            ['Omega-OLT', 1, 2, 'SF1000003', 'Other OLT', 'HG6145D2', 'FHTT0003', '2026-03-01 10:00:00'],
            ['Omega-OLT', 1, 3, 'SF1000004', 'Brand New', 'HG6145F3', 'FHTT0004', '2026-10-20 10:00:00'],
            ['Zeta-OLT', 2, 1, 'John SF1000005 test', 'Inside Words', 'HG6145F3', 'FHTT0005', '2026-10-20 10:00:00'],
            ['Zeta-OLT', 2, 2, 'Office router', 'Staff', 'HG6145F3', 'FHTT0099', '2026-10-20 10:00:00'],
            ['Mars-OLT', 8, 8, 'SF1000006', 'Unknown OLT', 'HG6145F3', 'FHTT0006', '2026-03-01 10:00:00'],
        ]);

        // The plan changes nothing.
        $this->artisan('oms:sync-onu-export')
            ->expectsOutputToContain('CS-Reports_12_00_00_20261021_120000_p1.csv')
            ->expectsOutputToContain('Mars-OLT')
            ->expectsOutputToContain('Preview only')
            ->assertSuccessful();
        $this->assertNull($same->refresh()->onu_type);
        $this->assertSame(0, OnuSyncRun::query()->count());

        $this->artisan('oms:sync-onu-export', ['--apply' => true])->expectsOutputToContain('Done:')->assertSuccessful();

        // Same place: the ONU details are filled in, nothing in the history.
        $same->refresh();
        $this->assertSame(['HG6145F3', 'FHTT0001', $slot->id, $port->id], [$same->onu_type, $same->onu_physical_address, $same->slot_id, $same->port_id]);
        $this->assertSame(0, CustomerOnuChange::query()->where('customer_id', $same->id)->count());

        // Slot and port changed (the account's newest ONU): on its FDT, the new slot and port are made.
        $moved->refresh();
        $this->assertSame($fdt->id, $moved->fdt_id);
        $this->assertSame(['4', '7'], [$moved->slot->name, $moved->port->name]);
        $this->assertSame('HG6543C7', $moved->onu_type);
        $this->assertEqualsCanonicalizing(['slot:3:4', 'port:5:7'], $this->history($moved));

        // Another OLT, by its alias: the OLT keeps its name; the FDT of the old one goes.
        $otherOlt->refresh();
        $this->assertSame([$omega->id, null, null, null], [$otherOlt->olt_id, $otherOlt->fdt_id, $otherOlt->slot_id, $otherOlt->port_id]);
        $this->assertSame('OMEGA FH', $omega->refresh()->name);
        $this->assertContains('olt:ZETA:OMEGA FH', $this->history($otherOlt));
        $this->assertContains('fdt:FDT 1:', $this->history($otherOlt));

        // New accounts are added — also one written among words; a row without an account is left out.
        $new = Customer::findByAccount('SF1000004');
        $this->assertSame([$omega->id, Customer::SOURCE_ONU_EXPORT, '1', '3'], [$new->olt_id, $new->source, $new->onu_slot, $new->onu_port]);
        $this->assertNotNull(Customer::findByAccount('SF1000005'));
        $this->assertSame(6, Customer::query()->count());

        // An OLT name OMS does not know: the customer keeps its OLT.
        $this->assertSame($zeta->id, $unknown->refresh()->olt_id);

        $run = OnuSyncRun::query()->sole();
        $this->assertSame([OnuSyncRun::DONE, 8, 2], [$run->status, $run->rows, $run->customers_created]);
        $this->assertSame(['Mars-OLT' => 1], $run->summary['unknown_olts']);

        // The same dump is read once.
        $this->artisan('oms:sync-onu-export', ['--apply' => true])->expectsOutputToContain('already read')->assertSuccessful();

        // The next dump: only what changed is written — the ONU of SF1000001 was replaced.
        $this->dump('17_00_00_20261021_170000', [
            ['Zeta-OLT', 3, 5, 'SF1000001', 'Same Place', 'HG6543C7', 'FHTT0101', '2026-10-21 16:00:00'],
            ['Zeta-OLT', 4, 7, 'SF1000002', 'New Port', 'HG6543C7', 'FHTT0022', '2026-09-01 10:00:00'],
            ['Omega-OLT', 1, 2, 'SF1000003', 'Other OLT', 'HG6145D2', 'FHTT0003', '2026-03-01 10:00:00'],
            ['Omega-OLT', 1, 3, 'SF1000004', 'Brand New', 'HG6145F3', 'FHTT0004', '2026-10-20 10:00:00'],
            ['Zeta-OLT', 2, 1, 'John SF1000005 test', 'Inside Words', 'HG6145F3', 'FHTT0005', '2026-10-20 10:00:00'],
            ['Zeta-OLT', 2, 2, 'Office router', 'Staff', 'HG6145F3', 'FHTT0099', '2026-10-20 10:00:00'],
            ['Mars-OLT', 8, 8, 'SF1000006', 'Unknown OLT', 'HG6145F3', 'FHTT0006', '2026-03-01 10:00:00'],
        ]);
        $before = CustomerOnuChange::query()->count();
        $this->artisan('oms:sync-onu-export', ['--apply' => true])->assertSuccessful();
        $this->assertEqualsCanonicalizing(['onu_type:HG6145F3:HG6543C7', 'onu_physical_address:FHTT0001:FHTT0101', 'onu_created_at:2026-03-01 10:00:00:2026-10-21 16:00:00'], $this->history($same));
        $this->assertSame($before + 3, CustomerOnuChange::query()->count());
        $this->assertSame(1, OnuSyncRun::query()->latest('id')->first()->customers_updated);

        // A cut-off dump is refused: nothing changes.
        $this->dump('21_00_00_20261021_210000', [['Omega-OLT', 1, 2, 'SF1000001', 'Same Place', 'HG6145F3', 'FHTT0001', '2026-03-01 10:00:00']]);
        $this->artisan('oms:sync-onu-export', ['--apply' => true])->expectsOutputToContain('cut off')->assertFailed();
        $this->assertSame($zeta->id, $same->refresh()->olt_id);
        $this->assertSame(OnuSyncRun::FAILED, OnuSyncRun::query()->latest('id')->first()->status);
    }

    public function test_the_account_is_found_in_the_onu_name(): void
    {
        $this->assertSame('SF2659301', OnuExportSync::account('sf2659301'));
        $this->assertSame('SF2659301', OnuExportSync::account('Jane SF2659301 shop'));
        $this->assertSame('SFL1234567', OnuExportSync::account('SFL1234567'));
        $this->assertNull(OnuExportSync::account('Office router'));
        $this->assertNull(OnuExportSync::account('HG6145F3'));
        $this->assertSame('Nsambya-OLT', OnuExportSync::cell('="Nsambya-OLT"'));
    }

    public function test_the_uploader_sends_a_dump_in_and_it_is_read(): void
    {
        $this->seedReference();
        $zeta = Olt::query()->create(['name' => 'ZETA', 'is_active' => true]);
        Customer::query()->create(['account_number' => 'SF2000001', 'name' => 'On File', 'olt_id' => $zeta->id, 'source' => 'import']);
        $secret = str_repeat('k', 48);
        config(['oms.onu_export_upload.token' => $secret, 'oms.onu_export_upload.allowed_ips' => []]);

        $csv = $this->csv([['Zeta-OLT', 2, 4, 'SF2000001', 'On File', 'HG6145F3', 'FHTT2001', '2026-05-01 10:00:00']]);
        $name = 'CS-Reports_12_00_00_20261022_120000_p1.csv';
        $send = fn (string $body, array $headers) => $this->call('POST', '/api/integrations/onu-export/fiberhome', [], [], [], array_merge([
            'HTTP_X_ONU_FILE' => $name, 'HTTP_X_ONU_SHA256' => hash('sha256', $csv), 'CONTENT_TYPE' => 'application/gzip', 'HTTP_ACCEPT' => 'application/json',
        ], $headers), $body);

        // Without the secret, or with a damaged file: refused, nothing kept.
        $send(gzencode($csv), [])->assertUnauthorized();
        $send(gzencode($csv), ['HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('x', 48)])->assertUnauthorized();
        $send(gzencode($csv.'x'), ['HTTP_AUTHORIZATION' => "Bearer {$secret}"])->assertUnprocessable();
        $send(gzencode($csv), ['HTTP_AUTHORIZATION' => "Bearer {$secret}", 'HTTP_X_ONU_FILE' => '../../evil.csv'])->assertUnprocessable();
        $this->assertFileDoesNotExist($this->dir.DIRECTORY_SEPARATOR.$name);

        // From an address not on the list: refused.
        config(['oms.onu_export_upload.allowed_ips' => ['10.32.28.32']]);
        $send(gzencode($csv), ['HTTP_AUTHORIZATION' => "Bearer {$secret}"])->assertForbidden();
        config(['oms.onu_export_upload.allowed_ips' => []]);

        // The right secret, automatic reading still off: only kept.
        $send(gzencode($csv), ['HTTP_AUTHORIZATION' => "Bearer {$secret}"])->assertAccepted()->assertJson(['status' => 'stored']);
        $this->assertNull(Customer::findByAccount('SF2000001')->onu_type);

        // On: kept, and read straight away.
        config(['oms.onu_export_upload.auto_apply' => true]);
        $send(gzencode($csv), ['HTTP_AUTHORIZATION' => "Bearer {$secret}"])->assertAccepted()->assertJson(['status' => 'queued']);
        $this->assertFileExists($this->dir.DIRECTORY_SEPARATOR.$name);
        $customer = Customer::findByAccount('SF2000001');
        $this->assertSame(['HG6145F3', '2', '4'], [$customer->onu_type, $customer->onu_slot, $customer->onu_port]);
        $this->assertSame(OnuSyncRun::DONE, OnuSyncRun::query()->sole()->status);

        // Sent again: OMS has it already.
        $send(gzencode($csv), ['HTTP_AUTHORIZATION' => "Bearer {$secret}"])->assertOk()->assertJson(['status' => 'already_read']);
        $this->assertSame(1, OnuSyncRun::query()->count());

        // No secret set in OMS: the address does not exist.
        config(['oms.onu_export_upload.token' => '']);
        $send(gzencode($csv), ['HTTP_AUTHORIZATION' => "Bearer {$secret}"])->assertNotFound();
    }

    /** A dump as the network management system writes it: every cell as ="value", a trailing comma. */
    private function dump(string $stamp, array $rows): void
    {
        $path = $this->dir.DIRECTORY_SEPARATOR."CS-Reports_{$stamp}_p1.csv";
        file_put_contents($path, $this->csv($rows));
        touch($path, time() - 600);
    }

    private function csv(array $rows): string
    {
        $line = fn (array $cells) => implode(',', array_map(fn ($cell) => '="'.$cell.'"', $cells)).',';
        $csv = [$line(['NE Name', 'NE IP Address', 'Slot Address', 'Card Port No.', 'ONU Authorization No.', 'ONU Name', 'Alias Name', 'ONU Type', 'ONU Physical Address', 'ONU Creation Time', 'ONU Online Status', 'Bandwidth Profile Name'])];

        foreach ($rows as [$olt, $slot, $port, $name, $alias, $type, $physical, $created]) {
            $csv[] = $line([$olt, '10.0.0.1', $slot, $port, 1, $name, $alias, $type, $physical, $created, 'Online', '50M']);
        }

        return implode("\r\n", $csv)."\r\n";
    }

    /** @return list<string> "field:old:new" of a customer's history */
    private function history(Customer $customer): array
    {
        return CustomerOnuChange::query()->where('customer_id', $customer->id)->get()
            ->map(fn (CustomerOnuChange $c) => "{$c->field}:{$c->old_value}:{$c->new_value}")->all();
    }
}
