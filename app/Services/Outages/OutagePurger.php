<?php

namespace App\Services\Outages;

use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\NotificationLog;
use App\Models\Outage;
use App\Models\OutageAttachment;
use App\Models\User;
use App\Services\Reports\DashboardMetrics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Empties all outage data for a clean start: every ticket and everything attached to it (topology
 * links, services, status history, updates, SLA / flag events, FIFO queue items, assignments and
 * their history, CX follow-ups, support requests, CRFs with signatures / reschedules / sends,
 * approvals, site photos), the ticket notification log and bell notifications, ticket audit
 * entries and the ticket-number sequences (numbering restarts at 1).
 *
 * Configuration, users, roles, teams, topology, templates and settings are kept.
 */
class OutagePurger
{
    /** @return array<string, int> what would be / was removed */
    public function counts(): array
    {
        return [
            'tickets' => Outage::query()->count(),
            'notification log entries' => NotificationLog::query()->whereNotNull('outage_id')->count(),
            'bell notifications' => DB::table('notifications')->count(),
            'ticket audit entries' => $this->auditQuery()->count(),
            'ticket number sequences' => DB::table('ticket_sequences')->count(),
        ];
    }

    /** @return array<string, int> */
    public function purge(): array
    {
        $counts = $this->counts();

        DB::transaction(function (): void {
            NotificationLog::query()->whereNotNull('outage_id')->delete();
            DB::table('notifications')->delete();
            $this->auditQuery()->delete();

            // Every table below hangs off outages with ON DELETE CASCADE.
            Outage::query()->delete();

            DB::table('ticket_sequences')->delete();
            User::query()->whereNotNull('last_assigned_at')->update(['last_assigned_at' => null]);
        });

        foreach (['outage-photos', 'crf-customer-lists', OutageAttachment::DIRECTORY] as $directory) {
            Storage::disk('local')->deleteDirectory($directory);
        }

        Cache::forget('oms.nav.active-outages');
        Cache::forget('oms.nav.active-4g-outages');
        DashboardMetrics::bump();

        return $counts;
    }

    private function auditQuery()
    {
        return AuditLog::query()->whereIn('auditable_type', [(new Outage)->getMorphClass(), (new ChangeRequest)->getMorphClass()]);
    }
}
