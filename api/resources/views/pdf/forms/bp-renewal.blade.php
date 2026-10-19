<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf.forms.partials.style')
</head>
<body>
@include('pdf.forms.partials.generated')
{{--
    MCG-BPLO-FO-002 v2.0 — APPLICATION FOR RENEWAL OF BUSINESS PERMIT, page 1
    of 2, laid out after the paper the client photographed (19 October 2026).

    Item order, numbering and wording are the paper's, including section A's
    three amendment questions and B's eight items with the Line of Business
    table under them. The applicant's answers print in the darker face; every
    box the register cannot answer prints blank for the counter to write in.
    Signatures are left blank — this is the copy that gets signed in ink.
--}}
@include('pdf.forms.partials.bplo-head', ['title' => 'APPLICATION FOR RENEWAL OF BUSINESS PERMIT', 'doc' => $doc, 'page' => 'Page 1 of 2'])

<table style="margin-top: 4px;">
    <tr>
        <td style="width: 56%;"></td>
        <td class="bold it" style="width: 18%;">Date of Receipt:</td><td class="fill v">{{ $f['date_received'] ?: $f['submitted_on'] }}</td>
    </tr>
    <tr><td></td><td class="bold it">Received by:</td><td class="fill v">{{ $f['received_by'] }}</td></tr>
    <tr><td></td><td class="bold it">Business Account Number:</td><td class="fill v">{{ $f['ban'] }}</td></tr>
</table>

