<?php

namespace App\Http\Controllers;

use App\Models\ChangeRequest;
use App\Models\ChangeRequestSignature;
use App\Services\Maintenance\CrfPdf;
use App\Services\Outages\OutageWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Personal CRF sign page (no login: signatories such as the CTO may not have an account). Every
 * route sits behind Laravel's `signed` middleware and also checks the per-signature token, so a
 * link only works for its signatory, until it expires or is re-issued, and only once.
 */
class CrfSignController extends Controller
{
    public function show(Request $request, ChangeRequestSignature $signatory): View
    {
        $this->authorizeToken($request, $signatory);
        $changeRequest = $signatory->changeRequest()->with(['outage.olts:id,name', 'outage.fdts:id,name,olt_id', 'signatures'])->firstOrFail();

        return view('crf.sign', [
            'signatory' => $signatory,
            'cr' => $changeRequest,
            'outage' => $changeRequest->outage,
            'canSign' => $signatory->status === ChangeRequestSignature::PENDING && $changeRequest->status === ChangeRequest::PENDING_SIGNOFF,
            'pdfUrl' => URL::temporarySignedRoute('crf.sign.pdf', now()->addHours(2), ['signatory' => $signatory->id, 'token' => $request->query('token')]),
            'action' => $request->fullUrl(),
        ]);
    }

    public function store(Request $request, ChangeRequestSignature $signatory, OutageWorkflow $workflow): RedirectResponse
    {
        $this->authorizeToken($request, $signatory);

        $data = $request->validate([
            'decision' => ['required', 'in:approve,changes'],
            'signed_name' => ['required', 'string', 'max:150'],
            'signature_image' => ['nullable', 'string', 'max:400000'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $workflow->recordSignature($signatory, $data + ['ip' => $request->ip(), 'user_agent' => $request->userAgent()]);

        // The token is single use: show the confirmation on its own short-lived link.
        return redirect()->to(URL::temporarySignedRoute('crf.sign.done', now()->addHour(), ['signatory' => $signatory->id]));
    }

    public function done(ChangeRequestSignature $signatory): View
    {
        return view('crf.sign-done', ['signatory' => $signatory->load('changeRequest.outage')]);
    }

    public function pdf(Request $request, ChangeRequestSignature $signatory, CrfPdf $pdf): Response
    {
        $this->authorizeToken($request, $signatory);
        $changeRequest = $signatory->changeRequest()->with('outage')->firstOrFail();

        return response($pdf->render($changeRequest), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$changeRequest->pdfFileName().'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function authorizeToken(Request $request, ChangeRequestSignature $signatory): void
    {
        if ($signatory->tokenMatches($request->query('token'))) {
            return;
        }

        abort($signatory->status === ChangeRequestSignature::PENDING ? 403 : 410, $signatory->status === ChangeRequestSignature::PENDING
            ? 'This sign link is no longer valid. Ask NOC to re-send the sign-off request.'
            : 'This CRF has already been signed with this link.');
    }
}
