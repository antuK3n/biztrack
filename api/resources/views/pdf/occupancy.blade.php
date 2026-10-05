{{--
    Malabon's Certificate of Occupancy, NBC Form B-13, as the City's Office of
    the Building Official issues it: a two-page spread in a blue riveted frame
    [client, 5 October 2026]. Left, what was submitted and the requirements
    verified, recommending issuance; right, the certificate itself.

    Drawn inside permit.blade.php's .sheet (unbordered for this form; the
    panels carry their own frames), which also carries the styles
    (body.occupancy). Every value is this system's: No. is the permit number,
    the fee is the OBO's share of the bill, the building permit number and date
    are the OBO's own entries, and the Zoning and Fire Safety lines carry the
    certificates this system issued on the same filing. The QR stands beside
    the receipt block, where nothing on the paper is.
--}}
@php
    $v = fn ($x) => $x !== null && $x !== '' ? $x : ' ';
    $cityName = str_contains(strtoupper($city ?: ''), 'CITY') ? $city : trim(($city ?: 'Malabon').' City');
    $located = collect([$address, $barangay, $cityName])->filter()->implode(', ');
    $official = $signatories[0] ?? ['name' => null, 'role' => 'Building Official'];
    $requirements = [
        ['Locational/Zoning<br>of Land Use', $occ_zoning_no ?? null],
        ['Line and Grade<br>(Geodetic)', null],
        ['Architectural', null],
        ['Civil/Structural', null],
        ['Electrical', null],
        ['Mechanical', null],
        ['Sanitary', null],
        ['Plumbing', null],
        ['Electronics', null],
        ['Interior Design', null],
        ['Accessibility', null],
        ['Fire Safety', $occ_fire_no ?? null],
    ];
@endphp

{{-- ── Left page ─────────────────────────────────────────────────────── --}}
<div class="occ-panel occ-left">
    <div class="occ-inner">
        <span class="occ-rivet occ-tl"></span><span class="occ-rivet occ-tr"></span>
        <span class="occ-rivet occ-bl"></span><span class="occ-rivet occ-br"></span>

        <div class="occ-form-no">NBC FORM NO. B-13</div>
        <div class="occ-submitted">SUBMITTED THE FOLLOWING AS REQUIRED</div>
        <div class="occ-date-line">{{ $v($date_submitted ?? null) }}</div>
        <div class="occ-caption">DATE SUBMITTED</div>

        <table class="occ-checks">
            <tr>
                <td><span class="occ-box"></span> CERTIFICATE OF COMPLETION</td>
                <td><span class="occ-box"></span> AS-BUILT PLANS/SPECIFICATIONS</td>
            </tr>
            <tr>
                <td><span class="occ-box"></span> DAILY CONSTRUCTION WORKS LOGBOOK</td>
                <td><span class="occ-box"></span> (SPECIFY) <span class="occ-blank" style="width: 110px"></span></td>
            </tr>
        </table>

        <div class="occ-para occ-indent occ-left-align">
            A CERTIFICATE OF COMPLETION WAS SUBMITTED BY <span class="occ-blank occ-captioned" style="width: 150px"><span>(Name)</span></span>
            A DULY LICENSED <span class="occ-blank occ-captioned" style="width: 150px"><span>(Professional)</span></span>
            HIRED BY THE OWNER WHO UNDERTOOK THE FULL-TIME INSPECTION AND SUPERVISION OF CONSTRUCTION WORKS IN
            ACCORDANCE WITH SECTION 308, CHAPTER 3 OF THE NATIONAL BUILDING CODE, (PD 1096).
        </div>

        <div class="occ-verified">VERIFIED &amp; COMPLIED AS TO THE FOLLOWING REQUIREMENTS</div>
        <table class="occ-reqs">
            @foreach(array_chunk($requirements, 3) as $row)
                <tr>
                    @foreach($row as [$label, $value])
                        <td>
                            <div class="occ-req-line">{{ $v($value) }}</div>
                            <div class="occ-req-label">{!! strtoupper($label) !!}</div>
                        </td>
                    @endforeach
                </tr>
            @endforeach
            <tr>
                <td></td>
                <td>
                    <div class="occ-req-line">&nbsp;</div>
                    <div class="occ-req-label">OTHERS (SPECIFY)</div>
                </td>
                <td></td>
            </tr>
        </table>

        <div class="occ-para occ-indent occ-recommend">
            THE CONSTRUCTION/ERECTION OF THE BUILDING/STRUCTURE COVERED BY BUILDING PERMIT NO.
            <span class="occ-fill">{{ $v($occ_building_permit_no ?? null) }}</span>
            ISSUED ON <span class="occ-fill">{{ $v($occ_building_permit_date ?? null) }}</span>
            HAS BEEN COMPLETED, FINALLY INSPECTED AND THE REQUIREMENTS REVIEWED AND FOUND SUBSTANTIALLY
            SATISFACTORY COMPLIED, THEREFORE THE <b>&ldquo;CERTIFICATE OF OCCUPANCY&rdquo;</b> IS HEREBY
            RECOMMENDED FOR ISSUANCE.
        </div>
    </div>
</div>

