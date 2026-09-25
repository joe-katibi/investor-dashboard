<?php

namespace App\Filament\Resources\Outages\Pages;

use App\Filament\Resources\Outages\OutageResource;
use App\Services\Outages\OutageWorkflow;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditOutage extends EditRecord
{
    protected static string $resource = OutageResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        return array_merge($data, [
            'olt_ids' => $record->olts()->pluck('olts.id')->all(),
            'fdt_ids' => $record->fdts()->pluck('fdts.id')->all(),
            'slot_ids' => $record->slots()->pluck('slots.id')->all(),
            'port_ids' => $record->ports()->pluck('ports.id')->all(),
            'service_ids' => $record->services()->pluck('services.id')->all(),
        ]);
    }

    /** Edits go through the workflow so priority, SLA, FIFO order and audit stay consistent. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(OutageWorkflow::class)->update($record, $data, Auth::user());
    }

    protected function getRedirectUrl(): string
    {
        return OutageResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
