<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.forms.partials.style')
    <style>
        body { font-size: 9px; }
        .q td { border: 1px solid #000; padding: 3px 5px; }
        .q .k { font-weight: bold; font-size: 7.8px; }
        .s { font-weight: bold; padding: 4px 5px; border: 1px solid #000; background: #000; color: #fff; }
    </style>
</head>
<body>
@include('pdf.forms.partials.generated')
{{--
    MCG-CENRO-FO-001 v2.0 — APPLICATION FOR CERTIFICATE OF ENVIRONMENTAL
    CLEARANCE (CEC). No photograph of the CENRO paper was sent, so this follows
    the CENRO sheet in the system field for field (OWNERSHIP AND DOCUMENTATION,
    then the DENR requirements the line of business calls for), under CENRO's
    own letterhead from config/biztrack.php. REQUIREMENTS FOR APPLICATION is the
    paper's four-item list the sheet's notes record.
--}}
@php $lh = config('biztrack.letterheads.CENRO', []); @endphp
@include('pdf.forms.partials.office-head', [
    'office' => 'CITY ENVIRONMENT AND NATURAL RESOURCES OFFICE',
    'officeAddress' => $lh['address'] ?? 'Malabon City Hall, F. Sevilla Blvd., Brgy. Tañong, Malabon City',
    'officeLines' => 'Trunkline No.: '.($lh['trunkline'] ?? '8281-4999').' | Email: '.($lh['email'] ?? '').' | Website: '.($lh['website'] ?? 'malabon.gov.ph'),
    'title' => 'APPLICATION FOR CERTIFICATE OF ENVIRONMENTAL CLEARANCE (CEC)',
    'subtitle' => null,
    'docId' => 'MCG-CENRO-FO-001',
    'docVersion' => '2.0',
])
@php
    $g = fn (string $k) => trim((string) ($d[$k] ?? ''));
    $initial = ! str_starts_with($g('application_type'), 'Renewal');
    $structures = ['sole_proprietorship' => 'Sole Proprietorship', 'partnership' => 'Partnership', 'corporation' => 'Corporation', 'cooperative' => 'Cooperative'];
@endphp

<table class="q" style="margin-top: 6px;">
    <tr><td colspan="4" class="s">OWNERSHIP AND DOCUMENTATION</td></tr>
    <tr>
        <td style="width: 25%;"><div class="k">DATE</div><span class="v">{{ $g('application_date') !== '' ? \App\Support\PaperForms::date($g('application_date')) : $saved_on }}</span></td>
        <td style="width: 25%;"><div class="k">CONTROL NO.</div><span class="muted small">(for office use)</span></td>
        <td colspan="2"><div class="k">TYPE OF APPLICATION</div>
            <span class="ck">{{ $ck($initial) }}</span> Initial Application &nbsp;&nbsp; <span class="ck">{{ $ck(! $initial) }}</span> Renewal of CEC
        </td>
    </tr>
    <tr>
        <td colspan="4"><div class="k">TYPE OF BUSINESS</div>
            @foreach($structures as $key => $label)
                <span class="ck">{{ $ck($f['registration_type'] === $key) }}</span> {{ $label }} &nbsp;&nbsp;
            @endforeach
        </td>
    </tr>
    <tr>
        <td><div class="k">BUSINESS AREA (in sq. m.)</div><span class="v">{{ $f['area'] }}</span></td>
        <td><div class="k">TOTAL NO. OF EMPLOYEES — MALE</div><span class="v">{{ $f['employees_male'] }}</span></td>
        <td colspan="2"><div class="k">TOTAL NO. OF EMPLOYEES — FEMALE</div><span class="v">{{ $f['employees_female'] }}</span></td>
    </tr>
    <tr>
        <td colspan="2"><div class="k">OWNER (Family Name, First Name, Middle Name)</div><span class="v">{{ trim($f['owner_surname'].', '.$f['owner_given'].' '.$f['owner_middle'], ', ') ?: $f['owner_name'] }}</span></td>
        <td colspan="2"><div class="k">BUSINESS NAME</div><span class="v">{{ $f['business_name'] }}</span></td>
    </tr>
    <tr>
        <td colspan="2"><div class="k">OWNER'S ADDRESS</div><span class="v">{{ $g('owner_address') ?: $f['home_address'] }}</span></td>
        <td colspan="2"><div class="k">BUSINESS ADDRESS</div><span class="v">{{ $f['address'] }}</span></td>
    </tr>
    <tr>
        <td colspan="2"><div class="k">BIRTHDAY</div><span class="v">{{ $g('owner_birthday') !== '' ? \App\Support\PaperForms::date($g('owner_birthday')) : '' }}</span></td>
        <td colspan="2"><div class="k">SEX</div>
            <span class="ck">{{ $ck($f['owner_gender'] === 'Male') }}</span> Male &nbsp; <span class="ck">{{ $ck($f['owner_gender'] === 'Female') }}</span> Female
        </td>
    </tr>
    <tr>
        <td colspan="2"><div class="k">LINE OF BUSINESS</div><span class="v">{{ implode('; ', array_filter(array_column($f['lines'], 'line'))) }}</span></td>
        <td colspan="2"><div class="k">PRODUCTS / SERVICES</div><span class="v">{{ implode('; ', array_filter(array_column($f['lines'], 'products'))) }}</span></td>
    </tr>
    <tr>
        <td colspan="2"><div class="k">CONTACT NUMBERS — LANDLINE</div><span class="v">{{ $f['telephone'] }}</span></td>
        <td colspan="2"><div class="k">CONTACT NUMBERS — MOBILE NO.</div><span class="v">{{ $f['mobile'] }}</span></td>
    </tr>

    <tr><td colspan="4" class="s">DENR REQUIREMENTS FOR THIS LINE OF BUSINESS</td></tr>
    <tr><td class="k">BASIS</td><td colspan="3" class="v">{{ $g('denr_basis') }}</td></tr>
    <tr><td class="k">DENR CERTIFICATE</td><td class="v">{{ $g('denr_certificate') }}</td><td class="k">POLLUTION CONTROL OFFICER</td><td class="v">{{ $g('denr_pco') }}</td></tr>
    <tr><td class="k">DENR PERMITS</td><td colspan="3" class="v">{{ $g('denr_permits') }}</td></tr>
    @if($g('denr_remarks') !== '')
        <tr><td class="k">REMARKS</td><td colspan="3">{{ $g('denr_remarks') }}</td></tr>
    @endif

    <tr><td colspan="4" class="s">REQUIREMENTS FOR APPLICATION</td></tr>
    <tr>
        <td colspan="4" style="line-height: 1.6;">
            <span class="ck">☐</span> Application for Renewal / New Business<br>
            <span class="ck">☐</span> Tax Order of Payment and Official Receipt<br>
            <span class="ck">☐</span> Business Permit<br>
            <span class="ck">{{ $ck(! $initial && in_array('CEC_REQ_PREVIOUS', $f['documents'], true)) }}</span> Certificate of Environmental Compliance (Previous Year) <span class="small">(renewal only)</span>
        </td>
    </tr>
</table>

<div style="margin-top: 8px; text-align: justify;">
    <span class="ck">{{ $ck($g('certified') === 'yes') }}</span>
    I hereby certify that the foregoing information is true and correct, and I undertake to comply with the environmental laws, rules
    and regulations and the conditions that the City Environment and Natural Resources Office may impose on this establishment.
</div>
<table style="margin-top: 26px;">
    <tr>
        <td style="width: 55%; padding: 0 30px;"><div class="c v">{{ $f['owner_name'] }}</div><div class="sig" style="font-weight: normal;">SIGNATURE OF OWNER/APPLICANT OVER PRINTED NAME</div></td>
        <td style="padding: 0 30px;"><div class="c v">&nbsp;</div><div class="sig" style="font-weight: normal;">DATE</div></td>
    </tr>
</table>

<table class="q" style="margin-top: 10px;">
    <tr><td colspan="4" class="s">FOR CENRO USE ONLY</td></tr>
    <tr><td style="width: 22%;" class="k">TRACKING ID</td><td class="v" style="width: 28%;">{{ $f['tracking_id'] }}</td><td class="k" style="width: 20%;">FEES ASSESSED</td><td class="v">{{ $fee !== '' ? '₱ '.$fee : '' }}</td></tr>
    <tr><td class="k">DATE OF INSPECTION</td><td></td><td class="k">O.R. NO. / DATE</td><td></td></tr>
    <tr><td class="k">EVALUATED BY</td><td style="height: 26px;"></td><td class="k">APPROVED BY</td><td></td></tr>
</table>
</body>
</html>
