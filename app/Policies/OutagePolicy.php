<?php

namespace App\Policies;

use App\Enums\StatusCode;
use App\Models\MajorOutageType;
use App\Models\Outage;
use App\Models\OutageStatus;
use App\Models\User;

/**
 * Permission + state checks for every workflow transition (spec §9: transitions are
 * permission-controlled). The same policy guards the UI and the workflow service.
 */
class OutagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('outage.view');
    }

    public function view(User $user, Outage $outage): bool
    {
        return $user->can('outage.view');
    }

    public function create(User $user): bool
    {
        return $user->can('outage.create');
    }

    public function update(User $user, Outage $outage): bool
    {
        return $user->can('outage.edit') && ! $outage->is_closed;
    }

    public function delete(User $user, Outage $outage): bool
    {
        return $user->can('outage.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('outage.delete');
    }

    /** NOC confirm / reject. */
    public function confirm(User $user, Outage $outage): bool
    {
        return $user->can('outage.confirm')
            && ! $outage->is_closed
            && $this->statusIs($outage, StatusCode::SupportUnconfirmed);
    }

    public function dispatch(User $user, Outage $outage): bool
    {
        return $user->can('outage.dispatch')
            && ! $outage->is_closed
            && $outage->noc_confirmed_at !== null
            && MajorOutageType::flags($outage->major_outage_type_id)['requires_infrastructure']
            && ! $this->statusIs($outage, StatusCode::RestoreReported, StatusCode::NocRestoreConfirmed);
    }

    public function addUpdate(User $user, Outage $outage): bool
    {
        return $outage->is_closed ? $user->can('outage.edit') : $user->can('outage.update_progress');
    }

    public function reportRestored(User $user, Outage $outage): bool
    {
        $ready = MajorOutageType::flags($outage->major_outage_type_id)['requires_infrastructure']
            ? $outage->dispatched_at !== null
            : $outage->noc_confirmed_at !== null;

        return $user->can('outage.update_restoration')
            && ! $outage->is_closed
            && $ready
            && ! $this->statusIs($outage, StatusCode::RestoreReported, StatusCode::NocRestoreConfirmed, StatusCode::SupportUnconfirmed);
    }

    public function confirmRestoration(User $user, Outage $outage): bool
    {
        return $user->can('outage.confirm_restoration')
            && ! $outage->is_closed
            && $this->statusIs($outage, StatusCode::RestoreReported);
    }

    public function resolve(User $user, Outage $outage): bool
    {
        return $user->can('outage.close')
            && ! $outage->is_closed
            && $this->statusIs($outage, StatusCode::NocRestoreConfirmed);
    }

    public function changeStatus(User $user, Outage $outage): bool
    {
        return $user->can('outage.change_status') && ! $outage->is_closed;
    }

    public function updateFlags(User $user, Outage $outage): bool
    {
        return $user->can('outage.update_credit_sms');
    }

    public function overrideAssignment(User $user, Outage $outage): bool
    {
        return $user->can('queue.override') && ! $outage->is_closed;
    }

    private function statusIs(Outage $outage, StatusCode ...$codes): bool
    {
        // Resolve from status_id (never a possibly stale loaded relation).
        $code = OutageStatus::codeForId($outage->status_id);

        return in_array($code, array_map(fn (StatusCode $c) => $c->value, $codes), true);
    }
}
