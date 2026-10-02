<?php

namespace App\Filament\Resources\MobilityOutages\Pages;

use App\Filament\Resources\MobilityOutages\MobilityOutageResource;
use App\Services\Outages\OutageWorkflow;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditMobilityOutage extends EditRecord
{
    protected static string $resource = MobilityOutageResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        return array_merge($data, [
            'enodeb_ids' => $record->enodebs()->pluck('enodebs.id')->all(),
            'node_ids' => $record->transmissionNodes()->pluck('transmission_nodes.id')->all(),
            'service_ids' => $record->services()->pluck('services.id')->all(),
        ]);
    }

    /** Edits go through the workflow so severity, SLA and the audit trail stay consistent. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(OutageWorkflow::class)->updateMobility($record, $data, Auth::user());
    }

    protected function getRedirectUrl(): string
    {
        return MobilityOutageResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
