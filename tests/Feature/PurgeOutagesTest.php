<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\Olt;
use App\Models\Outage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** oms:purge-outages empties all ticket data and keeps configuration, users and topology. */
class PurgeOutagesTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    public function test_purge_removes_every_ticket_and_restarts_numbering(): void
    {
        $this->seedReference();
        Mail::fake();
        $olt = $this->makeOlt('KIIRA');
        $agent = $this->makeUser('CX Agent', 'CX');
        $this->makeUser('NOC Tier I', 'NOC');
        $this->reportOutage($agent, ['olt_ids' => [$olt->id]]);
        $this->reportOutage($agent);
        $this->assertGreaterThan(0, NotificationLog::query()->count());

        $this->artisan('oms:purge-outages')->expectsQuestion('Type PURGE to continue', 'no')->assertFailed();
        $this->assertSame(2, Outage::query()->count(), 'cancelled without the confirmation word');

        $this->artisan('oms:purge-outages', ['--force' => true])->assertSuccessful();

        foreach (['outages', 'outage_olt', 'outage_status_histories', 'outage_updates', 'outage_queue_entries', 'outage_assignments',
            'outage_assignment_histories', 'sla_events', 'ticket_sequences', 'notifications'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} emptied");
        }
        $this->assertSame(0, NotificationLog::query()->whereNotNull('outage_id')->count());
        $this->assertTrue(Olt::query()->whereKey($olt->id)->exists(), 'topology kept');
        $this->assertTrue(User::query()->whereKey($agent->id)->exists(), 'users kept');

        $this->assertSame('OUT-00000000001', $this->reportOutage($agent)->ticket_number, 'numbering restarts');
    }
}
