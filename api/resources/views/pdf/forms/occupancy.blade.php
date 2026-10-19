<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.forms.partials.style')
    <style>
        body { font-size: 10.5px; line-height: 1.35; }
        .kv td { padding: 3px 4px; vertical-align: bottom; }
        .prc td { border-bottom: 1px solid #000; font-size: 7.6px; padding: 2px 3px; }
        .prc td.bl { border-left: 1px solid #000; }
        .chk td { padding: 3px 4px; vertical-align: top; }
    </style>
</head>
<body>
@include('pdf.forms.partials.generated')
{{--
    The OBO's UNIFIED APPLICATION FORM FOR CERTIFICATE OF OCCUPANCY AND FIRE
    SAFETY INSPECTION CERTIFICATE (photo img-334/img-335), then its CHECKLIST
    OF REQUIREMENTS FOR THE APPLICATION OF OCCUPANCY PERMIT (img-332).

    Every applicant line is filled from the Occupancy sheet the owner filled
    in; "Requirements submitted" and the checklist tick where the owner
    attached that document to the sheet (OccupancyRequirements). "Attested by"
    — the architect or civil engineer, with PRC/PTR/CTC — is the professional's
    to sign and seal and the system holds none of it, so it prints blank.
--}}
@php
    $scope = (string) ($d['application_type'] ?? '');
    $g = fn (string $k) => trim((string) ($d[$k] ?? ''));
    $tk = fn (string $k) => $ticks[$k] ?? false;
    $date = fn (string $k) => $g($k) !== '' ? \App\Support\PaperForms::date($g($k)) : '';
@endphp
<table>
    <tr>
        <td style="width: 22%; vertical-align: middle;">@if($f['seal'])<img src="{{ $f['seal'] }}" style="height: 78px;" alt="">@endif</td>
        <td class="c" style="vertical-align: middle;">
            <div>Republic of the Philippines</div>
            <div>Department of Public Works and Highways</div>
            <div class="bold" style="font-size: 13px;">CITY OF MALABON</div>
            <div class="bold" style="font-size: 13px;">OFFICE OF THE BUILDING OFFICIAL</div>
        </td>
        <td style="width: 22%;"></td>
    </tr>
</table>
<div class="c bold" style="font-size: 14.5px; margin-top: 8px; line-height: 1.2;">UNIFIED APPLICATION FORM FOR CERTIFICATE OF OCCUPANCY<br>AND FIRE SAFETY INSPECTION CERTIFICATE</div>
<table style="width: 50%; margin: 6px auto 0;">
    <tr>
        <td class="c"><span class="ck" style="font-size: 14px;">{{ $ck($scope === 'Full') }}</span> FULL</td>
        <td class="c"><span class="ck" style="font-size: 14px;">{{ $ck($scope === 'Partial') }}</span> PARTIAL</td>
    </tr>
</table>

<table class="kv" style="width: 52%; margin-top: 6px;">
    <tr><td style="width: 42%;">Building Permit No.</td><td class="fill v">{{ $g('building_permit_no') }}</td></tr>
    <tr><td>Date Issued</td><td class="fill v">{{ $date('building_permit_date') }}</td></tr>
    <tr><td>FSEC No.</td><td class="fill v">{{ $g('fsec_no') }}</td></tr>
    <tr><td>Date Issued</td><td class="fill v">{{ $date('fsec_date') }}</td></tr>
</table>

<table class="kv" style="margin-top: 6px;">
    <tr><td style="width: 62%;"></td><td class="fill v c">{{ $date('application_date') ?: $saved_on }}</td></tr>
    <tr><td></td><td class="c small">(Date)</td></tr>
</table>
<table class="kv">
    <tr>
        <td style="width: 24%;">Name of Owner/Permittee:</td>
        <td class="fill v c" style="width: 28%;">{{ $f['owner_surname'] }}</td>
        <td class="fill v c" style="width: 28%;">{{ $f['owner_given'] }}</td>
        <td class="fill v c">{{ $f['owner_mi'] }}</td>
    </tr>
    <tr><td></td><td class="c small">(Last Name)</td><td class="c small">(Given Name)</td><td class="c small">(Middle Initial)</td></tr>
</table>
<table class="kv">
    <tr>
        <td style="width: 25%;">Address of Owner/Permittee:</td>
        <td class="fill v" style="width: 45%;">{{ $g('owner_address') ?: $f['home_address'] }}</td>
        <td style="width: 9%;">ZIP Code:</td>
        <td class="fill v">{{ $g('owner_zip') ?: $f['home_postal'] }}</td>
    </tr>
    <tr>
        <td colspan="2" class="fill"></td>
        <td>Tel No.:</td>
        <td class="fill v">{{ $g('owner_tel') }}</td>
    </tr>
</table>

<div class="c bold" style="margin-top: 10px;">Requirements submitted:</div>
<table class="chk" style="margin-top: 4px;">
    <tr>
        <td style="width: 5%;" class="ck">{{ $ck($tk('BUILDING_PERMIT') || $tk('PLANS')) }}</td>
        <td style="width: 47%;">Issue Building Permit and Plans (1 set)</td>
        <td style="width: 5%;" class="ck">{{ $ck($tk('FIRE_SAFETY_CHECKLIST')) }}</td>
        <td>Issued Fire Safety Evaluation Clearance (FSEC)</td>
    </tr>
    <tr>
        <td class="ck">{{ $ck($tk('CONSTRUCTION_LOGBOOK')) }}</td>
        <td>Construction Logbook, <span class="small">signed and sealed by the Owner's Architect or Civil Engineer who undertook full-time inspection and Supervision</span></td>
        <td class="ck">☐</td>
        <td>Others documents deemed necessary: (Specify)<div class="fill" style="height: 13px;"></div><div class="fill" style="height: 13px;"></div><div class="fill" style="height: 13px;"></div></td>
    </tr>
    <tr><td class="ck">{{ $ck($tk('SITE_PHOTOS')) }}</td><td>Photos of Site / Project showing substantial completion</td><td colspan="2"></td></tr>
    <tr><td class="ck">{{ $ck($tk('COMPLETION')) }}</td><td>Certificate of Completion, <span class="small">duly notarized</span></td><td colspan="2"></td></tr>
    <tr><td class="ck">{{ $ck($tk('AS_BUILT')) }}</td><td>As-Built Plans and Specifications, <span class="small">duly signed and sealed by Respective design professionals (if there are changes made from the Submitted building plans) (4 sets)</span></td><td colspan="2"></td></tr>
</table>

<table class="kv" style="margin-top: 8px;">
    <tr><td style="width: 18%;">Name of Project:</td><td colspan="2" class="fill v">{{ $g('project_name') ?: $f['business_name'] }}</td></tr>
    <tr><td>Location of Project:</td><td colspan="2" class="fill v">{{ $g('project_location') ?: $f['address'] }}</td></tr>
    <tr><td></td><td style="width: 28%;">Use/Character of Occupancy:</td><td class="fill v" style="width: 40%;">{{ $g('occupancy_type') }}</td></tr>
    <tr><td></td><td>No. of Storey/s:</td><td class="fill v">{{ $g('building_storeys') }}</td></tr>
    <tr><td></td><td>No. of Units:</td><td class="fill v">{{ $g('building_units') }}</td></tr>
    <tr><td></td><td>Total Floor Area (Square Meters):</td><td class="fill v">{{ $g('total_floor_area_sqm') ?: $f['area'] }}</td></tr>
    <tr><td></td><td>Date of Completion:</td><td class="fill v">{{ $date('completion_date') }}</td></tr>
</table>

<table style="margin-top: 14px;">
    <tr>
        <td style="width: 50%;">
            Submitted by:
            <div style="margin: 28px 20px 0 20px;" class="c v">{{ $f['owner_name'] }}</div>
            <div style="margin: 0 20px; border-top: 1px solid #000;"></div>
            <div style="margin-left: 20px;" class="bold">OWNER/PERMITTEE</div>
            <div style="margin-left: 20px;" class="small">Signature Over Printed Name)</div>
            <table class="kv small" style="margin-left: 16px; width: 88%;">
                <tr><td style="width: 50%;">Community Tax Certificate No.</td><td class="fill v">{{ $g('owner_ctc_no') }}</td></tr>
                <tr><td>Date Issued:</td><td class="fill v">{{ $date('owner_ctc_date') }}</td></tr>
                <tr><td>Place Issued:</td><td class="fill v">{{ $g('owner_ctc_place') }}</td></tr>
            </table>
        </td>
        <td>
            Attested by:
            <div class="bold small" style="border-bottom: 1px solid #000; margin-top: 6px;">FULL-TIME INSPECTOR OR SUPERVISOR OF CONSTRUCTION</div>
            <div style="margin: 30px 20px 0; border-top: 1px solid #000;"></div>
            <div class="c bold">ARCHITECT OR CIVIL ENGINEER</div>
            <div class="c small">(Signed and Sealed Over Printed Name)</div>
            <div class="c small">Date______________</div>
            <table class="prc" style="margin-top: 4px;">
                <tr><td style="width: 50%;">PRC No.</td><td class="bl" colspan="2">Validity</td></tr>
                <tr><td>PTR No.</td><td class="bl" colspan="2">Date Issued</td></tr>
                <tr><td>Issued at</td><td class="bl" colspan="2">TIN</td></tr>
                <tr><td style="width: 34%;">CTC No.</td><td class="bl">Date Issued</td><td class="bl">Issued at</td></tr>
            </table>
        </td>
    </tr>
</table>

<div style="margin-top: 14px; font-size: 9.6px;">
    I have read this form, understood its contents and consent to the processing of my personal data. I understand that my consent does
    not preclude the existence of other criteria for lawful processing of personal data and does not waive any of my rights under the <i>Data
    Privacy Act of 2012 and other applicable laws.</i>
</div>
<table style="margin-top: 22px;">
    <tr><td style="width: 46%;"></td><td><div class="c v">{{ $f['owner_name'] }}</div><div class="sig" style="font-weight: normal; font-size: 9px;">SIGNATURE OVER PRINTED NAME OF OWNER/ APPLICANT</div></td></tr>
</table>

<div class="page-break"></div>

<table>
    <tr>
        <td style="width: 26%; text-align: right; vertical-align: middle;">@if($f['seal'])<img src="{{ $f['seal'] }}" style="height: 56px;" alt="">@endif</td>
        <td style="vertical-align: middle; padding-left: 14px;">
            <div class="bold small">REPUBLIC OF THE PHILIPPINES</div>
            <div class="bold small">CITY OF MALABON</div>
            <div class="bold small">CITY ENGINEERING DEPARTMENT</div>
            <div class="tiny bold">10/F Malabon City Hall, F. Sevilla Blvd., Brgy. Tañong, Malabon City, 1470 Philippines</div>
            <div class="tiny bold">Trunkline No. 8281-4999 | Email: CED@malabon.gov.ph | Website: malabon.gov.ph</div>
        </td>
    </tr>
</table>
<div class="c bold" style="text-decoration: underline; font-size: 11.5px; margin-top: 10px;">CHECKLIST OF REQUIREMENTS FOR THE APPLICATION OF OCCUPANCY PERMIT</div>
@php
    $list = [
        ['FORM', 'Two (2) copies of duly accomplished Application Form for Certificate of Occupancy'],
        ['COMPLETION', 'Two (2) copies of duly accomplished and notarized Certificate of Completion'],
        [null, 'One (1) copy of duly accomplished Fire Safety Inspection Certificate Application Form'],
        ['BUILDING_PERMIT', 'Two (2) Photocopies of Building Permit and issued Ancillary Permits (ex. Electrical, Mechanical, Electronics, Sanitary/ Plumbing)'],
        ['RECEIPTS', 'Two (2) Photocopies of official receipts for issued Building Permit, Ancillary Permits (ex. Electrical, Mechanical, Electronics, Sanitary/ Plumbing), Environmental Clearance, Contractors Tax, BFP)'],
        ['PLANS', 'Approved Building Plans or Three (3) sets of As-built plans in case of changes in the approved plan'],
        ['RELOCATION_SURVEY', '<i>Actual relocation survey shall be conducted by a duly licensed Geodetic Engineer</i>'],
        ['CHANGE_OF_PROFESSIONAL', 'Affidavit of Change of Professional in case of changes in the professional in charge of the project'],
        ['PROFESSIONAL_IDS', 'Two (2) photocopies of latest PRC Identification Card and Professional Tax Receipt, signed (three original specimen signatures) and sealed by the respective professionals in case of change of professional'],
        ['ZONING_CLEARANCE', 'Two (2) Photocopies of Local/ Zoning Clearance'],
        ['FIRE_SAFETY_CHECKLIST', 'Two (2) Photocopies of Owner\'s Copy of Fire Safety Checklist and corresponding Fire Safety Evaluation Clearance (FSEC)'],
        ['OWNER_ID', 'Photocopy of government-issued ID of applicant/owner/ co-owners with three (3) original specimen signature'],
        ['SPA', 'Authorization Letter to transact on behalf of the applicant/ owner or SPA from owner/applicant and photocopy of valid ID of authorized representative with three (3) original specimen signature'],
    ];
@endphp
<table class="chk" style="width: 86%; margin: 8px auto 0; font-size: 9.6px;">
    @foreach($list as [$key, $label])
        <tr>
            <td class="ck" style="width: 4%;">{{ $ck($key !== null && $tk($key)) }}</td>
            <td style="text-align: justify;">{!! $label !!}</td>
        </tr>
    @endforeach
</table>
</body>
</html>
