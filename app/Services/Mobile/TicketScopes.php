<?php

namespace App\Services\Mobile;

use App\Enums\AssignmentStatus;
use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Models\Outage;
use App\Models\OutageStatus;
use App\Models\Priority;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The named lists of the mobile app ("New assignments", "Awaiting dispatch", …). A dashboard tile
 * and the list behind it use the same scope, so the number on the tile is the number of rows.
 * Every scope is cut down to what the person may see (MobileProfile::visible) first.
 */
final class TicketScopes
{
    /** Status codes that mean "done in the field, waiting for someone to confirm". */
    private const PENDING_CODES = ['restore_reported', 'mobility_resolved_pending_noc', 'visit_pending_verification', 'visit_pending_confirmation'];

    /** @return array<string, string> scope => label, in dashboard order */
    public static function labels(?string $role): array
    {
        return $role === MobileProfile::DISPATCH
            ? [
                'awaiting' => 'Awaiting dispatch',
                'mine' => 'Assigned to me',
                'field' => 'In the field',
                'pending' => 'Pending confirmation',
                'critical' => 'Critical',
                'breached' => 'Past SLA',
                'open' => 'All open',
                'done' => 'Closed (7 days)',
            ]
            : [
                'new' => 'New assignments',
                'active' => 'In progress',
                'mine' => 'My tickets',
                'pending' => 'Pending confirmation',
                'critical' => 'Critical',
                'done' => 'Completed (30 days)',
            ];
    }

    public static function apply(Builder $query, User $user, string $scope): Builder
    {
        $query = MobileProfile::visible($query, $user);
        $dispatcher = MobileProfile::role($user) === MobileProfile::DISPATCH;
        $open = fn (Builder $q) => $q->where($q->qualifyColumn('is_closed'), false);
        $holding = fn (Builder $q, array $statuses) => $q->whereHas('assignments', fn (Builder $a) => $a->where('user_id', $user->id)->whereIn('status', $statuses));
        $queues = fn (array $queues) => array_map(fn (QueueType $queue) => $queue->value, $queues);
        $pending = fn () => OutageStatus::query()->whereIn('code', self::PENDING_CODES)->select('id');

        return match ($scope) {
            'new' => $holding($query, [AssignmentStatus::Assigned->value]),
            'active' => $holding($query, [AssignmentStatus::Accepted->value]),
            'mine' => $holding($query, AssignmentStatus::activeValues()),

            'awaiting' => $open($query)
                ->whereIn('current_queue', $queues(array_values(array_filter(
                    MobileProfile::dispatchQueues($user),
                    fn (QueueType $queue) => in_array($queue, MobileProfile::AWAITING_DISPATCH, true),
                ))) ?: ['-'])
                ->whereNotIn('status_id', $pending()),

            'field' => $open($query)
                ->whereIn('current_queue', $queues(array_values(array_filter(
                    MobileProfile::dispatchQueues($user),
                    fn (QueueType $queue) => ! in_array($queue, MobileProfile::AWAITING_DISPATCH, true),
                ))) ?: ['-']),

            // Field staff: what they finished and is not confirmed yet. Dispatchers: everything waiting to be confirmed.
            'pending' => $open($query)->whereIn('status_id', $pending())
                ->when(! $dispatcher, fn (Builder $q) => $q->where(fn (Builder $mine) => $mine->where('technician_id', $user->id)->orWhere('restored_by', $user->id))),

            'critical' => $open($query)
                ->whereIn('priority_id', Priority::query()->whereIn('code', Priority::CRITICAL_CODES)->select('id'))
                ->when(! $dispatcher, fn (Builder $q) => $holding($q, AssignmentStatus::activeValues())),

            'breached' => $open($query)->where(fn (Builder $q) => $q
                ->where('sla_state', SlaState::Breached->value)
                ->orWhere(fn (Builder $late) => $late->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())->whereNull('sla_paused_at'))),

            'done' => $query->where('is_closed', true)->where('updated_at', '>=', now()->subDays($dispatcher ? 7 : 30)),

            // "open" and anything unknown: every open ticket the person may see.
            default => $open($query),
        };
    }

    /** The newest work first for field staff; for the lists a dispatcher watches, the oldest SLA deadline first. */
    public static function order(Builder $query, string $scope): Builder
    {
        return match ($scope) {
            'done' => $query->orderByDesc('updated_at'),
            'awaiting', 'field', 'pending', 'breached', 'critical', 'open' => $query->orderByRaw('case when sla_due_at is null then 1 else 0 end')->orderBy('sla_due_at')->orderByDesc('id'),
            default => $query->orderByDesc('updated_at')->orderByDesc('id'),
        };
    }

    public static function module(Builder $query, ?string $module): Builder
    {
        return match ($module) {
            'site_visit' => $query->siteVisits(),
            'customer_ticket' => $query->cxTickets(),
            'mobility' => $query->mobility(),
            'outage' => $query->whereNull($query->qualifyColumn('workflow')),
            default => $query,
        };
    }

    public static function priority(Builder $query, ?string $level): Builder
    {
        $codes = match ($level) {
            'critical' => Priority::CRITICAL_CODES,
            'major' => Priority::MAJOR_CODES,
            default => null,
        };

        return $codes ? $query->whereIn('priority_id', Priority::query()->whereIn('code', $codes)->select('id')) : $query;
    }

    /** Ticket number, title, place or customer. */
    public static function search(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        // "%" and "\" are dropped rather than escaped: escaping differs between MySQL, PostgreSQL and SQLite.
        $like = '%'.str_replace(['\\', '%'], '', mb_strtolower(mb_substr($term, 0, 80))).'%';

        return $query->where(function (Builder $q) use ($like): void {
            foreach (['ticket_number', 'title', 'location'] as $column) {
                $q->orWhereRaw('lower('.$q->qualifyColumn($column).') like ?', [$like]);
            }

            $q->orWhereHas('ticket', fn (Builder $ticket) => $ticket
                ->whereRaw('lower(customer_name) like ?', [$like])
                ->orWhereRaw('lower(account_number) like ?', [$like]));
        });
    }

    public static function count(User $user, string $scope): int
    {
        return self::apply(Outage::query(), $user, $scope)->count();
    }
}
