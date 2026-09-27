{{--
    A six-digit code, HTML part. Same frame as owner-update.blade.php: tables and
    inline styles because that is what mail clients render, royal #3242ca for the
    rule only. No red anywhere: a code is not an error.

    The code is set in a monospace face with letter spacing so 0/O and 1/l cannot
    be confused, and it is plain text so a phone can long-press and copy it.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $heading }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f9;font-family:Arial,Helvetica,sans-serif;color:#1c1f2e;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f9;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;border-top:4px solid #3242ca;">
<tr><td style="padding:24px 28px 8px;">
    <p style="margin:0 0 16px;font-size:14px;font-weight:bold;color:#3242ca;letter-spacing:0.02em;">BizTrack &middot; City of Malabon</p>
    @if ($recipientName)
    <p style="margin:0 0 12px;font-size:15px;">Hi {{ $recipientName }},</p>
    @endif
    <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#1c1f2e;">{{ $heading }}</h1>
    <p style="margin:0 0 20px;font-size:15px;line-height:1.55;">{{ $lead }}</p>
    <p style="margin:0 0 8px;font-family:Consolas,Menlo,monospace;font-size:32px;font-weight:bold;letter-spacing:0.25em;color:#1c1f2e;">{{ $code }}</p>
    <p style="margin:0 0 20px;font-size:14px;color:#4a4f63;">This code works for {{ $minutes }} minutes.</p>
    <p style="margin:0 0 20px;font-size:13px;line-height:1.5;color:#4a4f63;">{{ $warning }}</p>
</td></tr>
<tr><td style="padding:16px 28px 24px;border-top:1px solid #e3e5ee;font-size:12px;line-height:1.5;color:#6b7086;">
    City of Malabon Business Permits and Licensing Office, through BizTrack.<br>
    This is an automatic message. Replies to this address are not read.
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
