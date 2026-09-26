@php
    // Body is "headline" followed by "Label: value" lines (see NotificationMessageFactory).
    $lines = array_values(array_filter(preg_split('/\R/', (string) $body)));
    $headline = array_shift($lines);
    $rows = array_map(fn ($line) => str_contains($line, ': ') ? explode(': ', $line, 2) : ['', $line], $lines);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f1f5f9;font-family:Segoe UI,Arial,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:10px;border:1px solid #e2e8f0;overflow:hidden;">
    <tr>
        <td style="padding:16px 24px;background:#0b4fd6;color:#ffffff;">
            <table role="presentation" cellspacing="0" cellpadding="0"><tr>
                <td style="padding-right:10px;"><img src="{{ asset('images/oms-logo-128.png') }}" width="36" height="36" alt="" style="display:block;background:#ffffff;border-radius:8px;padding:2px;"></td>
                <td style="font-size:15px;font-weight:700;">{{ config('app.name') }}</td>
            </tr></table>
        </td>
    </tr>
    <tr>
        <td style="padding:22px 24px 8px;">
            <h2 style="margin:0 0 6px;font-size:18px;line-height:1.35;">{{ $subjectLine }}</h2>
            @if ($headline)
                <p style="margin:0;color:#475569;font-size:14px;">{{ $headline }}</p>
            @endif
        </td>
    </tr>
    @if ($rows)
        <tr>
            <td style="padding:8px 24px 4px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:14px;">
                    @foreach ($rows as [$label, $value])
                        <tr>
                            <td style="padding:7px 10px 7px 0;border-bottom:1px solid #f1f5f9;color:#64748b;white-space:nowrap;vertical-align:top;width:34%;">{{ $label }}</td>
                            <td style="padding:7px 0;border-bottom:1px solid #f1f5f9;vertical-align:top;white-space:pre-line;">{{ $value }}</td>
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    @endif
    @if ($url)
        <tr>
            <td style="padding:18px 24px 24px;">
                <a href="{{ $url }}" style="display:inline-block;background:#d97706;color:#ffffff;padding:10px 18px;border-radius:6px;text-decoration:none;font-weight:600;">Open ticket</a>
            </td>
        </tr>
    @endif
    <tr>
        <td style="padding:12px 24px;font-size:12px;color:#64748b;border-top:1px solid #e2e8f0;">
            Automated alert from the {{ config('app.name') }}. Alert recipients are managed under Configuration → Alert E-mails.
        </td>
    </tr>
</table>
</body>
</html>
