{{--
    The CPDO's Zoning Clearance (For Business Permit), laid out from the issued
    sheet the client sent on 5 October 2026.

    Drawn inside permit.blade.php's ruled .sheet, which also carries the styles
    (body.zoning). The ZONING PERMIT NO. is this system's permit number, the
    date issued is the date of issue, and the type of establishment is the line
    of business on the face. The QR sits opposite the City's seal, where the
    paper has the office's own CPDO seal: we hold no copy of it, and a redrawn
    one would be a counterfeit.
--}}
@php
    $lh = $letterhead ?? [];
    $cityName = str_contains(strtoupper($city ?: ''), 'CITY') ? $city : trim(($city ?: 'Malabon').' City');
    $fullAddress = collect([$address, $barangay, $cityName])->filter()->implode(', ');
    $administrator = $signatories[0] ?? ['name' => null, 'role' => "City Planning & Dev't Coordinator / Zoning Administrator"];
@endphp

{{-- The City's seal, faint, behind the sheet — the paper carries its office's
     seal the same way. Our own city seal, never another office's. --}}
@if(file_exists(public_path('malabon-seal.png')))
    <img class="zoning-watermark" src="{{ public_path('malabon-seal.png') }}" alt="">
@endif

<table class="row">
    <tr>
        <td class="zoning-seal-cell">
            @if(file_exists(public_path('malabon-seal.png')))
                <img class="zoning-seal" src="{{ public_path('malabon-seal.png') }}" alt="">
            @endif
        </td>
        <td class="zoning-head">
            <div>{{ $lh['republic'] ?? 'Republic of the Philippines' }}</div>
            <div class="zoning-city">{{ strtoupper($lh['city'] ?? 'City of Malabon') }}</div>
            <div class="zoning-office">{{ strtoupper($lh['office'] ?? 'City Planning & Development Office') }}</div>
        </td>
        <td class="qr-cell">
            @if($qr)
                <img src="{{ $qr }}" alt="Verification QR code">
                <div class="caption">Scan to verify</div>
            @endif
        </td>
    </tr>
</table>

<div class="zoning-title">ZONING CLEARANCE</div>
<div class="zoning-subtitle">(For Business Permit)</div>
@if($status_label && $status_label !== 'Active')
    <div class="status">{{ strtoupper($status_label) }}</div>
@endif

<div class="zoning-box">
    <table class="zoning-line">
        <tr>
            <td class="zoning-label">BUSINESS NAME:</td>
            <td class="zoning-value zoning-big">{{ strtoupper($business_name ?: 'Business removed from register') }}</td>
        </tr>
    </table>
    <table class="zoning-line">
        <tr>
            <td class="zoning-label">TYPE OF ESTABLISHMENT:</td>
            <td class="zoning-value">{{ strtoupper($line_of_business ?: ' ') }}</td>
        </tr>
    </table>
    <table class="zoning-line">
        <tr>
            <td class="zoning-label">ADDRESS:</td>
            <td class="zoning-value">{{ strtoupper($fullAddress ?: ' ') }}</td>
        </tr>
    </table>
    <table class="zoning-line">
        <tr>
            <td class="zoning-label">ZONING PERMIT NO.:</td>
            <td class="zoning-value zoning-half">{{ $permit_number }}</td>
            <td class="zoning-label zoning-label-right">DATE ISSUED:</td>
            <td class="zoning-value zoning-nowrap">{{ strtoupper($valid_from ?: ' ') }}</td>
        </tr>
    </table>
    <table class="zoning-line">
        <tr>
            <td class="zoning-label">VALID UNTIL:</td>
            <td class="zoning-value zoning-half">{{ strtoupper($valid_until ?: ' ') }}</td>
            <td class="zoning-label zoning-label-right">TRACKING ID:</td>
            <td class="zoning-value zoning-nowrap">{{ $tracking_id ?: ' ' }}</td>
        </tr>
    </table>
    <div class="zoning-decision">DECISION &ndash; <b>ZONING CLEARANCE GRANTED</b></div>
</div>

<div class="zoning-conditions-title">CONDITIONS:</div>
<table class="zoning-conditions">
    @foreach([
        'All conditions stipulated herein form part of this clearance and are subject to monitoring;',
        'No activity other than applied for shall be conducted within the project site;',
        'No major expansion, alteration and/or improvement shall be introduced without prior clearance from this Office;',
        'Any misrepresentation, false statements or allegations material to the issuance of this decision shall be sufficient cause of its revocation.',
    ] as $condition)
        <tr>
            <td class="zoning-tick">&#10004;</td>
            <td>{{ $condition }}</td>
        </tr>
    @endforeach
</table>

<div class="zoning-sign">
    <div class="zoning-sig-name {{ $administrator['name'] ? '' : 'blank' }}">{{ $administrator['name'] ? strtoupper($administrator['name']) : '.' }}</div>
    <div class="zoning-sig-line"></div>
    <div class="zoning-sig-role">{{ $administrator['role'] }}</div>
</div>

<div class="zoning-public">(This must be displayed within public view)</div>
