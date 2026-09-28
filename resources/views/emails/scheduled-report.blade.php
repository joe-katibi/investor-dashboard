<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" style="max-width:900px;margin:0 auto;background:#ffffff;border-radius:8px;border:1px solid #e5e7eb;">
    <tr>
        <td style="padding:18px 24px;border-bottom:3px solid #d97706;">
            <strong style="font-size:16px;">{{ config('app.name') }}</strong>
            <div style="font-size:13px;color:#6b7280;">{{ $subjectLine }}</div>
        </td>
    </tr>
    <tr>
        <td style="padding:20px 24px;font-size:14px;line-height:1.55;">
            {{-- Rendered by TemplateRenderer: template text and values are escaped; only **bold** markers and OMS-generated tables become HTML. --}}
            {!! $html !!}
        </td>
    </tr>
    <tr>
        <td style="padding:12px 24px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;">
            Scheduled report generated {{ now()->format('Y-m-d H:i') }}.
        </td>
    </tr>
</table>
</body>
</html>
