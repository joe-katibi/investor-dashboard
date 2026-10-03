<?php

namespace App\Filament\Resources\SiteVisits\Pages;

use App\Filament\Resources\SiteVisits\SiteVisitResource;
use App\Services\Outages\OutageWorkflow;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateSiteVisit extends CreateRecord
{
    protected static string $resource = SiteVisitResource::class;

    protected static ?string $title = 'Book site visit';

    /** Creation goes through the workflow: customer lookup, visit number (INS-1, SUP-1…), SLA, outage check and the dispatch queue. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(OutageWorkflow::class)->createSiteVisit($data, Auth::user());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        $record = $this->getRecord();

        return 'Site visit '.$record->ticket_number.' booked — '.$record->status?->name;
    }

    protected function getRedirectUrl(): string
    {
        return SiteVisitResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
