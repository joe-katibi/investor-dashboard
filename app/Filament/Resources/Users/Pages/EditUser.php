<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Support\ActivityLog;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** The roles and teams before saving: what was added or taken away goes on the audit trail. */
    private array $membershipsBefore = [];

    protected function beforeSave(): void
    {
        $this->membershipsBefore = ActivityLog::memberships($this->getRecord());
    }

    protected function afterSave(): void
    {
        ActivityLog::membershipsChanged($this->getRecord(), $this->membershipsBefore);
    }
}
