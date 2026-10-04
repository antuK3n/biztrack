{{--
    The BFP's Fire Safety Inspection Certificate, laid out from the issued
    sheet (BFP-QSF-FSED-005) the client sent on 4 October 2026.

    Drawn inside permit.blade.php's ruled .sheet, which also carries the
    styles (body.fsic). Every number on it is this system's: the FSIC NO. is the
    permit number, the date is the date of issue, and the tracking ID stands
    where the paper prints its control number. The QR takes the place of the
    BFP seal opposite the City's - we hold no copy of the Bureau's seal, and a
    redrawn one would be a counterfeit of it.

    dompdf has no flexbox; every side-by-side pair is a fixed-width table.
--}}
@php
    $box = fn (bool $on) => '<span class="fsic-check">'.($on ? '&#10004;' : '&nbsp;').'</span>';
    $where = collect([$address, $barangay])->filter()->implode(', ');
    $cityName = str_contains(strtoupper($city ?: ''), 'CITY') ? $city : trim(($city ?: 'Malabon').' City');
    $postal = collect([$where, $cityName])->filter()->implode(', ');
    $lh = $letterhead ?? [];
@endphp

{{-- Head: the City's seal, the Bureau's letterhead centred, the QR opposite. --}}
<table class="row">
    <tr>
        <td class="fsic-seal-cell">
            @if(file_exists(public_path('malabon-seal.png')))
                <img class="fsic-seal" src="{{ public_path('malabon-seal.png') }}" alt="">
            @endif
        </td>
        <td class="fsic-head">
            <div>Republic of the Philippines</div>
            <div class="fsic-dilg">Department of the Interior and Local Government</div>
            <div class="fsic-bureau">{{ strtoupper($lh['agency'] ?? 'Bureau of Fire Protection') }}</div>
            @foreach(['region', 'district', 'station', 'address', 'contact'] as $line)
                @if(! empty($lh[$line]))
                    <div>{{ $lh[$line] }}</div>
                @endif
            @endforeach
        </td>
        <td class="qr-cell">
            @if($qr)
                <img src="{{ $qr }}" alt="Verification QR code">
                <div class="caption">Scan to verify</div>
            @endif
        </td>
    </tr>
</table>

{{-- FSIC NO. left, date of issue right, each on its own rule. --}}
<table class="row fsic-numbers">
    <tr>
        <td>
            <span class="fsic-no-label">FSIC NO.</span>
            <span class="fsic-no">{{ $permit_number }}</span>
        </td>
        <td class="fsic-date-cell">
            <div class="fsic-date">{{ $valid_from ?: ' ' }}</div>
            <div class="fsic-caption">Date</div>
        </td>
    </tr>
</table>

<div class="fsic-title">FIRE SAFETY INSPECTION CERTIFICATE</div>
@if($status_label && $status_label !== 'Active')
    <div class="status">{{ strtoupper($status_label) }}</div>
@endif

<div class="fsic-purposes">
    <div>{!! $box($fsic_purpose === 'occupancy') !!} FOR CERTIFICATE OF OCCUPANCY</div>
    <div>{!! $box($fsic_purpose === 'business') !!} FOR BUSINESS PERMIT (NEW/RENEWAL)</div>
    <div>{!! $box($fsic_purpose === 'other') !!} OTHERS <span class="fsic-others">{{ $fsic_others ?: ' ' }}</span></div>
</div>

<div class="fsic-concern">TO WHOM IT MAY CONCERN:</div>

