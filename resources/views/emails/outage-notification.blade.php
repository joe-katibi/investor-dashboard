<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:Segoe UI,Arial,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" style="max-width:640px;margin:0 auto;background:#ffffff;border-radius:8px;border:1px solid #e5e7eb;">
    <tr>
        <td style="padding:20px 24px;border-bottom:3px solid #d97706;">
            <strong style="font-size:16px;">{{ config('app.name') }}</strong>
        </td>
    </tr>
    <tr>
        <td style="padding:24px;">
            <h2 style="margin:0 0 16px;font-size:18px;">{{ $subjectLine }}</h2>
            <p style="margin:0;line-height:1.6;white-space:pre-line;">{{ $body }}</p>
            @if ($url)
                <p style="margin:24px 0 0;">
                    <a href="{{ $url }}" style="background:#d97706;color:#ffffff;padding:10px 18px;border-radius:6px;text-decoration:none;">Open ticket</a>
                </p>
            @endif
        </td>
    </tr>
    <tr>
        <td style="padding:12px 24px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;">
            Automated message from the Outage Management System.
        </td>
    </tr>
</table>
</body>
</html>
