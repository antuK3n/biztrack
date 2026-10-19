<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.forms.partials.style')
</head>
<body>
@include('pdf.forms.partials.generated')
{{--
    MCG-BPLO-FO-001 v2.0 — APPLICATION FOR NEW BUSINESS PERMIT, two pages.

    Page 1 follows the shell FO-001 shares with FO-002 (the renewal paper, which
    the client photographed whole): the BPLO letterhead and control block,
    General Instructions, A, B, the Data Privacy Policy with its two signature
    pairs, Documentary Requirements and Mode of Payment. Section B is the
    paper's own (photo img-50: items 1-9 with the Line of Business table
    between 6 and 7). Section A is numbered 1-17 as the application form in the
    system numbers it — no photograph of FO-001's page 1 section A was sent,
    so the system's sheet, which was built against that paper, is the source.

    Page 2 is ANNEX 1 exactly as photographed (img-331).
--}}
@include('pdf.forms.partials.bplo-head', ['title' => 'APPLICATION FOR NEW BUSINESS PERMIT', 'doc' => $doc, 'page' => 'Page 1 of 2'])

<table style="margin-top: 4px;">
    <tr>
        <td style="width: 56%;"></td>
        <td class="bold it" style="width: 18%;">Date of Receipt:</td><td class="fill v">{{ $f['date_received'] ?: $f['submitted_on'] }}</td>
    </tr>
    <tr><td></td><td class="bold it">Received by:</td><td class="fill v">{{ $f['received_by'] }}</td></tr>
    <tr><td></td><td class="bold it">Business Account Number:</td><td class="fill v">{{ $f['ban'] }}</td></tr>
</table>

@php
    $structures = ['sole_proprietorship' => 'Sole Proprietorship', 'partnership' => 'Partnership', 'corporation' => 'Corporation', 'cooperative' => 'Cooperative'];
    $econ = [
        'single_establishment' => 'Single Establishment', 'branch' => 'Branch', 'establishment_and_main_office' => 'Establishment and Main Office',
        'main_office_only' => 'Main Office only', 'ancillary_unit' => 'Ancillary Unit', 'others' => 'Others',
    ];
    $agency = match ($f['registration_type']) { 'sole_proprietorship' => 'DTI', 'cooperative' => 'CDA', 'partnership', 'corporation' => 'SEC', default => 'DTI/SEC/CDA' };
@endphp

