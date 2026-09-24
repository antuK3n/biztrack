{{--
    The owner-update e-mail, HTML part. One template for every event.

    Tables and inline styles because that is what mail clients render; a
    <style> block is stripped by Gmail's app and several webmail readers.
    Royal #3242ca for the button and the rule only (DESIGN.md). Red #bd0000
    appears once, on the title of a disapproval, and nowhere else — Red Means
    Stop, and every other update is not one. The title is also words, so the
    colour never carries the meaning alone.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }}</title>
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
    <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:{{ $isDisapproval ? '#bd0000' : '#1c1f2e' }};">{{ $title }}</h1>
    @if ($businessName || $reference)
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;font-size:14px;color:#4a4f63;">
        @if ($businessName)
        <tr><td style="padding:2px 12px 2px 0;">Business</td><td style="padding:2px 0;color:#1c1f2e;">{{ $businessName }}</td></tr>
        @endif
        @if ($reference)
        <tr><td style="padding:2px 12px 2px 0;">Reference</td><td style="padding:2px 0;color:#1c1f2e;font-family:Consolas,Menlo,monospace;">{{ $reference }}</td></tr>
        @endif
    </table>
    @endif
    <p style="margin:0 0 20px;font-size:15px;line-height:1.55;">{{ $body }}</p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
    <tr><td style="background:#3242ca;border-radius:6px;">
        <a href="{{ $url }}" style="display:inline-block;padding:11px 20px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;">Open in BizTrack</a>
    </td></tr>
    </table>
    <p style="margin:0 0 20px;font-size:13px;line-height:1.5;color:#4a4f63;">If the button does not work, copy this address into your browser:<br><a href="{{ $url }}" style="color:#3242ca;word-break:break-all;">{{ $url }}</a></p>
</td></tr>
<tr><td style="padding:16px 28px 24px;border-top:1px solid #e3e5ee;font-size:12px;line-height:1.5;color:#6b7086;">
    City of Malabon Business Permits and Licensing Office, through BizTrack.<br>
    This is an automatic message. Replies to this address are not read; use Messages in BizTrack to reach the office.
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
