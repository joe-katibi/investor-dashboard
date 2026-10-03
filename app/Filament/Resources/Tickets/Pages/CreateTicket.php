<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\TicketResource;
use App\Services\Outages\OutageWorkflow;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateTicket extends CreateRecord
{
    protected static string $resource = TicketResource::class;

    protected static ?string $title = 'Raise customer ticket';

    /** Creation goes through the workflow: customer lookup, ticket number, SLA, outage check and routing to the first desk. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(OutageWorkflow::class)->createTicket($data, Auth::user());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        $record = $this->getRecord();

        return 'Ticket '.$record->ticket_number.' raised — '.($record->current_queue?->getLabel() ?? $record->status?->name);
    }

    protected function getRedirectUrl(): string
    {
        return TicketResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
