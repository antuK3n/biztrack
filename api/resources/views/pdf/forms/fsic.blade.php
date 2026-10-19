<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.forms.partials.style')
    <style>
        body { font-size: 9px; }
        .bar { background: #000; color: #fff; }
        .bar td { vertical-align: middle; }
        .appno td { border: 1px solid #000; width: 18px; height: 20px; text-align: center; }
        .t td { border: 1px solid #000; padding: 4px 5px; }
        .t table.plain td { border: 0; padding: 2px 4px; }
        .hdr { background: #000; color: #fff; font-weight: bold; font-style: italic; text-align: center; font-size: 11px; padding: 2px; }
        .mon td { border: 1px solid #000; padding: 1px 3px; font-size: 7.6px; }
        .mon .h { font-weight: bold; font-size: 10px; text-align: center; }
    </style>
</head>
<body>
@include('pdf.forms.partials.generated')
{{--
    BFP-QSF-FSED-002 REV.02 (08.24.20) — FIRE SAFETY INSPECTION CERTIFICATE
    APPLICATION FORM, with its claim stub, after the paper photographed as
    img-330.

    The applicant's part is filled from the filing and the FSIC sheet. The
    requirement lines tick themselves where the applicant attached that
    document to the FSIC sheet's checklist (FsicRequirements); the paper's
    "FSIC FOR CERTIFICATE OF OCCUPANCY" rows have no upload slot in the system
    and are ticked only by the counter. Application Number, "Verified by
    BFP-CRO", the FSIC MONITORING row and the claim stub are BFP's and stay
    blank. The BFP seal is not on file and is not redrawn.
--}}
@php
    $applied = (string) ($d['certificate_applied_for'] ?? '');
    $forOccupancy = str_contains($applied, 'Occupancy');
    $renewal = str_contains($applied, 'Renewal');
    $forBusiness = ! $forOccupancy;
    $rep = trim((string) ($d['authorized_representative'] ?? ''));
    $occ = trim((string) ($d['occupancy_type'] ?? ''));
    $storeys = trim((string) ($d['building_storeys'] ?? '')) ?: $f['storeys'];
    $t = fn (string $key) => ($ticks[$key] ?? false) ? '[✓]' : '[  ]';
@endphp

@foreach(['form', 'stub'] as $part)
<table>
    <tr>
        <td style="width: 73%; padding: 0;">
            <table class="bar">
                <tr>
                    <td style="width: 16%; height: 64px;"></td>
                    <td class="c">
                        <div class="bold" style="font-size: 13px;">BUREAU OF FIRE PROTECTION</div>
                        <div class="small">Regional Office - NCR</div>
                        <div class="small">Fire District II, NCR</div>
                        <div class="small">Malabon City Fire Station</div>
                        <div class="small">Pascual Ave., Potrero Malabon City</div>
                    </td>
                </tr>
            </table>
        </td>
        <td class="c" style="vertical-align: middle;">
            <div class="bold" style="font-size: 36px; line-height: 1;">FSIC</div>
            <div class="bold small">APPLICATION NUMBER</div>
            <table class="appno" style="width: auto; margin: 3px auto 0;"><tr>@for($i = 0; $i < 8; $i++)<td></td>@endfor</tr></table>
        </td>
    </tr>
</table>

@if($part === 'form')
<div class="bold it" style="font-size: 12px; margin-top: 2px;">FIRE SAFETY INSPECTION CERTIFICATE APPLICATION FORM</div>
<div class="bold it small" style="margin-top: 2px;">CHECK BOX OF CERTIFICATE APPLIED FOR</div>

<table class="t" style="margin-top: 2px;">
    <tr><td class="bold" style="width: 42%;">NAME OF OWNER</td><td colspan="2" class="v">{{ $f['owner_name'] }}</td></tr>
    <tr><td class="bold">BUILDING/FACILITY/STRUCTURE/BUSINESS/<br>ESTABLISHMENT NAME</td><td colspan="2" class="v" style="vertical-align: middle;">{{ $f['business_name'] }}{{ $f['trade_name'] !== '' ? ' ('.$f['trade_name'].')' : '' }}</td></tr>
    <tr><td class="bold">EXACT ADDRESS</td><td colspan="2" class="v">{{ $f['address'] }}</td></tr>
    <tr><td class="bold">AUTHORIZED REPRESENTATIVE</td><td colspan="2" class="v">{{ $rep }}</td></tr>
    <tr><td class="bold">TYPE OF OCCUPANCY/ BUSINESS NATURE</td><td colspan="2" class="v">{{ $occ !== '' ? $occ : ($f['lines'][0]['line'] ?? '') }}</td></tr>
    <tr>
        <td colspan="2" style="width: 64%;">TOTAL FLOOR AREA (M²) : <span class="v">{{ $f['area'] }}</span></td>
        <td>NO. OF STOREY: <span class="v">{{ $storeys }}</span></td>
    </tr>
    <tr>
        <td colspan="2">CONTACT NUMBER &nbsp;&nbsp;: <span class="v">{{ $f['contact'] }}</span></td>
        <td>EMAIL ADDRESS: <span class="v">{{ $f['email'] }}</span></td>
    </tr>
    <tr><td colspan="3" class="hdr">ATTACHED DOCUMENTARY REQUIREMENTS</td></tr>
    <tr><td colspan="3" class="c bold"><span class="ck" style="font-size: 13px;">{{ $ck($forOccupancy) }}</span> FSIC FOR CERTIFICATE OF OCCUPANCY</td></tr>
    <tr>
        <td colspan="3" class="small" style="line-height: 1.45;">
            [ &nbsp;] ENDORSEMENT FROM OFFICE OF THE BUILDING OFFICIAL (OBO)<br>
            [ &nbsp;] CERTIFICATE OF COMPLETION<br>
            [ &nbsp;] CERTIFIED TRUE COPY OF ASSESSMENT FEE FOR SECURING CERTIFICATE OF OCCUPANCY FROM OBO<br>
            [ &nbsp;] AS-BUILT PLAN (IF NECESSARY)<br>
            [ &nbsp;] ONE (1) SET OF FIRE SAFETY COMPLIANCE AND COMMISSIONING REPORT (FSCCR) (IF NECESSARY)
        </td>
    </tr>
    <tr><td colspan="3" class="c bold">FSIC FOR BUSINESS PERMIT</td></tr>
    <tr>
        <td colspan="2" class="c bold"><span class="ck" style="font-size: 13px;">{{ $ck($forBusiness && ! $renewal) }}</span> FOR NEW BUSINESS</td>
        <td class="c bold"><span class="ck" style="font-size: 13px;">{{ $ck($forBusiness && $renewal) }}</span> FOR RENEWAL OF BUSINESS</td>
    </tr>
    <tr>
        <td colspan="2" class="small" style="height: 120px; line-height: 1.45;">
            {{ $t('VALID_COO') }} CERTIFIED TRUE COPY OF VALID CERTIFICATE OF OCCUPANCY<br>
            {{ $t('BPLO_ASSESSMENT_NEW') }} ASSESSMENT OF BUSINESS PERMIT FEE/ TAX ASSESSMENT BILL FROM BPLO<br>
            {{ $t('NO_CHANGES_AFFIDAVIT') }} AFFIDAVIT OF UNDERTAKING THAT THERE WAS NO SUBSTANTIAL CHANGES MADE ON BUILDING/ESTABLISHMENT<br>
            {{ $t('FIRE_INSURANCE_NEW') }} COPY OF FIRE INSURANCE (IF NECESSARY)
        </td>
        <td class="small" style="line-height: 1.45;">
            {{ $t('BPLO_ASSESSMENT_RENEWAL') }} ASSESSMENT OF THE BUSINESS PERMIT FEE/TAX ASSESSMENT BILL FROM BPLO<br>
            {{ $t('FIRE_INSURANCE_RENEWAL') }} COPY OF FIRE INSURANCE (IF NECESSARY)<br>
            {{ $t('FSMR') }} ONE (1) SET OF FIRE SAFETY MAINTENANCE REPORT (FSMR) (IF NECESSARY)<br>
            {{ $t('HOT_WORK') }} FIRE SAFETY CLEARANCE FOR WELDING, CUTTING AND OTHER HOT WORK OPERATIONS (IF REQUIRED)
        </td>
    </tr>
    <tr>
        <td colspan="3" style="padding: 3px 5px;">
            <div class="bold it small">NOTE: <span style="font-weight: normal;">Incomplete documentary requirements will be returned to the applicant.</span></div>
            <div class="c" style="margin-top: 2px;">I hereby certify the correctness of the information provided above and the completeness of the attached documents.</div>
            <table class="plain" style="margin-top: 22px;">
                <tr>
                    <td style="width: 70%; padding: 0 40px;"><div class="c v">{{ $rep !== '' ? $rep : $f['owner_name'] }}</div><div class="sig" style="font-weight: normal;">OWNER/AUTHORIZED REPRESENTATIVE'S SIGNATURE OVER PRINTED NAME</div></td>
                    <td style="padding: 0 30px;"><div class="c v">&nbsp;</div><div class="sig" style="font-weight: normal;">DATE</div></td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td colspan="2" style="height: 46px; vertical-align: bottom;">VERIFIED BY BFP-CRO: ______________________________</td>
        <td style="vertical-align: bottom;"><div class="sig" style="font-weight: normal; margin: 0 30px;">DATE/TIME</div></td>
    </tr>
    <tr><td colspan="3" class="hdr">FSIC MONITORING (TO BE FILLED-UP BY BFP PERSONNEL ONLY)</td></tr>
    <tr>
        <td colspan="3" style="padding: 0;">
            <table class="mon">
                <tr>@foreach(['CRO', 'FCA', 'FCCA', 'C,FSES', 'FSI', 'C,FSES', 'CFM/MFM', 'CRO'] as $h)<td colspan="2" class="h">{{ $h }}</td>@endforeach</tr>
                <tr>@for($i = 0; $i < 8; $i++)<td colspan="2">DATE:</td>@endfor</tr>
                <tr>@for($i = 0; $i < 8; $i++)<td class="c">IN</td><td class="c">OUT</td>@endfor</tr>
                <tr>@for($i = 0; $i < 16; $i++)<td style="height: 14px;"></td>@endfor</tr>
            </table>
        </td>
    </tr>
    <tr>
        <td colspan="3" class="c small">
            PAALALA: “MAHIGPIT NA IPINAGBABAWAL NG PAMUNUAN NG BUREAU OF FIRE PROTECTION SA MGA KAWANI NITO ANG MAGBENTA O<br>
            MAGREKOMENDA NG ANUMANG BRAND NG FIRE EXTINGUISHER”
            <div class="bold" style="font-size: 10.5px;">“FIRE SAFETY IS OUR MAIN CONCERN”</div>
        </td>
    </tr>
</table>
<div class="bold small">BFP-QSF-FSED-002 REV.02 (08.24.20)</div>
<div class="cut"></div>
@else
<div class="c bold" style="font-size: 12px; margin-top: 10px;">CLAIM STUB</div>
<div style="margin-top: 10px;">CERTIFIED BY:</div>
<table style="margin-top: 22px;">
    <tr>
        <td style="width: 45%;"><div class="sig">CUSTOMER RELATION OFFICER</div></td>
        <td></td>
        <td style="width: 30%;"><div class="sig">DATE</div></td>
    </tr>
</table>
<div class="bold it small" style="margin-top: 8px;">NOTE: <span style="font-weight: normal;">AUTHORIZED REPRESENTATIVE MUST PRESENT AN AUTHORIZATION LETTER AND COPY OF OWNER'S IDENTIFICATION CARD</span></div>
<div class="c small" style="margin-top: 3px;">
    PAALALA: “MAHIGPIT NA IPINAGBABAWAL NG PAMUNUAN NG BUREAU OF FIRE PROTECTION SA MGA KAWANI NITO ANG MAGBENTA O<br>
    MAGREKOMENDA NG ANUMANG BRAND NG FIRE EXTINGUISHER”
    <div class="bold" style="font-size: 10.5px;">“FIRE SAFETY IS OUR MAIN CONCERN”</div>
</div>
<div style="border-top: 5px solid #000; margin-top: 2px;"></div>
<div class="bold small">BFP-QSF-FSED-002 REV.02 (08.24.20)</div>
@endif
@endforeach
</body>
</html>