{{-- ── Right page: the certificate ───────────────────────────────────── --}}
<div class="occ-panel occ-right">
    <div class="occ-inner">
        <span class="occ-rivet occ-tl"></span><span class="occ-rivet occ-tr"></span>
        <span class="occ-rivet occ-bl"></span><span class="occ-rivet occ-br"></span>

        <table class="row">
            <tr>
                <td class="occ-seal-cell">
                    @if(file_exists(public_path('malabon-seal.png')))
                        <img class="occ-seal" src="{{ public_path('malabon-seal.png') }}" alt="">
                    @endif
                </td>
                <td class="occ-head">
                    <div class="occ-republic">REPUBLIC OF THE PHILIPPINES</div>
                    <div class="occ-city">CITY OF MALABON</div>
                    <div class="occ-office">OFFICE OF THE BUILDING OFFICIAL</div>
                </td>
            </tr>
        </table>
        <div class="occ-title">CERTIFICATE OF OCCUPANCY</div>
        @if($status_label && $status_label !== 'Active')
            <div class="status">{{ strtoupper($status_label) }}</div>
        @endif

        <table class="row occ-receipt-row">
            <tr>
                <td class="occ-receipt-pad"></td>
                <td class="occ-receipt">
                    <table class="occ-receipt-table">
                        <tr><td>NO.</td><td class="occ-receipt-value">{{ $permit_number }}</td></tr>
                        <tr><td>FEE PAID</td><td class="occ-receipt-value">{{ $v($office_amount_paid ?? null) }}</td></tr>
                        <tr><td>OR. NO.</td><td class="occ-receipt-value">{{ $v($or_number ?? null) }}</td></tr>
                        <tr><td>DATE PAID</td><td class="occ-receipt-value">{{ $v($date_paid ?? null) }}</td></tr>
                    </table>
                </td>
                <td class="qr-cell occ-qr">
                    @if($qr)
                        <img src="{{ $qr }}" alt="Verification QR code">
                        <div class="caption">Scan to verify</div>
                    @endif
                </td>
            </tr>
        </table>

        <table class="row">
            <tr>
                <td></td>
                <td class="occ-issued">
                    <div class="occ-issued-line">{{ $v($valid_from) }}</div>
                    <div class="occ-caption">DATE ISSUED</div>
                </td>
            </tr>
        </table>

        <div class="occ-para occ-indent occ-grant">
            THIS <b>CERTIFICATE OF OCCUPANCY</b> IS ISSUED/GRANTED PURSUANT TO SECTION 309 OF THE NATIONAL BUILDING
            CODE (PD 1096).
        </div>

        {{-- One table per line, so the longest caption does not set every
             line's label width and squeeze the address into two lines. --}}
        <table class="occ-fields occ-first-field"><tr><td class="occ-f-label">NAME/OWNER</td><td class="occ-f-value">{{ strtoupper($v($owner_name)) }}</td></tr></table>
        <table class="occ-fields"><tr><td class="occ-f-label">NAME OF PROJECT</td><td class="occ-f-value">{{ strtoupper($v($occ_project ?? $business_name)) }}</td></tr></table>
        <table class="occ-fields">
            <tr>
                <td class="occ-f-label">USE OR CHARACTER OF OCCUPANCY</td>
                <td class="occ-f-value">{{ strtoupper($v($occ_use ?? null)) }}</td>
                <td class="occ-f-label occ-f-area">AREA</td>
                <td class="occ-f-value occ-f-area-value">{{ $v($occ_area ?? null) }}</td>
            </tr>
        </table>
        <table class="occ-fields"><tr><td class="occ-f-label">LOCATED AT/ALONG</td><td class="occ-f-value">{{ strtoupper($v($located)) }}</td></tr></table>

        <div class="occ-small occ-indent">
            THE OWNER SHALL PROPERLY MAINTAIN THE BUILDING/STRUCTURE TO ENHANCE ARCHITECTURAL WELL BEING, STRUCTURAL
            STABILITY, ELECTRICAL, MECHANICAL, SANITATION, PLUMBING, ELECTRONICS, INTERIOR DESIGN AND FIRE-PROTECTIVE
            PROPERTIES AND SHALL NOT BE OCCUPIED OR USED FOR PURPOSES OTHER THAN ITS INTENDED USE AS STATED ABOVE.
        </div>
        <div class="occ-small occ-indent">
            THE ARCHITECT OR ENGINEER WHO DREW UP THE PLANS AND SPECIFICATIONS FOR THE BUILDING/STRUCTURE IS AWARE
            THAT UNDER ARTICLE 1723 OF THE CIVIL CODE OF THE PHILIPPINES, HE IS RESPONSIBLE FOR DAMAGES IF WITHIN
            FIFTEEN (15) YEARS FROM THE COMPLETION OF THE BUILDING/STRUCTURE, THE SAME SHOULD COLLAPSE DUE TO DEFECT
            IN THE PLANS OR SPECIFICATIONS OR DEFECTS IN THE GROUND. HE IS THEREFORE ENJOINED TO CONDUCT ANNUAL
            INSPECTIONS OF THE STRUCTURE TO ENSURE THAT THE CONDITIONS UNDER WHICH THE STRUCTURE WAS DESIGNED ARE NOT
            BEING VIOLATED OR ABUSED.
        </div>
        <div class="occ-small occ-indent">
            THE BUILDING/STRUCTURE SHALL BE SUBJECT TO ANNUAL INSPECTION AND ISSUANCE OF A CERTIFICATE OF OCCUPANCY
            FOR A PERIOD OF ONE (1) YEAR FROM THE DATE OF ISSUANCE OF CERTIFICATE AND YEARLY THEREAFTER.
        </div>
        <div class="occ-small occ-indent">
            A CERTIFIED COPY HEREOF SHALL BE POSTED WITHIN THE PREMISES OF THE BUILDING AND SHALL NOT BE REMOVED
            WITHOUT AUTHORITY FROM THE BUILDING OFFICIAL.
        </div>

        <div class="occ-sign">
            <div class="occ-sig-name {{ $official['name'] ? '' : 'blank' }}">{{ $official['name'] ? strtoupper($official['name']) : '.' }}</div>
            <div class="occ-sig-line"></div>
            <div class="occ-sig-role">{{ strtoupper($official['role']) }}</div>
        </div>
    </div>
</div>
