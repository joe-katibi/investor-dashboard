<?php

namespace App\Services\Maintenance;

use App\Models\ChangeRequest;
use Barryvdh\DomPDF\Facade\Pdf;

/** Renders the Maintenance & Change Request Form (CRF) — always from current data, so signatures appear once given. */
class CrfPdf
{
    public function render(ChangeRequest $changeRequest): string
    {
        $changeRequest->loadMissing([
            'outage.olts:id,name', 'outage.fdts:id,name,olt_id', 'outage.services:id,name', 'outage.team:id,name',
            'outage.technician:id,name', 'signatures',
        ]);

        return Pdf::loadView('pdf.crf', [
            'cr' => $changeRequest,
            'outage' => $changeRequest->outage,
            'logo' => $this->logo(),
        ])->setPaper('a4')->output();
    }

    private function logo(): ?string
    {
        $path = public_path('images/oms-logo-128.png');

        return is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : null;
    }
}