<table class="box" style="margin-top: 4px;">
    <tr>
        <td colspan="2" style="padding: 3px 6px;">
            <span class="bold">GENERAL INSTRUCTIONS:</span>
            <div style="padding-left: 40px;">
                1. Accomplish the application form by ticking the appropriate boxes, typing, and/or printing (upper case/capital letter).
                All required data fields/information should be completely and clearly filled-out by the applicant.<br>
                2. Please ensure that all required documents are properly attached and fill out all necessary information. Incomplete
                submission of application form and/or requirements will be returned to the applicant.
            </div>
        </td>
    </tr>
    <tr><td colspan="2" class="secl big">A. BUSINESS INFORMATION AND REGISTRATION</td></tr>
    <tr>
        <td colspan="2" style="padding: 0;">
            <table>
                <tr>
                    <td class="bold bb" style="width: 64%;">1. Do you have any changes or amendments in the previous business registration?</td>
                    <td class="bb bl" style="width: 18%;"><span class="ck">{{ $ck($f['has_amendments']) }}</span> Yes</td>
                    <td class="bb bl"><span class="ck">{{ $ck(! $f['has_amendments']) }}</span> No</td>
                </tr>
            </table>
            <div class="bold" style="padding: 2px 4px;">2. If yes, please check the appropriate box/es (The local BPLS or CTO shall update the business registration of the applicant/owner)</div>
            <table>
                <tr>
                    <td style="width: 20%; padding-left: 60px;"><span class="ck">{{ $ck($f['amendment_ownership']) }}</span> Ownership</td>
                    <td style="width: 28%;"><span class="ck">{{ $ck($f['amendment_location']) }}</span> Location or Address of Business</td>
                    <td style="width: 20%;"><span class="ck">{{ $ck($f['amendment_nature']) }}</span> Nature of Business</td>
                    <td><span class="ck">{{ $ck($f['amendment_other'] !== '') }}</span> Others <span class="line v" style="min-width: 110px;">{{ $f['amendment_other'] }}</span></td>
                </tr>
            </table>
            @php $structures = ['sole_proprietorship' => 'Sole Proprietorship', 'partnership' => 'Partnership', 'corporation' => 'Corporation', 'cooperative' => 'Cooperative']; @endphp
            <table class="bt">
                @foreach(['From' => $f['amend_from'], 'To' => $f['amend_to']] as $dir => $picked)
                    <tr>
                        <td class="bold {{ $dir === 'To' ? 'bt' : '' }}" style="width: 22%;">{{ $dir === 'From' ? '3. Amendment:' : '' }}</td>
                        <td class="bl {{ $dir === 'To' ? 'bt' : '' }}" style="width: 6%;">{{ $dir }}</td>
                        @foreach($structures as $key => $label)
                            <td class="bl {{ $dir === 'To' ? 'bt' : '' }}"><span class="ck">{{ $ck($picked === $key) }}</span> {{ $label }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        </td>
    </tr>

    <tr><td colspan="2" class="secl big">B. BUSINESS OPERATION</td></tr>
    <tr><td colspan="2" class="bb"><span class="bold">1. Business Name:</span> <span class="v">{{ $f['business_name'] }}</span></td></tr>
    <tr>
        <td colspan="2" style="padding: 0;">
            <table>
                <tr>
                    <td class="br bb" style="width: 22%;"><span class="bold">2. Business Area<br>(in sq. m.):</span> <span class="v">{{ $f['area'] }}</span></td>
                    <td class="br bb" style="width: 26%; padding: 0;">
                        <div class="bold" style="padding: 2px 4px;">3. Total No. of Employees:</div>
                        <table class="bt"><tr>
                            <td class="br">Male: <span class="v">{{ $f['employees_male'] }}</span></td>
                            <td class="br">Female: <span class="v">{{ $f['employees_female'] }}</span></td>
                            <td>Total: <span class="v">{{ $f['employees_total'] }}</span></td>
                        </tr></table>
                    </td>
                    <td class="br bb" style="width: 26%;"><span class="bold">4. No. of Employees Residing within LGU:</span> <span class="v">{{ $f['employees_lgu'] }}</span></td>
                    <td class="bb"><span class="bold">5. No. of Delivery Units:</span> <span class="v">{{ $f['delivery_units'] }}</span></td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td colspan="2" class="bb" style="padding: 3px 4px;">
            @include('pdf.forms.partials.location-address', ['num' => 6])
        </td>
    </tr>
    <tr>
        <td colspan="2" style="padding: 0;">
            <table>
                <tr>
                    <td class="bold bb br" style="width: 46%;">7. Do you have tax incentives from any Government Entity?</td>
                    <td class="bb br" style="width: 40%;"><span class="ck">{{ $ck($f['has_tax_incentives']) }}</span> Yes (Please attach a copy of your certificate)</td>
                    <td class="bb"><span class="ck">{{ $ck(! $f['has_tax_incentives']) }}</span> No</td>
                </tr>
                <tr>
                    <td class="bold br">8. Do you pay rent for occupying a place of business?</td>
                    <td class="br"><span class="ck">{{ $ck($f['pays_rent']) }}</span> Yes (Please attach a copy of your lease contract)</td>
                    <td><span class="ck">{{ $ck(! $f['pays_rent']) }}</span> No</td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td colspan="2" style="padding: 0;">
            @include('pdf.forms.partials.lines-table', ['lines' => $f['lines'], 'withSales' => true])
        </td>
    </tr>
    <tr>
        <td colspan="2" style="padding: 6px 8px;">
            <div class="bold">DATA PRIVACY POLICY</div>
            <div style="text-indent: 40px; text-align: justify; margin-top: 3px;">
                I have read and understood the Data Privacy Policy and hereby give my consent to the City Government of Malabon, and any person acting in its
                behalf, to collect, store, record, process and update my personal data as part of its database and share said data to the national government, its agencies
                and instrumentalities, and other local government units.
            </div>
            @if($f['privacy_consent'])
                <div class="small muted" style="margin-top: 2px;"><span class="ck">☒</span> Consent given online when this application was filed.</div>
            @endif
            <table style="margin-top: 34px;">
                <tr>
                    <td style="width: 50%; padding: 0 30px;"><div class="sig">SIGNATURE OF REPRESENTATIVE OVER PRINTED NAME</div></td>
                    <td style="padding: 0 30px;">
                        <div class="c v">{{ $f['owner_name'] }}</div>
                        <div class="sig">SIGNATURE OF APPLICANT/OWNER OVER PRINTED NAME</div>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 26px 60px 0;"><div class="sig">DESIGNATED POSITION</div></td>
                    <td style="padding: 26px 60px 0;"><div class="sig">DESIGNATED POSITION</div></td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td colspan="2" class="bt" style="padding: 4px 8px;">
            <div class="bold big" style="padding-left: 90px;">DOCUMENTARY REQUIREMENTS</div>
            @php
                $reqs = [
                    'BIR_SALES_TAX_RETURN' => 'Submission of BIR Sales Tax Returns of the prior year or Quarterly VAT Sales',
                    'LIABILITY_INSURANCE' => 'Comprehensive General Liability Insurance (if applicable)',
                    'REGULATORY_CERTIFICATION' => 'Certification and clearance applicable for regulatory purposes',
                    'SPA_AUTHORIZATION' => 'Special power of attorney (SPA)/Authorization to transact for representative together with photocopies of IDs)',
                ];
            @endphp
            <div style="padding-left: 150px;">
                @foreach($reqs as $code => $label)
                    <div class="bold it small"><span class="ck">{{ in_array($code, $f['documents'], true) ? '◉' : '○' }}</span> {{ $label }}</div>
                @endforeach
            </div>
        </td>
    </tr>
    <tr>
        <td colspan="2" class="bt" style="padding: 4px 8px;">
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
@include('pdf.forms.partials.bplo-head', ['title' => 'APPLICATION FOR RENEWAL OF BUSINESS PERMIT', 'doc' => $doc, 'page' => 'Page 2 of 2'])
@include('pdf.forms.partials.bplo-annex', ['annexTitle' => 'APPLICATION FOR RENEWAL OF BUSINESS PERMIT'])
</body>
</html>
