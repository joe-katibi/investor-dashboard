<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsOmsFixtures;
use Tests\TestCase;

/** oms:prune-history: read in-app notifications after 30 days, any after 90, notification logs after 180. */
class PruneHistoryTest extends TestCase
{
    use BuildsOmsFixtures, RefreshDatabase;

    private function notification(User $user, int $daysOld, bool $read): string
    {
        DB::table('notifications')->insert([
            'id' => $id = (string) Str::uuid(), 'type' => 'Filament\\Notifications\\DatabaseNotification', 'notifiable_type' => User::class,
            'notifiable_id' => $user->id, 'data' => '{}', 'read_at' => $read ? now()->subDays($daysOld) : null,
            'created_at' => now()->subDays($daysOld), 'updated_at' => now()->subDays($daysOld),
        ]);

        return $id;
    }

    private function log(int $daysOld): int
    {
        return DB::table('notification_logs')->insertGetId([
            'event' => 'outage_created', 'channel' => 'mail', 'recipient' => 'noc@example.com', 'subject' => 'Outage', 'status' => 'sent', 'attempts' => 1,
            'created_at' => now()->subDays($daysOld), 'updated_at' => now()->subDays($daysOld),
        ]);
    }

    public function test_old_history_is_deleted_and_recent_history_kept(): void
    {
        $this->seedReference();
        $user = $this->makeUser('CX Agent', 'CX');

        $keep = [$this->notification($user, 5, read: true), $this->notification($user, 45, read: false)];
        $gone = [$this->notification($user, 45, read: true), $this->notification($user, 120, read: false)];
        $recentLog = $this->log(100);
        $oldLog = $this->log(200);

        $this->artisan('oms:prune-history', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(4, DB::table('notifications')->count(), 'a dry run deletes nothing');

        $this->artisan('oms:prune-history')->assertSuccessful();

        $this->assertEqualsCanonicalizing($keep, DB::table('notifications')->pluck('id')->all());
        $this->assertSame([$recentLog], DB::table('notification_logs')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotContains($oldLog, DB::table('notification_logs')->pluck('id')->all());
        $this->assertSame([], array_intersect($gone, DB::table('notifications')->pluck('id')->all()));
    }
}
