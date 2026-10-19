<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.forms.partials.style')
    <style>
        body { font-size: 9px; }
        .q td { border: 1px solid #000; padding: 3px 5px; }
        .q .n { font-weight: bold; width: 4%; text-align: center; }
        .q table.plain td { border: 0; padding: 1px 3px; }
    </style>
</head>
<body>
@include('pdf.forms.partials.generated')
{{--
    MCG-CPDD-FO-003 v1.2 — APPLICATION FOR LOCATIONAL CLEARANCE (BUSINESS
    ACTIVITIES). No photograph of this paper was sent, so the items follow the
    zoning sheet in the system, which was transcribed from it: I to IX in its
    order, X the Applicant Declaration worded exactly as
    pdf/zoning-declaration.blade.php transcribes it (typo "share said date"
    included, for the reason given there), then the CHECKLIST OF REQUIREMENTS
    ticked where the applicant attached each item.

    The declaration's jurat and the notary's Doc./Page/Book/Series lines are
    blank: they are the notary's.
--}}
@include('pdf.forms.partials.office-head', [
    'office' => 'CITY PLANNING AND DEVELOPMENT DEPARTMENT',
    'officeAddress' => 'Malabon City Hall, F. Sevilla Blvd., Brgy. Tañong, Malabon City',
    'officeLines' => 'Trunkline No.: 8281-4999 | Website: malabon.gov.ph',
    'title' => 'APPLICATION FOR LOCATIONAL CLEARANCE (BUSINESS ACTIVITIES)',
    'subtitle' => null,
    'docId' => 'MCG-CPDD-FO-003',
    'docVersion' => '1.2',
])
@php
    $g = fn (string $k) => trim((string) ($d[$k] ?? ''));
    $rented = $g('site_is_rented') === 'yes';
    $nature = $g('application_type');
    $industrial = $g('zoning_industrial_project_type');
    $proprietor = in_array($f['registration_type'], ['corporation', 'partnership', 'cooperative'], true) && $f['president'] !== '' ? $f['president'] : $f['owner_name'];
    $t = fn (string $k) => $ck($ticks[$k] ?? false);
@endphp
<table style="margin-top: 4px;">
    <tr>
        <td style="width: 62%;"></td>
        <td class="bold" style="width: 12%;">Date:</td>
        <td class="fill v">{{ $g('application_date') !== '' ? \App\Support\PaperForms::date($g('application_date')) : $saved_on }}</td>
    </tr>
</table>

<table class="q" style="margin-top: 4px;">
    <tr>
        <td class="n">I.</td>
        <td style="width: 52%;"><span class="bold">NAME OF PROPRIETOR/PRESIDENT/GENERAL MANAGER</span><br><span class="v">{{ $proprietor }}</span></td>
        <td style="width: 20%;"><span class="bold">CONTACT NO.</span><br><span class="v">{{ $f['mobile'] }}</span></td>
        <td><span class="bold">EMAIL ADDRESS</span><br><span class="v">{{ $f['email'] }}</span></td>
    </tr>
    <tr>
        <td class="n">II.</td>
        <td colspan="3"><span class="bold">HOME ADDRESS</span> <span class="small">(No. of Street, Barangay, Municipality/City, Province)</span><br><span class="v">{{ $g('zoning_home_address') ?: $f['home_address'] }}</span></td>
    </tr>
    <tr>
        <td class="n">III.</td>
        <td colspan="2"><span class="bold">NAME OF FIRM</span><br><span class="v">{{ $f['business_name'] }}{{ $f['trade_name'] !== '' ? ' ('.$f['trade_name'].')' : '' }}</span></td>
        <td><span class="bold">CONTACT NO.</span><br><span class="v">{{ $f['contact'] }}</span></td>
    </tr>
    <tr>
        <td class="n">IV.</td>
        <td colspan="3"><span class="bold">ADDRESS OF FIRM</span><br><span class="v">{{ $f['address'] }}</span></td>
    </tr>
    <tr>
        <td class="n">V.</td>
        <td colspan="3"><span class="bold">ACTIVITY</span><br><span class="v">{{ implode('; ', array_filter(array_column($f['lines'], 'line'))) }}</span></td>
    </tr>
    <tr>
        <td class="n">VI.</td>
        <td colspan="3"><span class="bold">LOCATION/ADDRESS OF PROJECT/ACTIVITY</span><br><span class="v">{{ $f['address'] }}</span></td>
    </tr>
    <tr>
        <td class="n">VII.</td>
        <td colspan="3">
            <span class="bold">NATURE OF APPLICATION</span>
            <table class="plain"><tr>
                <td style="width: 40%;"><span class="ck">{{ $ck(str_starts_with($nature, 'New')) }}</span> New Locational Clearance</td>
                <td><span class="ck">{{ $ck(str_starts_with($nature, 'Renewal')) }}</span> Renewal of Locational Clearance</td>
            </tr></table>
        </td>
    </tr>
    <tr>
        <td class="n">VIII.</td>
        <td colspan="3">
            <span class="bold">PROJECT DESCRIPTION</span><br><span class="v">{{ $g('zoning_project_description') }}</span>
            <table class="plain" style="margin-top: 3px;">
                <tr>
                    <td style="width: 50%;">Floor Area to be / being Utilized (sq. m.): <span class="v">{{ $g('total_floor_area_sqm') ?: $f['area'] }}</span></td>
                    <td>No. of Storey of Building: <span class="v">{{ $g('building_storeys') }}</span></td>
                </tr>
                <tr>
                    <td>Right over land: <span class="ck">{{ $ck(! $rented) }}</span> Owned &nbsp; <span class="ck">{{ $ck($rented) }}</span> Leased/Rented</td>
                    <td></td>
                </tr>
                <tr>
                    <td>Name of Lessor (if lessee): <span class="v">{{ $g('lessor_name') ?: ($rented ? $f['lessor_name'] : '') }}</span></td>
                    <td>Address of Lessor (if lessee): <span class="v">{{ $g('lessor_address') ?: ($rented ? $f['lessor_address'] : '') }}</span></td>
                </tr>
                <tr>
                    <td colspan="2">
                        Type of Industrial Project:
                        @foreach(['Pollutive', 'Non-Pollutive', 'Hazardous', 'Non-Hazardous', 'Other'] as $kind)
                            &nbsp;<span class="ck">{{ $ck($industrial === $kind) }}</span> {{ $kind }}
                        @endforeach
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td class="n">IX.</td>
        <td colspan="3"><span class="bold">AUTHORIZED REPRESENTATIVE</span><br><span class="v">{{ $g('authorized_representative') }}</span></td>
    </tr>
    <tr>
        <td class="n">X.</td>
        <td colspan="3">
            <span class="bold">APPLICANT DECLARATION</span> <span class="small">(MUST BE NOTARIZED PRIOR TO SUBMISSION OF APPLICATION)</span>
            <div style="text-align: justify; margin-top: 3px;">
                That, I am an applicant for a <b>Locational Clearance</b>; That I have caused the above application and the herein attached prepared
                documents, and have read and with full knowledge of the contents thereof and that all information therein are true and correct to the
                best of my knowledge.
            </div>
            <div style="text-align: justify; margin-top: 3px;">
                That I have read and understood the <b>Data Privacy</b> Policy and hereby give my consent to the <b>City Government of Malabon</b>,
                and any person acting in its behalf to collect, store, record, process, and update my personal data as part of its database and share
                said date to the national government, its agencies and instrumentalities and other local government units.
            </div>
            <table class="plain" style="margin-top: 22px;">
                <tr>
                    <td style="width: 50%; padding: 0 24px;"><div class="sig" style="font-weight: normal;">Applicant/Representative<br>(Signature over Printed Name)</div></td>
                    <td style="padding: 0 24px;"><div class="sig" style="font-weight: normal;">Position/Title</div></td>
                </tr>
            </table>
            <div style="margin-top: 6px; text-align: justify;">
                Subscribed and sworn before me this ____ day of __________, 20____ at ______________, affiant exhibiting to me his/her
                ______________ with ID No. ______________, issued on __________.
            </div>
            <table class="plain" style="margin-top: 2px;"><tr>
                <td>Doc. No.: ______</td><td>Page No.: ______</td><td>Book No.: ______</td><td>Series of: ______</td>
            </tr></table>
        </td>
    </tr>
</table>

<table style="margin-top: 6px;">
    <tr>
        <td style="width: 62%; padding: 0; padding-right: 6px;">
            <table class="q">
                <tr><td colspan="2" class="bold c" style="background: #000; color: #fff;">CHECKLIST OF REQUIREMENTS</td></tr>
                @foreach([
                    'FORM' => 'Completely filled-up application form',
                    'TCT' => 'Transfer Certificate of Title (TCT) (if owned)',
                    'TAX_DECLARATION' => 'Tax Declaration (if owned)',
                    'RPT_CLEARANCE' => 'Real Property Tax Clearance (if owned)',
                    'LEASE' => 'Contract of Lease (if rented)',
                    'LOT_OWNER_CONSENT' => 'Consent from the Lot Owner (if rented)',
                    'DTI_SEC' => 'DTI / SEC Articles of Incorporation',
                    'SKETCH' => 'Sketch of the Location',
                    'DECLARATION' => 'Applicant Declaration, notarized',
                    'AUTHORIZATION' => 'Authorization Letter (if through a representative)',
                ] as $key => $label)
                    <tr><td style="width: 7%;" class="c ck">{{ $t($key) }}</td><td>{{ $label }}</td></tr>
                @endforeach
            </table>
        </td>
        <td style="padding: 0;">
            <table class="q">
                <tr><td colspan="2" class="bold c" style="background: #000; color: #fff;">FOR OFFICE USE ONLY</td></tr>
                <tr><td style="width: 50%;">Tracking ID</td><td class="v">{{ $f['tracking_id'] }}</td></tr>
                <tr><td>Date Received</td><td class="v">{{ $saved_on }}</td></tr>
                <tr><td>Received by</td><td></td></tr>
                <tr><td>Fees Assessed</td><td class="v">{{ $fee !== '' ? '₱ '.$fee : '' }}</td></tr>
                <tr><td>O.R. No. / Date</td><td></td></tr>
                <tr><td>Zoning Classification</td><td></td></tr>
                <tr><td>Evaluated by</td><td style="height: 22px;"></td></tr>
                <tr><td>Approved by</td><td style="height: 22px;"></td></tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
