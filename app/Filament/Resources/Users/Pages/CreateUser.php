<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Support\ActivityLog;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** The roles and teams a new account starts with are on the audit trail. */
    protected function afterCreate(): void
    {
        ActivityLog::membershipsChanged($this->getRecord(), []);
    }
}
