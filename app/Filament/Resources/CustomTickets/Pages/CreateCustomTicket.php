<?php

namespace App\Filament\Resources\CustomTickets\Pages;

use App\Filament\Resources\CustomTickets\CustomTicketResource;
use App\Models\CustomFlow;
use App\Services\Outages\OutageWorkflow;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateCustomTicket extends CreateRecord
{
    protected static string $resource = CustomTicketResource::class;

    protected static ?string $title = 'Raise workflow ticket';

    /** Through the workflow service: ticket number, the copy of the flow's steps, SLA, and the queue of the first step. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(OutageWorkflow::class)->raiseCustomTicket(CustomFlow::query()->findOrFail($data['custom_flow_id']), $data, Auth::user());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        $record = $this->getRecord();

        return $record->ticket_number.' raised — '.$record->customTicket?->progressLabel();
    }

    protected function getRedirectUrl(): string
    {
        return CustomTicketResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
