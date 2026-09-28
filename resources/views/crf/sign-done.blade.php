<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ config('app.name') }} | CRF signed</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <style>
        body { margin: 0; background: #f4f5f7; color: #1f2937; font: 15px/1.5 "Segoe UI", Arial, sans-serif; }
        .card { max-width: 560px; margin: 48px auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 28px; text-align: center; }
        img { width: 56px; height: 56px; }
        h1 { font-size: 20px; color: #c2410c; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
<div class="card">
    <img src="{{ asset('images/oms-logo-128.png') }}" alt="">
    @if ($signatory->status === \App\Models\ChangeRequestSignature::APPROVED)
        <h1>Thank you — CRF signed</h1>
        <p>Your approval of <strong>{{ $signatory->changeRequest->outage->title }}</strong> ({{ $signatory->changeRequest->outage->ticket_number }}) has been recorded.</p>
        <p class="muted">Once every signatory has approved, all signatories receive the fully signed CRF by e-mail.</p>
    @else
        <h1>Change request sent</h1>
        <p>Your requested changes to <strong>{{ $signatory->changeRequest->outage->title }}</strong> have been sent to NOC.</p>
        <p class="muted">You will receive a new sign-off request once the CRF has been updated.</p>
    @endif
</div>
</body>
</html>