<div class="fsic-body">
    <p class="fsic-indent">
        By virtue of the provisions of RA 9514 otherwise known as the Fire Code of the Philippines of 2008,
        the application for <b>FIRE SAFETY INSPECTION CERTIFICATE</b> of
    </p>
    <div class="fsic-fill">{{ $business_name ?: 'Business removed from register' }}</div>
    <div class="fsic-caption">(Name of Establishment)</div>

    <table class="row fsic-owned">
        <tr>
            <td class="fsic-lead">owned and managed by</td>
            <td>
                <div class="fsic-fill">{{ $owner_name ?: ' ' }}</div>
                <div class="fsic-caption">(Name of Owner/Representative)</div>
            </td>
            <td class="fsic-trail">with postal address at</td>
        </tr>
    </table>
    <div class="fsic-fill">{{ $postal ?: ' ' }}</div>
    <div class="fsic-caption">(Address)</div>

    <p>
        is hereby <b>GRANTED</b> after said building structure or facility has been duly inspected with the finding
        that it has fully complied with the fire safety and protection requirements of the Fire Code of the
        Philippines of 2008 and its Revised Implementing Rules and Regulations.
    </p>

    <table class="row fsic-valid">
        <tr>
            <td class="fsic-lead fsic-indent-cell">This certification is valid for</td>
            <td><div class="fsic-fill fsic-nowrap">{{ $fsic_valid_for }}</div></td>
        </tr>
    </table>
    <table class="row fsic-valid">
        <tr>
            <td>
                <div class="fsic-fill fsic-small">{{ trim(($business_name ?: '').' '.($fsic_description ?: '')) ?: ' ' }}</div>
                <div class="fsic-caption">(Description)</div>
            </td>
            <td class="fsic-until-label">valid until</td>
            <td class="fsic-until"><div class="fsic-fill">{{ $valid_until ?: ' ' }}</div></td>
        </tr>
    </table>

    <p class="fsic-indent fsic-violation">
        Violation of Fire Code provisions shall cause this certificate <i>null and void</i> after appropriate
        proceeding and shall hold the owner liable to the penalties provided for by the said Fire Code.
    </p>
</div>

{{-- Fees bottom-left, the two signatures bottom-right, as the form sets them. --}}
<table class="row fsic-foot">
    <tr>
        <td class="fsic-fees">
            <div class="fsic-fees-title">Fire Code Fees:</div>
            <table class="fsic-fee-table">
                <tr><td>Amount Paid:</td><td class="fsic-fee-value">{{ $office_amount_paid ?: ' ' }}</td></tr>
                <tr><td>O.R. Number:</td><td class="fsic-fee-value">{{ $or_number ?: ' ' }}</td></tr>
                <tr><td>Date:</td><td class="fsic-fee-value">{{ $date_paid ?: ' ' }}</td></tr>
            </table>
            <div class="fsic-control">Business Account No.: <b>{{ $ban ?: '—' }}</b></div>
        </td>
        <td class="fsic-signs">
            @foreach($signatories as $s)
                <div class="fsic-action">
                    @if(($s['action'] ?? '') === 'Approved'){!! $box(true) !!} @endif{{ strtoupper($s['action'] ?? '') }}:
                </div>
                <div class="fsic-sig-name {{ $s['name'] ? '' : 'blank' }}">{{ $s['name'] ? strtoupper($s['name']) : '.' }}</div>
                <div class="fsic-sig-line"></div>
                <div class="fsic-sig-role">{{ strtoupper($s['role']) }}</div>
            @endforeach
        </td>
    </tr>
</table>

<div class="fsic-note">
    NOTE: &ldquo;This Certificate does not take the place of any license required by law and is not transferable.
    Any change in the use of occupancy of the premises shall require a new certificate.&rdquo;
</div>
<div class="fsic-posted">THIS CERTIFICATE SHALL BE POSTED CONSPICUOUSLY</div>
<div class="fsic-paalala">
    PAALALA: &ldquo;MAHIGPIT NA IPINAGBABAWAL NG PAMUNUAN NG BUREAU OF FIRE PROTECTION SA MGA KAWANI NITO
    ANG MAGBENTA O MAGREKOMENDA NG ANUMANG BRAND NG FIRE EXTINGUISHER&rdquo;
</div>
<table class="row fsic-last">
    <tr>
        <td class="fsic-copy-cell"><span class="fsic-copy">Applicant/Owner&rsquo;s Copy</span></td>
        <td class="fsic-motto">&ldquo;FIRE SAFETY IS OUR MAIN CONCERN&rdquo;</td>
    </tr>
</table>
