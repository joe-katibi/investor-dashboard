@php
    /** @var \App\Models\ChangeRequest $cr */
    $box = fn (bool $on) => $on ? '&#9745;' : '&#9744;';
    $checklist = $cr->checklist ?? [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $cr->pdfFileName() }}</title>
<style>
    @page { margin: 34px 44px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2937; }
    .center { text-align: center; }
    .logo { width: 52px; height: 52px; }
    h1 { color: #c2410c; font-size: 19px; margin: 8px 0 2px; letter-spacing: .3px; }
    .sub { font-style: italic; color: #6b7280; font-size: 9.5px; margin-bottom: 14px; }
    h2 { color: #1d6fa5; font-size: 12.5px; margin: 16px 0 6px; }
    table { width: 100%; border-collapse: collapse; }
    .details td { padding: 6px 4px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
    .label { color: #1d6fa5; font-weight: bold; width: 18%; }
    .value { width: 32%; }
    .tiers td { width: 25%; vertical-align: top; padding: 4px 6px 4px 0; }
    .tier { color: #1d6fa5; font-weight: bold; }
    .tier.on { color: #b45309; }
    .hint { font-style: italic; color: #6b7280; font-size: 9px; }
    .scope td { padding: 6px 4px; vertical-align: top; }
    .scope .label { width: 30%; }
    .grid th { background: #1e3a5f; color: #fff; text-align: left; padding: 7px 6px; font-size: 10.5px; }
    .grid td { padding: 7px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: middle; }
    .yes { color: #1d6fa5; font-weight: bold; }
    .sig { height: 38px; max-width: 170px; }
    .signed-name { font-size: 9.5px; color: #374151; }
    .approved { color: #15803d; font-weight: bold; }
    .pending { color: #9ca3af; }
    .changes { color: #b91c1c; font-weight: bold; }
    .footer { margin-top: 16px; font-size: 8.5px; color: #9ca3af; }
    .page-break { page-break-before: always; }
</style>
</head>
<body>
    <div class="center">
        @if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif
        <h1>MAINTENANCE &amp; CHANGE REQUEST FORM (CRF)</h1>
        <div class="sub">Standard Operational Procedure | Network Operations Center &amp; Infrastructure</div>
    </div>

    <h2>1. GENERAL MAINTENANCE DETAILS</h2>
    <table class="details">
        <tr>
            <td class="label">Tracking No:</td><td class="value">{{ $outage->ticket_number }}</td>
            <td class="label">Initiator:</td><td class="value">{{ $cr->initiator }}</td>
        </tr>
        <tr>
            <td class="label">Title / Subject:</td><td class="value">{{ $outage->title }}</td>
            <td class="label">Department:</td><td class="value">{{ $cr->department }}</td>
        </tr>
        <tr>
            <td class="label">Scheduled Date:</td><td class="value">{{ $cr->scheduled_start->format('d/m/Y') }}</td>
            <td class="label">In-Charge / Lead:</td><td class="value">{{ $cr->in_charge }}</td>
        </tr>
        <tr>
            <td class="label">Start Time:</td><td class="value">{{ $cr->scheduled_start->format('H:i') }} Hrs</td>
            <td class="label">End Time:</td>
            <td class="value">{{ $cr->scheduled_end->format('H:i') }} Hrs{{ $cr->scheduled_end->isSameDay($cr->scheduled_start) ? '' : ' ('.$cr->scheduled_end->format('d/m/Y').')' }}</td>
        </tr>
        <tr>
            <td class="label">Expected Downtime:</td><td class="value">{{ $cr->expectedDowntime() }}</td>
            <td class="label">Escalation Time:</td><td class="value">{{ $cr->escalation_time }}</td>
        </tr>
        <tr>
            <td class="label">Rollback Time:</td><td class="value">{{ $cr->rollback_time }}</td>
            <td class="label">Emergency Contact:</td><td class="value">{{ $cr->emergency_contact }}</td>
        </tr>
        <tr>
            <td class="label">Affected Area:</td><td class="value">{{ $outage->topologyPath() ?: $outage->location }}</td>
            <td class="label">Customers Affected:</td><td class="value">{{ number_format($outage->customers_affected) }}</td>
        </tr>
    </table>

    <h2>2. RISK RATING &amp; CLASSIFICATION</h2>
    <div class="hint" style="margin-bottom:6px;">Select the appropriate risk tier for this maintenance activity:</div>
    <table class="tiers">
        <tr>
            @foreach (\App\Models\ChangeRequest::RISK_TIERS as $tier => [$label, $hint])
                <td>
                    <span class="tier {{ $cr->risk_tier === $tier ? 'on' : '' }}">{!! $box($cr->risk_tier === $tier) !!} {{ $label }}</span><br>
                    <span class="hint">{{ $hint }}</span>
                </td>
            @endforeach
        </tr>
    </table>

    <h2>3. TECHNICAL SCOPE &amp; FALLBACK PLANNING</h2>
    <table class="scope">
        <tr><td class="label">Detail / Description of Work:</td><td>{!! nl2br(e($cr->work_description)) !!}</td></tr>
        <tr><td class="label">Recovery &amp; Fallback Plan:</td><td>{!! nl2br(e($cr->fallback_plan)) !!}</td></tr>
        <tr><td class="label">Prior Test Details:</td><td>{!! nl2br(e($cr->prior_tests)) !!}</td></tr>
        <tr><td class="label">Identified Risks &amp; Mitigation:</td><td>{!! nl2br(e($cr->risks_mitigation)) !!}</td></tr>
    </table>

    <h2>4. OPERATIONAL CHECKLIST &amp; PRE-REQUISITES</h2>
    <table class="grid">
        <tr><th style="width:14%">Status</th><th>Verification Item</th><th style="width:36%">Target Audience / Details</th></tr>
        @foreach (\App\Models\ChangeRequest::CHECKLIST as $key => [$item, $audience])
            <tr>
                <td class="yes">{!! $box(! empty($checklist[$key])) !!} YES</td>
                <td>{{ $item }}</td>
                <td>{{ $audience }}{{ $key === 'customers_identified' && $cr->customer_list_name ? ' — '.$cr->customer_list_name : '' }}</td>
            </tr>
        @endforeach
    </table>

    <h2>5. POST-MAINTENANCE OUTCOME &amp; SUCCESS DETAIL</h2>
    <table class="scope">
        <tr>
            <td class="label">Final Status:</td>
            <td>
                @foreach (\App\Models\ChangeRequest::FINAL_STATUSES as $status => $label)
                    <span style="margin-right:14px;">{!! $box($cr->final_status === $status) !!} <strong>{{ $label }}</strong></span>
                @endforeach
            </td>
        </tr>
        <tr>
            <td class="label">Success / Activity Details:</td>
            <td>{!! $cr->outcome_details ? nl2br(e($cr->outcome_details)) : '<span class="hint">Record post-execution results, metrics, or follow-up action items…</span>' !!}</td>
        </tr>
    </table>

    <h2>6. SIGN-OFFS &amp; AUTHORIZATIONS</h2>
    <table class="grid">
        <tr><th style="width:26%">Role / Authority</th><th>Name &amp; Signature</th><th style="width:16%">Date</th><th style="width:20%">Approval Status</th></tr>
        @forelse ($cr->signatures as $signature)
            <tr>
                <td style="color:#1d6fa5;">{{ $signature->role_label }}</td>
                <td>
                    @if ($signature->status === \App\Models\ChangeRequestSignature::APPROVED && $signature->signature_image)
                        <img class="sig" src="{{ $signature->signature_image }}" alt=""><br>
                    @endif
                    <span class="signed-name">{{ $signature->signed_name ?: $signature->displayName() }}</span>
                </td>
                <td>{{ $signature->signed_at?->format('d/m/Y') }}</td>
                <td>
                    @if ($signature->status === \App\Models\ChangeRequestSignature::APPROVED)
                        <span class="approved">&#9745; Approved</span>
                    @elseif ($signature->status === \App\Models\ChangeRequestSignature::CHANGES_REQUESTED)
                        <span class="changes">&#9746; Changes requested</span>
                    @else
                        <span class="pending">&#9744; Approved</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="hint">Signatories are added when NOC sends the CRF for sign-off.</td></tr>
        @endforelse
    </table>

    <div class="footer">
        {{ config('app.name') }} · {{ $outage->ticket_number }} · CRF status: {{ $cr->statusLabel() }}
        @if ($cr->reschedule_count) · Rescheduled {{ $cr->reschedule_count }}× @endif
        · Generated {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>
