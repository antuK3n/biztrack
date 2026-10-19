<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.forms.partials.style')
    <style>
        body { font-size: 9.4px; }
        .hd td { vertical-align: middle; }
        .req { padding-left: 4px; }
        .req div { margin: 0; line-height: 1.3; }
        .sect td { vertical-align: top; }
        .rule { border-top: 2px solid #000; margin: 6px 0 4px; }
    </style>
</head>
<body>
@include('pdf.forms.partials.generated')
{{--
    The BPLO AMENDMENT FORM (the system's MCG-BPLO-FO-003), after the paper
    photographed as img-124/img-140: City of Malabon head, the five header
    lines, the unnumbered "other amendments" block with its four circles, then
    I. Change of Address, II. Change of Ownership, III. Change of Trade Name —
    each with the requirements printed under it word for word — REMARKS, and
    the after-payment notice.

    A box is ticked when the filing asks for that group; the new value prints
    on the paper's line and the old one is noted beside it, since the officer
    approving the change needs both. The paper's typo "4. F / or Single
    Proprietor" under Change of Address is printed as the one item it was
    meant to be ("For Single Proprietor – DTI Registration"); the paper names
    no form code, so the code the system files it under prints at the foot.

    "Approved by:" is left blank: the paper carries the signature of the BPLO
    officer-in-charge, and printing a name there would be a signature nobody
    gave on this copy.
--}}
<table class="hd">
    <tr>
        <td style="width: 18%;">@if($f['seal'])<img src="{{ $f['seal'] }}" style="height: 66px;" alt="">@endif</td>
        <td class="c">
            <div style="font-size: 10px;">Republic of the Philippines</div>
            <div class="bold" style="font-size: 15px;">CITY OF MALABON</div>
            <div style="font-size: 10px;">Metropolitan Manila</div>
            <div class="small">3<sup>rd</sup> floor, Malabon City Hall, F. Sevilla Boulevard, Barangay San Agustin, Malabon City</div>
            <div class="small">Telephone No.: 8281-4999 local no 3012</div>
        </td>
        <td style="width: 18%;"></td>
    </tr>
</table>
<div class="c bold" style="font-size: 17px; margin-top: 6px; letter-spacing: 0.5px;">BUSINESS PERMIT AND LICENSING OFFICE</div>
<div class="c bold" style="font-size: 15px; margin-top: 4px; font-family: DejaVu Serif, serif;">AMENDMENT FORM</div>

<table style="margin-top: 8px; width: 80%; margin-left: 50px;">
    <tr><td class="bold big" style="width: 26%;">Taxpayer's Name:</td><td class="fill v">{{ $f['owner_name'] }}</td></tr>
    <tr><td class="bold big">Business Name:</td><td class="fill v">{{ $f['business_name'] }}</td></tr>
    <tr><td class="bold big">Account Number:</td><td class="fill v">{{ $f['ban'] }}{{ $f['prior_permit_no'] !== '' ? '   (Permit No. '.$f['prior_permit_no'].')' : '' }}</td></tr>
    <tr><td class="bold big">Address:</td><td class="fill v">{{ $f['address'] }}</td></tr>
    <tr><td class="bold big">Date of Application:</td><td><table style="width: 45%;"><tr><td class="fill v">{{ $f['submitted_on'] }}</td></tr></table></td></tr>
</table>

@php
    $other = $amend['other'];
    $val = fn ($row) => $row ? trim($row['to'].($row['from'] !== '' ? '  (from '.$row['from'].')' : '')) : '';
    $line = $other['line_of_business'] ?? null;
    $area = $other['business_area_sqm'] ?? null;
    $rest = array_diff_key($other, array_flip(['line_of_business', 'business_area_sqm']));
    $restText = implode('; ', array_map(fn ($r) => $r['label'].': '.$r['to'].($r['from'] !== '' ? ' (from '.$r['from'].')' : ''), $rest));
    $circle = fn ($on) => $on ? '◉' : '○';
    $requirements = fn (array $items) => $items;
@endphp

{{-- The unnumbered block: line of business, area and "others". --}}
<table class="sect" style="margin-top: 10px;">
    <tr>
        <td style="width: 8%;"><span class="ck" style="font-size: 18px;">{{ $ck($other !== []) }}</span></td>
        <td style="padding: 0;">
            <table>
                <tr><td style="width: 5%;" class="ck">{{ $circle($line !== null) }}</td><td class="bold" style="width: 47%;">CHANGE OF LINE OF BUSINESS</td><td class="fill v">{{ $val($line) }}</td></tr>
                <tr><td class="ck">○</td><td class="bold">ADDITIONAL LINE OF BUSINESS</td><td class="fill v"></td></tr>
                <tr><td class="ck">{{ $circle($area !== null) }}</td><td class="bold">AMENDMENT OF AREA</td><td class="fill v">{{ $area ? $area['to'].' sq. m.'.($area['from'] !== '' ? '  (from '.$area['from'].' sq. m.)' : '') : '' }}</td></tr>
                <tr><td class="ck">{{ $circle($rest !== []) }}</td><td class="bold">OTHERS, KINDLY SPECIFY <span style="font-weight: normal;">(EMPLOYEES, VEHICLE, ETC.)</span></td><td class="fill v">{{ $restText }}</td></tr>
            </table>
        </td>
    </tr>
</table>
<table style="margin-top: 4px;">
    <tr>
        <td style="width: 16%;">Requirements:</td>
        <td class="req">
            <div>1. Affidavit requesting BPLO to acknowledge particular amendment.</div>
            <div>2. Zoning clearance (if required).</div>
            <div>3. For Corporation – Amended Articles of Incorporation (if required), Board Resolution and Secretary's Certification.</div>
            <div>4. Photocopy of Business/Mayor's Permit.</div>
            <div>5. SPA/Authorization and ID's if representative processes application.</div>
            <div>6. Other documents that may be required ____________________________________.</div>
        </td>
    </tr>
</table>
<div class="rule"></div>

@php
    $addr = $amend['address'];
    $addrNew = implode(' ', array_filter([
        $addr['address_house_bldg_no']['to'] ?? '', $addr['address_street']['to'] ?? '',
        isset($addr['address_barangay_id']) ? 'Brgy. '.$addr['address_barangay_id']['to'] : '',
    ]));
    $addrDetails = trim(($addr['address_details']['to'] ?? '').(isset($addr['address_pin']) ? ' (map location moved)' : ''));
    $own = $amend['ownership'];
    $ownNew = trim(implode(' ', array_filter([
        $own['owner_given_name']['to'] ?? '', $own['owner_middle_name']['to'] ?? '', $own['owner_surname']['to'] ?? '', $own['owner_suffix']['to'] ?? '',
    ])));
    $trade = $amend['trade_name'];
@endphp

<table class="sect">
    <tr>
        <td style="width: 8%;"><span class="ck" style="font-size: 18px;">{{ $ck($addr !== []) }}</span></td>
        <td style="width: 5%;" class="bold">I.</td>
        <td style="padding: 0;">
            <table>
                <tr><td class="bold" style="width: 40%;">CHANGE OF ADDRESS: <span style="font-weight: normal;">(New Address)</span></td><td class="fill v">{{ $addrNew }}</td></tr>
                <tr><td class="bold" style="padding-top: 6px;">AMENDMENT OF ADDRESS DETAILS:</td><td class="fill v">{{ $addrDetails }}</td></tr>
            </table>
        </td>
    </tr>
</table>
<table style="margin-top: 4px;">
    <tr>
        <td style="width: 16%;">Requirements:</td>
        <td class="req">
            <div>1. Affidavit requesting BPLO to acknowledge change/Amendment of ADDRESS/</div>
            <div>2. Zoning clearance.</div>
            <div>3. Contract of Lease and/or Proof of Ownership.</div>
            <div>4. For Single Proprietor – DTI Registration.</div>
            <div>5. For Corporation – Amended Articles of Incorporation, Board Resolution and Secretary's Certification.</div>
            <div>6. Photocopy of Business/Mayor's Permit.</div>
            <div>7. Picture and sketch map of location of establishment.</div>
            <div>8. SPA/Authorization and ID's if representative processes application.</div>
            <div>9. Other documents that may be required ____________________________________.</div>
        </td>
    </tr>
</table>
<div class="rule"></div>

<table class="sect">
    <tr>
        <td style="width: 8%;"><span class="ck" style="font-size: 18px;">{{ $ck($own !== []) }}</span></td>
        <td style="width: 5%;" class="bold">II.</td>
        <td style="padding: 0;">
            <table>
                <tr><td class="bold" style="width: 40%;">CHANGE OF OWNERSHIP: <span style="font-weight: normal;">(New Owner)</span></td><td class="fill v">{{ $ownNew }}</td></tr>
                <tr><td class="bold" style="padding-top: 6px;">AMENDMENT OF OWNERSHIP DETAILS:</td><td class="fill v">{{ $own['ownership_details']['to'] ?? '' }}</td></tr>
            </table>
        </td>
    </tr>
</table>
<table style="margin-top: 4px;">
    <tr>
        <td style="width: 16%;">Requirements:</td>
        <td class="req">
            <div>1. Affidavit requesting BPLO to acknowledge change/Amendment of OWNERSHIP.</div>
            <div>2. Deed of Transfer – Deed of Sale/Assignment, Affidavit of Self-Adjudication or Extra-Judicial Settlement of estate of deceased owner.</div>
            <div>3. From Corporation to Single Proprietor – DTI Registration.</div>
            <div>4. From Single Proprietor to Corporation – Amended Articles of Incorporation, Board Resolution and Secretary's Certification.</div>
            <div>5. Photocopy of Business/Mayor's Permit.</div>
            <div>6. SPA/Authorization and ID's if representative processes application.</div>
            <div>7. Other documents that may be required ____________________________________.</div>
        </td>
    </tr>
</table>
<div class="rule"></div>

<table class="sect">
    <tr>
        <td style="width: 8%;"><span class="ck" style="font-size: 18px;">{{ $ck($trade !== []) }}</span></td>
        <td style="width: 5%;" class="bold">III.</td>
        <td style="padding: 0;">
            <table>
                <tr><td class="bold" style="width: 40%;">CHANGE OF TRADE NAME: <span style="font-weight: normal;">(New Trade Name)</span></td><td class="fill v">{{ $trade['trade_name']['to'] ?? '' }}</td></tr>
                <tr><td class="bold" style="padding-top: 6px;">AMENDMENT OF TRADENAME DETAILS:</td><td class="fill v">{{ $trade['trade_name_details']['to'] ?? '' }}</td></tr>
            </table>
        </td>
    </tr>
</table>
<table style="margin-top: 4px;">
    <tr>
        <td style="width: 16%;">Requirements:</td>
        <td class="req">
            <div>1. Affidavit requesting BPLO to acknowledge change/Amendment of TRADE NAME.</div>
            <div>2. For Single Proprietor - DTI Registration.</div>
            <div>3. For Corporation – Amended Articles of Incorporation, Board Resolution and Secretary's Certification.</div>
            <div>4. SPA/Authorization and ID's if representative processes application.</div>
            <div>5. Other documents that may be required ____________________________________.</div>
        </td>
    </tr>
</table>

<table class="box" style="margin-top: 10px; border-width: 2px;">
    <tr><td style="height: 70px; padding: 4px 8px;">REMARKS:</td></tr>
</table>

<div class="bold" style="margin-top: 8px; font-family: DejaVu Serif, serif; font-size: 9.6px;">
    AFTER PAYMENT, PLEASE RETURN THIS FORM AND OTHER REQUIREMENTS TO THE BPLO WINDOW FOR COMPLETION OF PROCESS.
</div>
<div class="bold" style="font-family: DejaVu Serif, serif; font-size: 9.6px;">Approved by:</div>
<table style="margin-top: 26px; width: 40%;">
    <tr><td class="sig" style="text-align: left;">OIC – BPLO</td></tr>
</table>
<div class="tiny muted r" style="margin-top: 4px;">{{ $doc['id'] }} · v{{ $doc['version'] }}</div>
</body>
</html>
