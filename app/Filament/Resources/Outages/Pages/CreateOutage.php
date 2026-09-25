<?php

namespace App\Filament\Resources\Outages\Pages;

use App\Filament\Resources\Outages\OutageResource;
use App\Services\Outages\OutageWorkflow;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateOutage extends CreateRecord
{
    protected static string $resource = OutageResource::class;

    protected static ?string $title = 'Report outage';

    /** Creation goes through the workflow: ticket number, priority, SLA, NOC FIFO routing. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(OutageWorkflow::class)->create($data, Auth::user());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Outage '.$this->getRecord()->ticket_number.' created and routed to NOC';
    }

    protected function getRedirectUrl(): string
    {
        return OutageResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