<table class="box" style="margin-top: 4px;">
    <tr>
        <td style="padding: 3px 6px;">
            <span class="bold">GENERAL INSTRUCTIONS:</span>
            <div style="padding-left: 40px;">
                1. Accomplish the application form by ticking the appropriate boxes, typing, and/or printing (upper case/capital letter).
                All required data fields/information should be completely and clearly filled-out by the applicant.<br>
                2. Please ensure that all required documents are properly attached and fill out all necessary information. Incomplete
                submission of application form and/or requirements will be returned to the applicant.
            </div>
        </td>
    </tr>
    <tr><td class="secl big">A. BUSINESS INFORMATION AND REGISTRATION</td></tr>
    <tr>
        <td style="padding: 0;">
            <table>
                <tr>
                    <td class="bold bb br" style="width: 22%;">1. Form of Organization:</td>
                    @foreach($structures as $key => $label)
                        <td class="bb {{ $loop->last ? '' : 'br' }}"><span class="ck">{{ $ck($f['registration_type'] === $key) }}</span> {{ $label }}</td>
                    @endforeach
                </tr>
            </table>
            <table>
                <tr>
                    <td class="bb br" style="width: 50%;"><span class="bold">2. {{ $agency }} Registration No.:</span> <span class="v">{{ $f['registration_number'] }}</span></td>
                    <td class="bb"><span class="bold">3. Tax Identification Number (TIN):</span> <span class="v">{{ $f['tin'] }}</span></td>
                </tr>
                <tr>
                    <td class="bb br"><span class="bold">4. Business Name:</span> <span class="v">{{ $f['business_name'] }}</span></td>
                    <td class="bb"><span class="bold">5. Trade Name/Franchise:</span> <span class="v">{{ $f['trade_name'] }}</span></td>
                </tr>
            </table>
            <table>
                <tr>
                    <td class="bb br" style="width: 25%;"><span class="bold">6. Telephone No.:</span><br><span class="v">{{ $f['telephone'] }}</span></td>
                    <td class="bb br" style="width: 25%;"><span class="bold">7. Mobile No.:</span><br><span class="v">{{ $f['mobile'] }}</span></td>
                    <td class="bb br" style="width: 28%;"><span class="bold">8. Email Address:</span><br><span class="v">{{ $f['email'] }}</span></td>
                    <td class="bb"><span class="bold">9. Website (if any):</span><br><span class="v">{{ $f['website'] }}</span></td>
                </tr>
            </table>
            <div class="bold" style="padding: 2px 4px;">Name of Owner/Registrant:</div>
            <table>
                <tr>
                    <td class="bt bb br" style="width: 28%;"><span class="small">10. Surname</span><br><span class="v">{{ $f['owner_surname'] }}</span></td>
                    <td class="bt bb br" style="width: 28%;"><span class="small">11. Given Name</span><br><span class="v">{{ $f['owner_given'] }}</span></td>
                    <td class="bt bb br" style="width: 22%;"><span class="small">12. Middle Name</span><br><span class="v">{{ $f['owner_middle'] }}</span></td>
                    <td class="bt bb br" style="width: 8%;"><span class="small">13. Suffix</span><br><span class="v">{{ $f['owner_suffix'] }}</span></td>
                    <td class="bt bb"><span class="small">14. Sex</span><br>
                        <span class="ck">{{ $ck($f['owner_gender'] === 'Male') }}</span> M
                        <span class="ck">{{ $ck($f['owner_gender'] === 'Female') }}</span> F
                    </td>
                </tr>
            </table>
            <table>
                <tr>
                    <td class="br" style="width: 42%;"><span class="bold">15. Name of President/Officer in Charge:</span><br><span class="v">{{ $f['president'] }}</span></td>
                    <td class="br" style="width: 28%;"><span class="bold">16. Citizenship (of President/OIC):</span><br><span class="v">{{ $f['citizenship'] }}</span></td>
                    <td><span class="bold">17. Capital Participation (% Filipino):</span><br><span class="v">{{ $f['capital_participation'] !== '' ? $f['capital_participation'].'%' : '' }}</span></td>
                </tr>
            </table>
        </td>
    </tr>

    <tr><td class="secl big">B. BUSINESS OPERATION</td></tr>
    <tr>
        <td style="padding: 0;">
            <table>
                <tr>
                    <td class="br bb" style="width: 22%;"><span class="bold">1. Business Area<br>(in sq. m.):</span> <span class="v">{{ $f['area'] }}</span></td>
                    <td class="br bb" style="width: 28%; padding: 0;">
                        <div class="bold" style="padding: 2px 4px;">2. Total No. of Employees:</div>
                        <table class="bt"><tr>
                            <td class="br">Male: <span class="v">{{ $f['employees_male'] }}</span></td>
                            <td class="br">Female: <span class="v">{{ $f['employees_female'] }}</span></td>
                            <td>Total: <span class="v">{{ $f['employees_total'] }}</span></td>
                        </tr></table>
                    </td>
                    <td class="br bb" style="width: 26%;"><span class="bold">3. No. of Employees Residing within LGU:</span> <span class="v">{{ $f['employees_lgu'] }}</span></td>
                    <td class="bb"><span class="bold">4. No. of Delivery Units:</span> <span class="v">{{ $f['delivery_units'] }}</span></td>
                </tr>
            </table>
        </td>
    </tr>
    <tr><td class="bb" style="padding: 3px 4px;">@include('pdf.forms.partials.location-address', ['num' => 5])</td></tr>
    <tr>
        <td class="bb" style="padding: 0;">
            <table>
                <tr>
                    <td class="bold" rowspan="2" style="width: 22%;">6. Economic Organization:</td>
                    @foreach(['single_establishment', 'branch', 'establishment_and_main_office'] as $key)
                        <td><span class="ck">{{ $ck($f['economic_organization'] === $key) }}</span> {{ $econ[$key] }}</td>
                    @endforeach
                </tr>
                <tr>
                    @foreach(['main_office_only', 'ancillary_unit'] as $key)
                        <td><span class="ck">{{ $ck($f['economic_organization'] === $key) }}</span> {{ $econ[$key] }}</td>
                    @endforeach
                    <td><span class="ck">{{ $ck($f['economic_organization'] === 'others') }}</span> Others <span class="v">{{ $f['economic_organization_others'] }}</span></td>
                </tr>
            </table>
        </td>
    </tr>
    <tr><td style="padding: 0;">@include('pdf.forms.partials.lines-table', ['lines' => $f['lines'], 'withSales' => false])</td></tr>
    <tr><td class="bb"><span class="bold">7. Capital Investment (Php):</span> <span class="v">{{ $f['capital_investment'] }}</span></td></tr>
    <tr>
        <td style="padding: 0;">
            <table>
                <tr>
                    <td class="bold bb br" style="width: 46%;">8. Do you have tax incentives from any Government Entity?</td>
                    <td class="bb br" style="width: 40%;"><span class="ck">{{ $ck($f['has_tax_incentives']) }}</span> Yes (Please attach a copy of your certificate)</td>
                    <td class="bb"><span class="ck">{{ $ck(! $f['has_tax_incentives']) }}</span> No</td>
                </tr>
                <tr>
                    <td class="bold bb br">9. Do you pay rent for occupying a place of business?</td>
                    <td class="bb br"><span class="ck">{{ $ck($f['pays_rent']) }}</span> Yes (Please attach a copy of your lease contract)</td>
                    <td class="bb"><span class="ck">{{ $ck(! $f['pays_rent']) }}</span> No</td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td style="padding: 6px 8px;">
            <div class="bold">DATA PRIVACY POLICY</div>
            <div style="text-indent: 40px; text-align: justify; margin-top: 3px;">
                I have read and understood the Data Privacy Policy and hereby give my consent to the City Government of Malabon, and any person acting in its
                behalf, to collect, store, record, process and update my personal data as part of its database and share said data to the national government, its agencies
                and instrumentalities, and other local government units.
            </div>
            @if($f['privacy_consent'])
                <div class="small muted" style="margin-top: 2px;"><span class="ck">☒</span> Consent given online when this application was filed.</div>
            @endif
            <table style="margin-top: 16px;">
                <tr>
                    <td style="width: 50%; padding: 0 30px;"><div class="sig">SIGNATURE OF REPRESENTATIVE OVER PRINTED NAME</div></td>
                    <td style="padding: 0 30px;">
                        <div class="c v">{{ $f['owner_name'] }}</div>
                        <div class="sig">SIGNATURE OF APPLICANT/OWNER OVER PRINTED NAME</div>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 14px 60px 0;"><div class="sig">DESIGNATED POSITION</div></td>
                    <td style="padding: 14px 60px 0;"><div class="sig">DESIGNATED POSITION</div></td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td class="bt" style="padding: 4px 8px;">
            <div class="bold big" style="padding-left: 90px;">DOCUMENTARY REQUIREMENTS</div>
            @php
                $reqs = [
                    'DTI_SEC_CDA' => 'Proof of Business Registration (DTI / SEC / CDA)',
                    'LOCATION_SKETCH' => 'Sketch and photos of location of business',
                    'LEASE_CONTRACT' => 'Contract of Lease (if renting)',
                    'LAND_TITLE' => 'Tax Declaration/Transfer Certificate of Title (TCT) (if owned)',
                    'TAX_INCENTIVE_CERT' => 'Certificate of tax incentive (if any)',
                    'SPA_AUTHORIZATION' => 'Special power of attorney (SPA)/Authorization to transact for representative together with photocopies of IDs',
                ];
            @endphp
            <table style="margin-left: 40px; width: 92%;">
                @foreach(array_chunk($reqs, 2, true) as $pair)
                    <tr>
                        @foreach($pair as $code => $label)
                            <td class="bold it small" style="width: 50%; padding: 1px 4px;"><span class="ck">{{ in_array($code, $f['documents'], true) ? '◉' : '○' }}</span> {{ $label }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        </td>
    </tr>
    <tr>
        <td class="bt" style="padding: 4px 8px;">
            <table><tr>
                <td class="bold" style="width: 22%;">Mode of Payment:</td>
                <td><span class="ck">{{ $ck($f['payment_mode'] === 'annual') }}</span> Annually</td>
                <td><span class="ck">{{ $ck($f['payment_mode'] === 'semi_annual') }}</span> Semi-Annually</td>
                <td><span class="ck">{{ $ck($f['payment_mode'] === 'quarterly') }}</span> Quarterly</td>
            </tr></table>
        </td>
    </tr>
</table>

<div class="page-break"></div>
@include('pdf.forms.partials.bplo-head', ['title' => 'APPLICATION FOR NEW BUSINESS PERMIT', 'doc' => $doc, 'page' => 'Page 2 of 2'])
@include('pdf.forms.partials.bplo-annex', ['annexTitle' => 'APPLICATION FOR NEW BUSINESS PERMIT'])
</body>
</html>
