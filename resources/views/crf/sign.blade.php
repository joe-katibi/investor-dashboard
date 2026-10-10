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
        .tabs { display: flex; gap: 6px; margin-bottom: 10px; }
        .tab { border: 1px solid var(--line); background: #fff; color: var(--ink); border-radius: 999px; padding: 7px 16px; font: inherit; font-weight: 600; cursor: pointer; }
        .tab[aria-selected=true] { background: var(--blue); border-color: var(--blue); color: #fff; }
        .styles { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 10px; }
        .style { position: relative; display: flex; flex-direction: column; align-items: center; justify-content: space-between; gap: 6px; min-height: 104px; padding: 10px 10px 8px; border: 2px solid var(--line); border-radius: 10px; background: #fff; cursor: pointer; font: inherit; }
        .style:hover { border-color: #93c5fd; }
        .style[aria-checked=true] { border-color: var(--brand); background: #fffbeb; box-shadow: 0 0 0 2px #fde68a; }
        .style img { max-width: 100%; height: 56px; object-fit: contain; }
        .style .style-name { font-size: 12px; color: var(--muted); }
        .style .mine { position: absolute; top: 4px; right: 6px; font-size: 10px; font-weight: 700; color: #166534; background: #dcfce7; border-radius: 999px; padding: 1px 7px; }
        .check { display: flex; align-items: flex-start; gap: 8px; font-weight: 400; margin: 12px 0 0; }
        .check input { margin-top: 4px; }
        .notice { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 8px; padding: 10px 14px; }
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
                <input type="hidden" name="signature_method" id="signature_method" value="{{ old('signature_method', 'typed') }}">
                <input type="hidden" name="signature_style" id="signature_style" value="{{ old('signature_style', $savedStyle) }}">

                <label for="signed_name">Full name</label>
                <input type="text" id="signed_name" name="signed_name" maxlength="150" required value="{{ old('signed_name', $signatory->user?->name ?? $signatory->name) }}">

                <div id="approve-block">
                    <label id="signature-label">Signature</label>
                    <div class="tabs" role="tablist" aria-labelledby="signature-label">
                        <button type="button" class="tab" role="tab" id="tab-typed" data-mode="typed" aria-selected="true">Choose a style</button>
                        <button type="button" class="tab" role="tab" id="tab-drawn" data-mode="drawn" aria-selected="false">Draw it</button>
                    </div>

                    <div id="typed-block">
                        <p class="muted" id="styles-hint">Loading the signature styles…</p>
                        <div class="styles" id="styles" role="radiogroup" aria-label="Signature style"></div>
                        <div class="notice hidden" id="styles-none">The signature styles could not be loaded on this connection. Draw your signature instead.</div>
                        <div id="typed-options" class="hidden">
                            <label class="check"><input type="checkbox" name="adopt_signature" id="adopt_signature" value="1"> <span>I confirm that the signature I chose is mine and stands for my handwritten signature on this form.</span></label>
                            <label class="check"><input type="checkbox" name="remember_style" id="remember_style" value="1" @checked(old('remember_style'))> <span>Keep this style as my signature for next time</span></label>
                        </div>
                    </div>

                    <div id="drawn-block" class="hidden">
                        <p class="muted">Draw with your mouse or finger.</p>
                        <canvas class="pad" id="pad"></canvas>
                        <div class="row"><button type="button" class="btn btn-light" id="clear">Clear signature</button></div>
                    </div>
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

    // ---- Signature styles: the name written as a signature, as an alternative to drawing it.
    // What is shown on each card is the very picture that would be stored and printed on the CRF.
    const STYLES = @json($styles);
    const SAVED = @json($savedStyle);
    const INK = '#1e3a8a';
    const nameInput = document.getElementById('signed_name');
    const method = document.getElementById('signature_method');
    const styleInput = document.getElementById('signature_style');
    const gallery = document.getElementById('styles');
    const hint = document.getElementById('styles-hint');
    const none = document.getElementById('styles-none');
    const typedOptions = document.getElementById('typed-options');
    const tabs = { typed: document.getElementById('tab-typed'), drawn: document.getElementById('tab-drawn') };
    const blocks = { typed: document.getElementById('typed-block'), drawn: document.getElementById('drawn-block') };
    const ready = new Map();   // style key => its card, once the font is there
    let pending = STYLES.length;

    const written = (style) => { const name = nameInput.value.trim().replace(/\s+/g, ' '); return style.upper ? name.toUpperCase() : name; };
    const fontOf = (style, size) => `${style.weight} ${size}px "${style.font}"`;

    function setMode(mode) {
        method.value = mode;
        Object.keys(tabs).forEach((key) => {
            tabs[key].setAttribute('aria-selected', key === mode ? 'true' : 'false');
            blocks[key].classList.toggle('hidden', key !== mode);
        });
        if (mode === 'drawn') resize();
    }
    Object.values(tabs).forEach((tab) => tab.addEventListener('click', () => setMode(tab.dataset.mode)));

    /** The name in this style as a transparent PNG, cut close to the writing. */
    async function render(style) {
        const text = written(style);
        const size = 96, pad = 16;
        const font = fontOf(style, size);
        await document.fonts.load(font, text);

        const out = document.createElement('canvas');
        const c = out.getContext('2d');
        c.font = font;
        const m = c.measureText(text);
        const left = Math.ceil(m.actualBoundingBoxLeft || 0);
        const right = Math.ceil(Math.max(m.actualBoundingBoxRight || 0, m.width));
        const ascent = Math.ceil(m.actualBoundingBoxAscent || size * 0.8);
        const descent = Math.ceil(m.actualBoundingBoxDescent || size * 0.3);
        const lean = style.slant ? 0.2 : 0;
        const width = left + right + pad * 2 + Math.ceil((ascent + descent) * lean);
        const height = ascent + descent + pad * 2 + (style.line ? 30 : 0);
        const scale = Math.min(1, 1400 / width);   // a long name still fits the form

        out.width = Math.ceil(width * scale);
        out.height = Math.ceil(height * scale);
        c.scale(scale, scale);
        c.save();
        if (lean) c.transform(1, 0, -lean, 1, lean * (pad + ascent + descent), 0);
        c.font = font; c.fillStyle = INK; c.textBaseline = 'alphabetic';
        c.fillText(text, pad + left, pad + ascent);
        c.restore();

        if (style.line) {
            const y = pad + ascent + descent + 8;
            c.strokeStyle = INK; c.lineCap = 'round'; c.lineJoin = 'round';
            c.beginPath();
            if (style.line === 'underline') {
                c.lineWidth = 3;
                c.moveTo(pad, y + 6); c.quadraticCurveTo(width / 2, y - 2, width - pad, y + 4);
            } else {
                c.lineWidth = 2.5;
                c.moveTo(pad, y + 4);
                c.bezierCurveTo(width * 0.35, y - 10, width * 0.7, y + 22, width - pad, y);
                c.bezierCurveTo(width * 0.82, y + 20, width * 0.58, y + 18, width * 0.45, y + 10);
            }
            c.stroke();
        }

        return out.toDataURL('image/png');
    }

    function select(key) {
        styleInput.value = key;
        ready.forEach((card, other) => card.setAttribute('aria-checked', other === key ? 'true' : 'false'));
    }

    let drawTimer = null;
    function redraw() {
        const named = nameInput.value.trim() !== '';
        gallery.classList.toggle('hidden', !named);
        typedOptions.classList.toggle('hidden', !named || ready.size === 0);
        if (ready.size) hint.textContent = named ? 'Your name written as a signature. Choose the one you want on the form.' : 'Type your full name above to see it as a signature.';
        if (!named) return;

        ready.forEach((card, key) => {
            const style = STYLES.find((s) => s.key === key);
            render(style).then((url) => { card.querySelector('img').src = url; }).catch(() => {});
        });
    }
    nameInput.addEventListener('input', () => { clearTimeout(drawTimer); drawTimer = setTimeout(redraw, 200); });

    function settled() {
        if (--pending > 0) return;
        if (ready.size === 0) {
            // No font service on this connection: drawing still works.
            hint.classList.add('hidden');
            none.classList.remove('hidden');
            setMode('drawn');
        }
    }

    function offer(style) {
        const card = document.createElement('button');
        card.type = 'button';
        card.className = 'style';
        card.setAttribute('role', 'radio');
        card.setAttribute('aria-checked', 'false');
        card.dataset.key = style.key;
        card.innerHTML = '<img alt=""><span class="style-name"></span>' + (style.key === SAVED ? '<span class="mine">Your style</span>' : '');
        card.querySelector('.style-name').textContent = style.label;
        card.setAttribute('aria-label', style.label);
        card.addEventListener('click', () => select(style.key));

        // Kept in the order of the list, whichever font arrives first.
        ready.set(style.key, card);
        const after = STYLES.slice(STYLES.indexOf(style) + 1).map((s) => ready.get(s.key)).find(Boolean);
        gallery.insertBefore(card, after || null);

        if (styleInput.value === style.key) select(style.key);
        hint.classList.remove('hidden');
        none.classList.add('hidden');
        clearTimeout(drawTimer);
        drawTimer = setTimeout(redraw, 60);
    }

    STYLES.forEach((style) => {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = style.css;
        link.referrerPolicy = 'no-referrer';
        link.onload = () => document.fonts.load(fontOf(style, 40), 'Signature')
            .then((faces) => { if (faces.length) offer(style); })
            .catch(() => {})
            .finally(settled);
        link.onerror = settled;
        document.head.appendChild(link);
    });
    // A connection that neither answers nor fails: stop waiting.
    setTimeout(() => { if (pending > 0) { pending = 1; settled(); } }, 8000);

    setMode(method.value === 'drawn' ? 'drawn' : 'typed');

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
        const image = document.getElementById('signature_image');

        if (method.value === 'typed') {
            // The picture is made now, from the name as it stands, then the form is sent.
            e.preventDefault();
            const style = STYLES.find((s) => s.key === styleInput.value && ready.has(s.key));
            if (!nameInput.value.trim()) { alert('Type your full name.'); nameInput.focus(); return; }
            if (!style) { alert('Choose a signature style, or draw your signature.'); return; }
            if (!document.getElementById('adopt_signature').checked) { alert('Tick the box to confirm that this is your signature.'); return; }
            render(style)
                .then((url) => { image.value = url; form.submit(); })
                .catch(() => alert('Your signature could not be prepared. Choose the style again, or draw your signature.'));
            return;
        }

        if (!drawn) { e.preventDefault(); alert('Please draw your signature first.'); return; }
        styleInput.value = '';
        image.value = canvas.toDataURL('image/png');
    });
})();
</script>
@endif
</body>
</html>
