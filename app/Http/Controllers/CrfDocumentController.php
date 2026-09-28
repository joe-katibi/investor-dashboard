<?php

namespace App\Http\Controllers;

use App\Models\ChangeRequest;
use App\Models\Outage;
use App\Services\Maintenance\CrfPdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** View / download the CRF of a planned maintenance ticket (signed-in users with outage.view). */
class CrfDocumentController extends Controller
{
    public function __invoke(Request $request, Outage $outage, CrfPdf $pdf): Response
    {
        Gate::authorize('viewCrf', $outage);
        $changeRequest = ChangeRequest::query()->where('outage_id', $outage->id)->with('outage')->firstOrFail();
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($pdf->render($changeRequest), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition.'; filename="'.$changeRequest->pdfFileName().'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
