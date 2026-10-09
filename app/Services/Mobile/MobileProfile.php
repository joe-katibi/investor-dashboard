<?php

namespace App\Services\Mobile;

use App\Enums\QueueType;
use App\Models\Outage;
use App\Models\OutageType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may use the mobile app, as what, and which tickets they may see there.
 *
 * The app is for the field side only: dispatchers, technicians and 4G engineers. What someone is
 * comes from their permissions — never from anything the phone says. A dispatcher sees the tickets
 * of the desks they dispatch; a technician or engineer only the tickets they are, or were, on.
 */
final class MobileProfile
{
    public const DISPATCH = 'dispatch';

    public const TECHNICIAN = 'technician';

    public const ENGINEER = 'engineer';

    public const LABELS = [self::DISPATCH => 'Dispatcher', self::TECHNICIAN => 'Technician', self::ENGINEER => 'Engineer'];

    /** The permission that makes someone a dispatcher => the queues they then manage (waiting to be dispatched, out in the field). */
    private const DISPATCH_DESKS = [
        'outage.dispatch' => [QueueType::Infrastructure, QueueType::Technician],
        'visit.dispatch' => [QueueType::VisitDispatch, QueueType::VisitTechnician],
        'queue.work_infra_support_dispatch' => [QueueType::InfraSupportDispatch, QueueType::InfraSupportTechnician],
    ];

    /** Queues where a ticket waits for the dispatcher to act. */
    public const AWAITING_DISPATCH = [QueueType::Infrastructure, QueueType::VisitDispatch, QueueType::InfraSupportDispatch];

    /** Being given field work makes someone a technician (or, for 4G transmission, an engineer). */
    private const FIELD_PERMISSIONS = [
        'queue.work_technician', 'queue.work_support', 'queue.work_visit_technician', 'queue.work_infra_support_tech', 'queue.work_mobility_engineer',
    ];

    /** dispatch | technician | engineer — or null: this account has no business in the mobile app. */
    public static function role(User $user): ?string
    {
        if (self::dispatchQueues($user) !== []) {
            return self::DISPATCH;
        }

        foreach (self::FIELD_PERMISSIONS as $permission) {
            if ($user->can($permission)) {
                return $user->can('queue.work_mobility_engineer') && $user->can(OutageType::handlerPermission(OutageType::DOMAIN_TRANSMISSION))
                    ? self::ENGINEER
                    : self::TECHNICIAN;
            }
        }

        return null;
    }

    /** @return list<QueueType> the queues this person dispatches (empty: not a dispatcher) */
    public static function dispatchQueues(User $user): array
    {
        $queues = [];

        foreach (self::DISPATCH_DESKS as $permission => $desks) {
            if ($user->can($permission)) {
                array_push($queues, ...$desks);
            }
        }

        return $queues;
    }

    /** Dispatchers see every ticket of the kinds they dispatch; field staff only their own. */
    public static function canView(User $user, Outage $outage): bool
    {
        if (! $user->is_active || self::role($user) === null || ! $user->can('view', $outage)) {
            return false;
        }

        return self::visible(Outage::query(), $user)->whereKey($outage->id)->exists();
    }

    /** Limit a ticket query to what this person may see in the app. */
    public static function visible(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $workflow = $query->qualifyColumn('workflow');

            // Their own work: tickets they hold or held, or are / were the technician of.
            $query->where($query->qualifyColumn('technician_id'), $user->id)
                ->orWhereHas('assignments', fn (Builder $assignment) => $assignment->where('user_id', $user->id))
                // …and maintenance they raised on site themselves, also while it waits for its approvals.
                ->orWhere(fn (Builder $own) => $own->where($query->qualifyColumn('created_by'), $user->id)->whereNotNull($query->qualifyColumn('maintenance_kind')));

            if ($user->can('outage.dispatch') && $user->can('outage.view')) {
                $query->orWhereNull($workflow);
            }

            if (($user->can('visit.dispatch') || $user->can('queue.work_infra_support_dispatch')) && $user->can('ticket.view')) {
                $query->orWhereIn($workflow, OutageType::TICKET_WORKFLOWS);
            }
        });
    }

    /** @return array<string, mixed> what the app shows on the profile screen and routes on */
    public static function describe(User $user): array
    {
        $user->loadMissing(['department:id,name', 'teams:id,name', 'roles:id,name']);
        $role = self::role($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'phone' => $user->phone,
            'department' => $user->department?->name,
            'teams' => $user->teams->pluck('name')->values()->all(),
            'roles' => $user->roles->pluck('name')->values()->all(),
            'mobile_role' => $role,
            'mobile_role_label' => self::LABELS[$role] ?? null,
            'availability' => [
                'status' => $user->availability_status?->value,
                'label' => $user->availability_status?->getLabel(),
            ],
        ];
    }
}
