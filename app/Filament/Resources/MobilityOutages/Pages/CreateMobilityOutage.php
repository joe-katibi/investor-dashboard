<?php

namespace App\Filament\Resources\MobilityOutages\Pages;

use App\Filament\Resources\MobilityOutages\MobilityOutageResource;
use App\Services\Outages\OutageWorkflow;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateMobilityOutage extends CreateRecord
{
    protected static string $resource = MobilityOutageResource::class;

    protected static ?string $title = 'Report 4G outage';

    /** Creation goes through the workflow: ticket number, severity, SLA, engineer / vendor and the vendor e-mail. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(OutageWorkflow::class)->createMobility($data, Auth::user());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        $record = $this->getRecord();

        return '4G ticket '.$record->ticket_number.' created — '.$record->status?->name;
    }

    protected function getRedirectUrl(): string
    {
        return MobilityOutageResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
