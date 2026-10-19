<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.forms.partials.style')
    <style>
        body { font-size: 9px; }
        .q td { border: 1px solid #000; padding: 3px 5px; }
        .q td.lab { font-weight: bold; width: 30%; }
        .s { font-weight: bold; padding: 4px 5px; border: 1px solid #000; background: #000; color: #fff; }
    </style>
</head>
<body>
@include('pdf.forms.partials.generated')
{{--
    APPLICATION FOR SANITARY PERMIT TO OPERATE — City Health Office.

    There is NO paper sanitary form (client, img-392: "We don't have a paper
    copy of the sanitary permit … You may base it off from the common sanitary
    permit fields"), so the system's sheet is the form: sections A to D in its
    order (OFFICE_FORM_FIELD_ORDER.SANITARY), the choices printed as boxes with
    the applicant's ticked, then the certification and an inspection / office
    box laid out the way the common PD 856 sanitary permit applications lay
    them out. The form code line says what it is rather than inventing a
    "MCG-CHO-FO" number the office never issued.
--}}
@include('pdf.forms.partials.office-head', [
    'office' => 'CITY HEALTH OFFICE · SANITATION DIVISION',
    'officeAddress' => 'Malabon City Hall, F. Sevilla Blvd., Brgy. Tañong, Malabon City',
    'officeLines' => 'Trunkline No.: 8281-4999 | Website: malabon.gov.ph',
    'title' => 'APPLICATION FOR SANITARY PERMIT TO OPERATE',
    'subtitle' => 'Pursuant to PD 856, Code on Sanitation of the Philippines',
    'docId' => 'BizTrack sanitary sheet (no paper form)',
    'docVersion' => '—',
])
@php
    $g = fn (string $k) => trim((string) ($d[$k] ?? ''));
    $opts = function (string $key, array $choices) use ($g, $ck) {
        $v = $g($key);
        $out = [];
        foreach ($choices as $c) { $out[] = '<span class="ck">'.$ck($v === $c).'</span> '.e($c); }
        if ($v !== '' && ! in_array($v, $choices, true)) { $out[] = '<span class="v">'.e($v).'</span>'; }
        return implode(' &nbsp; ', $out);
    };
    $isFood = $g('sanitary_classification') === 'Food Establishment';
    $nature = $g('application_type');
@endphp

<table class="q" style="margin-top: 6px;">
    <tr><td colspan="4" class="s">BUSINESS DETAILS</td></tr>
    <tr>
        <td class="lab">Name of Establishment</td><td colspan="3" class="v">{{ $f['business_name'] }}{{ $f['trade_name'] !== '' ? ' ('.$f['trade_name'].')' : '' }}</td>
    </tr>
    <tr><td class="lab">Name of Owner/Operator</td><td colspan="3" class="v">{{ $f['owner_name'] }}</td></tr>
    <tr><td class="lab">Business Address</td><td colspan="3" class="v">{{ $f['address'] }}</td></tr>
    <tr><td class="lab">Line of Business</td><td colspan="3" class="v">{{ implode('; ', array_filter(array_column($f['lines'], 'line'))) }}</td></tr>
    <tr>
        <td class="lab">Contact No.</td><td class="v" style="width: 22%;">{{ $f['contact'] }}</td>
        <td class="bold" style="width: 14%;">E-mail</td><td class="v">{{ $f['email'] }}</td>
    </tr>

    <tr><td colspan="4" class="s">A. APPLICATION DETAILS</td></tr>
    <tr>
        <td class="lab">Type of Application</td>
        <td colspan="3"><span class="ck">{{ $ck($nature === 'New') }}</span> New &nbsp; <span class="ck">{{ $ck($nature === 'Renewal') }}</span> Renewal</td>
    </tr>
    <tr>
        <td class="lab">Date of Application</td><td class="v">{{ $g('application_date') !== '' ? \App\Support\PaperForms::date($g('application_date')) : $saved_on }}</td>
        <td class="bold">Sanitary Permit No.</td><td><span class="muted small">(to be assigned)</span></td>
    </tr>

    <tr><td colspan="4" class="s">B. ESTABLISHMENT SANITATION PROFILE</td></tr>
    <tr><td class="lab">Sanitary Classification</td><td colspan="3">{!! $opts('sanitary_classification', ['Food Establishment', 'Non-Food Establishment', 'Personal / Public Service', 'Industrial']) !!}</td></tr>
    <tr>
        <td class="lab">No. of Employees</td>
        <td colspan="3">Male: <span class="v">{{ $g('employees_male') }}</span> &nbsp;&nbsp; Female: <span class="v">{{ $g('employees_female') }}</span> &nbsp;&nbsp; Total: <span class="v">{{ $g('employees_total') }}</span></td>
    </tr>
    <tr><td class="lab">No. of Workers Requiring Health Certificates</td><td class="v">{{ $g('workers_requiring_health_certs') }}</td><td class="bold">Floor Area (sq. m.)</td><td class="v">{{ $g('total_floor_area_sqm') ?: $f['area'] }}</td></tr>
    <tr><td class="lab">Operating Hours</td><td colspan="3" class="v">{{ $g('operating_hours') }}</td></tr>

    <tr><td colspan="4" class="s">C. WATER AND SANITATION FACILITIES</td></tr>
    <tr><td class="lab">Water Source</td><td colspan="3">{!! $opts('water_source', ['Level III (Waterworks)', 'Deep Well', 'Bottled / Refill', 'Other']) !!}</td></tr>
    <tr><td class="lab">No. of Toilets</td><td class="v">{{ $g('toilets_count') }}</td><td class="bold">Separate for Men and Women</td><td>{!! $opts('toilets_separate_sexes', ['Yes', 'No']) !!}</td></tr>
    <tr><td class="lab">Type of Toilet</td><td colspan="3">{!! $opts('toilet_type', ['Water-sealed flush', 'Pour-flush', 'Other']) !!}</td></tr>
    <tr><td class="lab">Sewage Disposal</td><td colspan="3">{!! $opts('sewage_disposal', ['Public sewer (Maynilad)', 'Septic tank', 'Other']) !!}</td></tr>
    <tr><td class="lab">Solid Waste Disposal</td><td colspan="3">{!! $opts('solid_waste_disposal', ['City garbage collection', 'Private hauler', 'Composting / recycling', 'Other']) !!}</td></tr>
    <tr><td class="lab">Waste Segregation Practised</td><td colspan="3">{!! $opts('waste_segregation', ['Yes', 'No']) !!}</td></tr>
    <tr><td class="lab">Pest Control</td><td colspan="3">{!! $opts('pest_control', ['Contracted pest control service', 'Own measures', 'None']) !!}</td></tr>
    <tr><td class="lab">Last Pest Control Treatment</td><td colspan="3" class="v">{{ $g('pest_control_last_date') !== '' ? \App\Support\PaperForms::date($g('pest_control_last_date')) : '' }}</td></tr>

    <tr><td colspan="4" class="s">D. FOOD ESTABLISHMENT <span style="font-weight: normal;">(answer only if a Food Establishment)</span></td></tr>
    <tr><td class="lab">Seating Capacity</td><td class="v">{{ $isFood ? $g('seating_capacity') : '' }}</td><td class="bold">Food Preparation Area</td><td>{!! $isFood ? $opts('has_kitchen', ['Yes', 'No']) : '<span class="ck">☐</span> Yes &nbsp; <span class="ck">☐</span> No' !!}</td></tr>
    <tr><td class="lab">Refrigeration / Cold Storage</td><td colspan="3">{!! $isFood ? $opts('has_cold_storage', ['Yes', 'No']) : '<span class="ck">☐</span> Yes &nbsp; <span class="ck">☐</span> No' !!}</td></tr>
</table>

<div style="margin-top: 8px; text-align: justify;">
    <span class="ck">{{ $ck($g('certified') === 'yes') }}</span>
    I hereby certify that the information given above is true and correct, and I undertake to comply with the provisions of
    PD 856 (Code on Sanitation of the Philippines) and its implementing rules, and to allow the inspection of the establishment
    by the City Health Office at any reasonable time.
</div>
<table style="margin-top: 26px;">
    <tr>
        <td style="width: 55%; padding: 0 30px;"><div class="c v">{{ $f['owner_name'] }}</div><div class="sig" style="font-weight: normal;">SIGNATURE OF OWNER/OPERATOR OVER PRINTED NAME</div></td>
        <td style="padding: 0 30px;"><div class="c v">&nbsp;</div><div class="sig" style="font-weight: normal;">DATE</div></td>
    </tr>
</table>

<table class="q" style="margin-top: 10px;">
    <tr><td colspan="4" class="s">FOR CITY HEALTH OFFICE USE ONLY</td></tr>
    <tr><td class="lab">Tracking ID</td><td class="v" style="width: 22%;">{{ $f['tracking_id'] }}</td><td class="bold" style="width: 18%;">Fees Assessed</td><td class="v">{{ $fee !== '' ? '₱ '.$fee : '' }}</td></tr>
    <tr><td class="lab">Date of Inspection</td><td></td><td class="bold">O.R. No. / Date</td><td></td></tr>
    <tr><td class="lab">Inspection Findings</td><td colspan="3"><span class="ck">☐</span> Satisfactory &nbsp; <span class="ck">☐</span> Unsatisfactory &nbsp; Remarks: ____________________________________________</td></tr>
    <tr><td class="lab">Inspected by (Sanitary Inspector)</td><td style="height: 26px;"></td><td class="bold">Approved by</td><td></td></tr>
</table>
</body>
</html>
