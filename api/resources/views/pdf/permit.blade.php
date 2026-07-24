<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1a1a1a; margin: 0; padding: 40px; }
        .header { text-align: center; border-bottom: 3px solid #0025cc; padding-bottom: 12px; }
        .header .republic { font-size: 11px; letter-spacing: 1px; color: #555; }
        .header .city { font-size: 22px; font-weight: bold; color: #0025cc; margin: 4px 0; }
        .header .office { font-size: 12px; color: #333; }
        .title { text-align: center; font-size: 18px; font-weight: bold; margin: 24px 0 4px; letter-spacing: 2px; }
        .permit-no { text-align: center; font-size: 14px; color: #bd0000; font-weight: bold; margin-bottom: 24px; }
        table.details { width: 100%; border-collapse: collapse; margin: 0 auto; }
        table.details td { padding: 6px 8px; font-size: 12px; vertical-align: top; }
        table.details td.label { color: #666; width: 35%; }
        table.details td.value { font-weight: bold; }
        .validity { margin-top: 24px; text-align: center; font-size: 13px; }
        .qr-box { text-align: center; margin-top: 28px; }
        .qr-box img { width: 130px; height: 130px; }
        .verify-code { font-family: DejaVu Sans Mono, monospace; font-size: 12px; border: 1px solid #333; display: inline-block; padding: 6px 14px; margin-top: 8px; letter-spacing: 1px; }
        .verify-url { font-size: 10px; color: #555; margin-top: 6px; }
        .footer { margin-top: 36px; text-align: center; font-size: 9px; color: #999; border-top: 1px solid #ddd; padding-top: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="republic">REPUBLIC OF THE PHILIPPINES</div>
        <div class="city">CITY OF MALABON</div>
        <div class="office">{{ $department_name ?? 'Business Permits and Licensing Office' }}</div>
    </div>

    <div class="title">{{ strtoupper($permit_type_name) }}</div>
    <div class="permit-no">Permit No. {{ $permit_number }}</div>

    <table class="details">
        <tr><td class="label">Business Name</td><td class="value">{{ $business_name }}</td></tr>
        @if($trade_name)<tr><td class="label">Trade Name</td><td class="value">{{ $trade_name }}</td></tr>@endif
        <tr><td class="label">Owner</td><td class="value">{{ $owner_name }}</td></tr>
        <tr><td class="label">Address</td><td class="value">{{ $address }}</td></tr>
        <tr><td class="label">Barangay</td><td class="value">{{ $barangay }}</td></tr>
    </table>

    <div class="validity">
        Valid from <strong>{{ $valid_from }}</strong> until <strong>{{ $valid_until }}</strong>
    </div>

    <div class="qr-box">
        @if($qr)
            <img src="{{ $qr }}" alt="Verification QR">
        @endif
        <div class="verify-code">{{ $permit_number }}</div>
        <div class="verify-url">Scan or verify at {{ $verify_url }}</div>
    </div>

    <div class="footer">
        This is a system-generated certificate from BizTrack (Malabon). Verify authenticity using the code above.
    </div>
</body>
</html>
