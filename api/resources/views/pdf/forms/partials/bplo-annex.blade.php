{{--
    ANNEX 1 (PAGE 2 OF 2) — the LGU verification table and the Bureau of Fire
    Protection section, as the FO-001 paper prints them.

    Compliance is ticked only where an office has RULED: an issued clearance is
    Yes, a refused one is No. Anything still in flight is left blank for the
    evaluator, and "Evaluated by" is always left for their initials — a printed
    name there would be a signature nobody gave. Market Clearance stays on the
    table because it is on the paper; the system stopped issuing it on
    6 September 2026, so it is never ticked.
--}}
@php
    $rows = [
        ['Occupancy Permit', 'Office of the Local Building Official', 'OCCUPANCY'],
        ['Sanitary Permit/Health Certificate', 'City Health Office', 'SANITARY'],
        ['City Environmental Certificate', 'City Environmental and Natural Resources Office', 'CEC'],
        ['Market Clearance (for Stall Holders)', 'Office of the City Market Administrator', null],
        ['Fire Safety Inspection Certificate', 'Bureau of Fire Protection', 'FSIC'],
        ['Zoning Clearance', 'Planning/Zoning Office', 'ZONING'],
    ];
@endphp
<table class="box" style="margin-top: 8px;">
    <tr><td colspan="6" class="bold bb" style="padding: 3px 26px;">ANNEX 1 (PAGE 2 OF 2) {{ $annexTitle }}</td></tr>
    <tr><td colspan="6" class="bold bb" style="padding: 3px 26px;">I. LGU SECTION (The local BPLO or CTO shall fill-up this section)</td></tr>
    <tr>
        <td colspan="2" class="bold" style="padding-left: 60px;">1. VERIFICATION OF DOCUMENTS</td>
        <td colspan="3" class="bold c bl bb">COMPLIANCE</td>
        <td rowspan="2" class="bold c bl bb" style="width: 10%; vertical-align: middle;">Evaluated by</td>
    </tr>
    <tr>
        <td class="bold c bt br bb" style="width: 32%; vertical-align: middle; height: 26px;">DESCRIPTION</td>
        <td class="bold c bt bb" style="width: 38%; vertical-align: middle;">OFFICE/AGENCY</td>
        <td class="bold c bl bb" style="width: 5%; vertical-align: bottom;">Yes</td>
        <td class="bold c bl bb" style="width: 5%; vertical-align: bottom;">No</td>
        <td class="bold c bl bb" style="width: 10%;">Not required</td>
    </tr>
    @foreach($rows as [$desc, $office, $code])
        @php $mark = $code ? ($annex['verification'][$code] ?? null) : null; @endphp
        <tr>
            <td class="c br bb">{{ $desc }}</td>
            <td class="c bb">{{ $office }}</td>
            <td class="c bl bb ck">{{ $mark === 'yes' ? '✓' : '' }}</td>
            <td class="c bl bb ck">{{ $mark === 'no' ? '✓' : '' }}</td>
            <td class="c bl bb"></td>
            <td class="bl bb"></td>
        </tr>
    @endforeach
    <tr><td colspan="6" style="height: 14px;"></td></tr>
</table>

<table class="box" style="margin-top: 14px;">
    <tr><td colspan="2" class="bold bb" style="padding: 4px 26px;">II. BUREAU OF FIRE PROTECTION SECTION (APPLICATION FOR FIRE SAFETY INSPECTION CERTIFICATE)</td></tr>
    <tr><td style="width: 50%;"></td><td style="padding-top: 8px;">DATE: <span class="line v" style="min-width: 150px;">{{ $f['submitted_on'] }}</span></td></tr>
    <tr><td colspan="2" style="padding: 10px 20px 0;">TRACKING NUMBER: <span class="line v" style="min-width: 160px;">{{ $f['tracking_id'] }}</span></td></tr>
    <tr><td colspan="2" style="padding: 10px 20px 0;">(TO BE FILLED UP BY APPLICANT/OWNER)</td></tr>
    <tr><td colspan="2" style="padding: 10px 20px 0;">Name of Applicant/Owner: <span class="line v" style="min-width: 330px;">{{ $f['owner_name'] }}</span></td></tr>
    <tr><td colspan="2" style="padding: 10px 20px 0;">Name of Business: <span class="line v" style="min-width: 365px;">{{ $f['business_name'] }}</span></td></tr>
    <tr>
        <td style="padding: 10px 20px 0;">Total Floor Area: <span class="line v" style="min-width: 90px;">{{ $f['area'] !== '' ? $f['area'].' sq. m.' : '' }}</span></td>
        <td style="padding: 10px 20px 0;">Contact Number: <span class="line v" style="min-width: 150px;">{{ $f['contact'] }}</span></td>
    </tr>
    <tr><td colspan="2" style="padding: 10px 20px 0;">Address of Establishment: <span class="line v" style="min-width: 330px;">{{ $f['address'] }}</span></td></tr>
    <tr><td colspan="2" style="padding: 30px 30px 0;"><div class="sig" style="width: 210px; font-weight: normal;">Signature of Applicant/Owner</div></td></tr>
    <tr>
        <td style="padding: 16px 20px 6px;">
            Certified by:<br>Customer Relations Officer<br>
            Time and Date Received: <span class="line" style="min-width: 110px;"></span>
        </td>
        <td style="padding: 16px 0 6px;">
            <table class="grid" style="width: 100%;">
                <tr>
                    <td class="c" style="width: 60%; vertical-align: middle;">FIRE SAFETY INSPECTION FEE ASSESSMENT:</td>
                    <td class="c v" style="vertical-align: middle;">{{ $annex['fsic_fee'] !== '' ? '₱ '.$annex['fsic_fee'] : '' }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
<div class="small" style="margin-top: 10px; line-height: 1.9;">
    Important Notice: as per Section 12 of the Implementing Rules and Regulations of the Fire Code of 2008, certain establishments
    (e.g. building lessors, fire, earthquake, and hazard insurance companies, and vendors of fire fighting equipment, appliances and
    devices) may be required to pay additional charges and fees other than the Fire Safety Inspection Fees. These shall be collected
    during inspections or in another process to be communicated by representatives of the Bureau of Fire Protection (BFP).
</div>
