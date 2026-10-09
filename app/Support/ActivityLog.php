<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Outage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;

/**
 * Audit entries for things that are not a change of one auditable record: signing in and out,
 * who was given which role, which permissions a role holds, exports and imports. Written to the
 * same append-only audit trail as everything else (Monitoring → Audit Trail).
 */
final class ActivityLog
{
    /**
     * @param  int|false|null  $userId  who did it: null = the signed-in person, false = nobody (the system, or someone not signed in)
     */
    public static function record(Model|string $subject, string $event, array $new = [], array $old = [], int|false|null $userId = null, int $subjectId = 0): void
    {
        $request = app()->runningInConsole() ? null : Request::instance();

        AuditLog::query()->create([
            'auditable_type' => $subject instanceof Model ? $subject->getMorphClass() : (new $subject)->getMorphClass(),
            'auditable_id' => $subject instanceof Model ? $subject->getKey() : $subjectId,
            'event' => $event,
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'user_id' => $userId === false ? null : ($userId ?? Auth::id()),
            'trigger' => AuditContext::trigger(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'url' => $request ? mb_substr($request->fullUrl(), 0, 500) : null,
            'created_at' => now(),
        ]);
    }

    /** One "opened" entry per person and ticket in this many minutes: reloading a page is not a new visit. */
    public const VIEW_INTERVAL_MINUTES = 15;

    /**
     * Someone opened a ticket (its page on the website, or the ticket in the mobile app). Can be
     * switched off under FIFO & SLA Settings → Audit trail.
     */
    public static function viewed(?Outage $outage, ?User $user): void
    {
        if (! $outage || ! $user || ! app(Settings::class)->bool('audit.ticket_views')) {
            return;
        }

        // Cache::add answers false while an earlier visit of this person to this ticket is still fresh.
        if (! Cache::add("oms:viewed:{$user->id}:{$outage->id}:".AuditContext::trigger(), true, now()->addMinutes(self::VIEW_INTERVAL_MINUTES))) {
            return;
        }

        $outage->loadMissing('status');
        self::record($outage, 'viewed', ['ticket' => $outage->ticket_number, 'status' => $outage->status?->name, 'person' => $user->name], [], $user->id);
    }

    /** @return array{roles: list<string>, teams: list<string>} the roles and teams a person has right now */
    public static function memberships(User $user): array
    {
        return [
            'roles' => $user->roles()->orderBy('name')->pluck('name')->all(),
            'teams' => $user->teams()->orderBy('teams.name')->pluck('teams.name')->all(),
        ];
    }

    /** Compare with an earlier {@see memberships()} and record what was added and taken away. */
    public static function membershipsChanged(User $user, array $before): void
    {
        $after = self::memberships($user);

        foreach (['roles' => 'roles_changed', 'teams' => 'teams_changed'] as $kind => $event) {
            $added = array_values(array_diff($after[$kind], $before[$kind] ?? []));
            $removed = array_values(array_diff($before[$kind] ?? [], $after[$kind]));

            if ($added !== [] || $removed !== []) {
                self::record($user, $event, array_filter([
                    'person' => $user->name, 'added' => implode(', ', $added) ?: null, 'removed' => implode(', ', $removed) ?: null,
                    'now' => implode(', ', $after[$kind]) ?: 'none',
                ]), [$kind => implode(', ', $before[$kind] ?? []) ?: 'none']);
            }
        }
    }
}
