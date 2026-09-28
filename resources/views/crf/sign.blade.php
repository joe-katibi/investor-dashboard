<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ config('app.name') }} | Sign CRF {{ $outage->ticket_number }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <style>
        :root { --brand: #d97706; --ink: #1f2937; --muted: #6b7280; --line: #e5e7eb; --bg: #f4f5f7; --blue: #1d6fa5; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--ink); font: 15px/1.5 "Segoe UI", Arial, sans-serif; }
        .wrap { max-width: 860px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        header img { width: 44px; height: 44px; }
        h1 { font-size: 20px; margin: 0; color: #c2410c; }
        h2 { font-size: 15px; margin: 0 0 10px; color: var(--blue); text-transform: uppercase; letter-spacing: .3px; }
        .muted { color: var(--muted); font-size: 13px; }
        dl { display: grid; grid-template-columns: 170px 1fr; gap: 6px 14px; margin: 0; }
        dt { color: var(--blue); font-weight: 600; font-size: 13px; }
        dd { margin: 0; white-space: pre-line; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid var(--line); }
        th { background: #1e3a5f; color: #fff; font-weight: 600; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .ok { background: #dcfce7; color: #166534; } .wait { background: #f3f4f6; color: #4b5563; } .no { background: #fee2e2; color: #991b1b; }
        .btn { display: inline-block; border: 0; border-radius: 8px; padding: 11px 18px; font-weight: 700; font-size: 15px; cursor: pointer; text-decoration: none; }
        .btn-primary { background: var(--brand); color: #fff; } .btn-light { background: #fff; color: var(--ink); border: 1px solid var(--line); }
        .btn-danger { background: #fff; color: #b91c1c; border: 1px solid #fecaca; }
        label { display: block; font-weight: 600; margin: 12px 0 6px; }
        input[type=text], textarea { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 8px; font: inherit; }
        .pad { border: 2px dashed #cbd5e1; border-radius: 8px; background: #fff; touch-action: none; width: 100%; height: 180px; display: block; }
        .row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 14px; }
        .errors { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; }
        .hidden { display: none; }
        @media (max-width: 600px) { dl { grid-template-columns: 1fr; } dt { margin-top: 6px; } .card { padding: 14px; } }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <img src="{{ asset('images/oms-logo-128.png') }}" alt="">
        <div>
            <h1>Maintenance &amp; Change Request Form (CRF)</h1>
            <div class="muted">{{ $outage->ticket_number }} · Signing as <strong>{{ $signatory->role_label }}</strong> — {{ $signatory->displayName() }}</div>
        </div>
    </header>

    <div class="card">
        <h2>{{ $outage->title }}</h2>
        <dl>
            <dt>Scheduled window</dt><dd>{{ $cr->scheduled_start->format('d/m/Y H:i') }} – {{ $cr->scheduled_end->isSameDay($cr->scheduled_start) ? $cr->scheduled_end->format('H:i') : $cr->scheduled_end->format('d/m/Y H:i') }} Hrs ({{ $cr->expectedDowntime() }})</dd>
            <dt>Affected area</dt><dd>{{ $outage->topologyPath() ?: ($outage->location ?: '—') }}</dd>
            <dt>Customers affected</dt><dd>{{ number_format($outage->customers_affected) }}</dd>
            <dt>Risk</dt><dd>{{ $cr->riskLabel() }}</dd>
            <dt>Initiator / Lead</dt><dd>{{ $cr->initiator }} · {{ $cr->in_charge ?: '—' }} · {{ $cr->emergency_contact ?: '—' }}</dd>
            <dt>Description of work</dt><dd>{{ $cr->work_description }}</dd>
            <dt>Fallback plan</dt><dd>{{ $cr->fallback_plan ?: '—' }}</dd>
            <dt>Risks &amp; mitigation</dt><dd>{{ $cr->risks_mitigation ?: '—' }}</dd>
        </dl>
        <div class="row">
            <a class="btn btn-light" href="{{ $pdfUrl }}" target="_blank" rel="noopener">View full CRF (PDF)</a>
        </div>
    </div>

    <div class="card">
        <h2>Sign-offs</h2>
        <table>
            <tr><th>Role / Authority</th><th>Name</th><th>Status</th></tr>
            @foreach ($cr->signatures as $row)
                <tr>
                    <td>{{ $row->role_label }}</td>
                    <td>{{ $row->signed_name ?: $row->displayName() }}</td>
                    <td>
                        @if ($row->status === \App\Models\ChangeRequestSignature::APPROVED)
                            <span class="badge ok">Approved {{ $row->signed_at?->format('d/m/Y') }}</span>
                        @elseif ($row->status === \App\Models\ChangeRequestSignature::CHANGES_REQUESTED)
                            <span class="badge no">Changes requested</span>
                        @else
                            <span class="badge wait">Awaiting</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>

    <div class="card">
        @if (! $canSign)
            <h2>Signature</h2>
            <p>This CRF is not awaiting your signature any more ({{ $cr->statusLabel() }}).</p>
        @else
            <h2>Your decision</h2>
            @if ($errors->any())
                <div class="errors">{{ implode(' ', $errors->all()) }}</div>
            @endif
            <form method="POST" action="{{ $action }}" id="sign-form">
                @csrf
                <input type="hidden" name="decision" id="decision" value="approve">
                <input type="hidden" name="signature_image" id="signature_image">

                <label for="signed_name">Full name</label>
                <input type="text" id="signed_name" name="signed_name" maxlength="150" required value="{{ old('signed_name', $signatory->user?->name ?? $signatory->name) }}">

                <div id="approve-block">
                    <label>Signature <span class="muted">(draw with your mouse or finger)</span></label>
                    <canvas class="pad" id="pad"></canvas>
                    <div class="row"><button type="button" class="btn btn-light" id="clear">Clear signature</button></div>
                </div>

                <div id="changes-block" class="hidden">
                    <label for="comment">What needs to change?</label>
                    <textarea id="comment" name="comment" rows="4" maxlength="2000">{{ old('comment') }}</textarea>
                </div>

                <div class="row">
                    <button type="submit" class="btn btn-primary" id="approve">Approve &amp; sign</button>
                    <button type="button" class="btn btn-danger" id="request-changes">Request changes</button>
                </div>
                <p class="muted">By approving you confirm you have reviewed this CRF. Your name, signature, date and IP address are recorded on the form.</p>
            </form>
        @endif
    </div>
</div>

@if ($canSign)
<script>
(() => {
    const canvas = document.getElementById('pad');
    const ctx = canvas.getContext('2d');
    let drawing = false, drawn = false, last = null;

    function resize() {
        const ratio = window.devicePixelRatio || 1;
        const rect = canvas.getBoundingClientRect();
        canvas.width = rect.width * ratio;
        canvas.height = rect.height * ratio;
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#1e3a8a';
        drawn = false;
    }
    const point = (e) => { const r = canvas.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; };

    canvas.addEventListener('pointerdown', (e) => { drawing = true; last = point(e); canvas.setPointerCapture(e.pointerId); });
    canvas.addEventListener('pointermove', (e) => {
        if (!drawing) return;
        const p = point(e);
        ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
        last = p; drawn = true;
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach((t) => canvas.addEventListener(t, () => { drawing = false; }));
    document.getElementById('clear').addEventListener('click', () => { ctx.clearRect(0, 0, canvas.width, canvas.height); drawn = false; });
    window.addEventListener('resize', resize);
    resize();

    const form = document.getElementById('sign-form');
    const decision = document.getElementById('decision');
    const changesBlock = document.getElementById('changes-block');
    const approveBlock = document.getElementById('approve-block');
    const changesBtn = document.getElementById('request-changes');

    changesBtn.addEventListener('click', () => {
        if (decision.value !== 'changes') {
            decision.value = 'changes';
            changesBlock.classList.remove('hidden');
            approveBlock.classList.add('hidden');
            document.getElementById('approve').textContent = 'Back to signing';
            changesBtn.textContent = 'Send change request';
            document.getElementById('comment').focus();
            return;
        }
        if (!document.getElementById('comment').value.trim()) { alert('Describe the changes you need.'); return; }
        form.submit();
    });

    form.addEventListener('submit', (e) => {
        if (decision.value === 'changes') {
            // "Back to signing"
            e.preventDefault();
            decision.value = 'approve';
            changesBlock.classList.add('hidden');
            approveBlock.classList.remove('hidden');
            document.getElementById('approve').textContent = 'Approve & sign';
            changesBtn.textContent = 'Request changes';
            resize();
            return;
        }
        if (!drawn) { e.preventDefault(); alert('Please draw your signature first.'); return; }
        document.getElementById('signature_image').value = canvas.toDataURL('image/png');
    });
})();
</script>
@endif
</body>
</html>
