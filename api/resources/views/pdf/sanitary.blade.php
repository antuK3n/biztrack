{{--
    The CHO's Sanitary Permit to Operate, laid out from the issued sheet the
    client sent on 5 October 2026.

    Drawn inside permit.blade.php's ruled .sheet, which also carries the styles
    (body.sanitary). The SANITARY PERMIT NO. is this system's permit number,
    the date issued is the date of issue, the date of expiration is the permit's
    valid-until, and the type of establishment is the CHO sheet's Sanitary
    Classification. The QR sits where the paper has the city's second mark.
--}}
@php
    $lh = $letterhead ?? [];
    $cityName = str_contains(strtoupper($city ?: ''), 'CITY') ? $city : trim(($city ?: 'Malabon').' City');
    $fullAddress = collect([$address, $barangay, $cityName])->filter()->implode(', ');
    $type = $sanitary_classification ?: $line_of_business;
@endphp

<table class="row">
    <tr>
        <td class="san-seal-cell">
            @if(file_exists(public_path('malabon-seal.png')))
                <img class="san-seal" src="{{ public_path('malabon-seal.png') }}" alt="">
            @endif
        </td>
        <td class="san-head">
            <div class="san-head-line">{{ strtoupper($lh['republic'] ?? 'Republic of the Philippines') }}</div>
            <div class="san-head-line">{{ strtoupper($lh['city'] ?? 'City of Malabon') }}</div>
            <div class="san-office">{{ strtoupper($lh['office'] ?? 'City Health Office') }}</div>
        </td>
        <td class="qr-cell">
            @if($qr)
                <img src="{{ $qr }}" alt="Verification QR code">
                <div class="caption">Scan to verify</div>
            @endif
        </td>
    </tr>
</table>

<div class="san-title">SANITARY PERMIT TO OPERATE</div>
@if($status_label && $status_label !== 'Active')
    <div class="status">{{ strtoupper($status_label) }}</div>
@endif

<table class="row san-line san-first">
    <tr>
        <td class="san-label"><i>ISSUED TO</i></td>
        <td>
            <div class="san-fill">{{ strtoupper($owner_name ?: ' ') }}</div>
            <div class="san-caption">(Registered Name)</div>
        </td>
    </tr>
</table>

<div class="san-fill san-wide">{{ strtoupper($business_name ?: 'Business removed from register') }}</div>
<div class="san-caption">(Name of Establishment)</div>

<table class="row san-line">
    <tr>
        <td class="san-label">Type of Establishment</td>
        <td><div class="san-fill">{{ strtoupper($type ?: ' ') }}</div></td>
    </tr>
</table>

<table class="row san-line">
    <tr>
        <td class="san-label"><i>ADDRESS</i></td>
        <td><div class="san-fill">{{ strtoupper($fullAddress ?: ' ') }}</div></td>
    </tr>
</table>

<table class="row san-line">
    <tr>
        <td class="san-label">Sanitary Permit No.</td>
        <td class="san-half"><div class="san-fill">{{ $permit_number }}</div></td>
        <td class="san-label san-label-right">Date Issued</td>
        <td><div class="san-fill san-nowrap">{{ strtoupper($valid_from ?: ' ') }}</div></td>
    </tr>
</table>

<table class="row san-line">
    <tr>
        <td class="san-label">Date of Expiration</td>
        <td class="san-half"><div class="san-fill san-nowrap">{{ strtoupper($valid_until ?: ' ') }}</div></td>
        <td class="san-label san-label-right">Tracking ID</td>
        <td><div class="san-fill san-nowrap">{{ $tracking_id ?: ' ' }}</div></td>
    </tr>
</table>

<div class="san-clause">
    THIS PERMIT IS NOT TRANSFERABLE AND WILL BE REVOKED FOR VIOLATION OF THE SANITARY RULES, LAWS OR
    REGULATION OF P.D. 522/P.D. 856 (CODE ON SANITATION OF THE PHILIPPINES) AND PERTINENT LOCAL ORDINANCES
    OF THE CITY OF MALABON.
</div>

@foreach($signatories as $i => $s)
    <table class="row san-sign {{ $i === 0 ? 'san-sign-first' : '' }}">
        <tr>
            <td class="san-sign-pad {{ $i === 0 ? 'san-pad-recommend' : 'san-pad-approve' }}">&nbsp;</td>
            <td class="san-action"><i>{{ $s['action'] ?? '' }}</i></td>
            <td class="san-sign-cell">
                <div class="san-sig-name {{ $s['name'] ? '' : 'blank' }}">{{ $s['name'] ? strtoupper($s['name']) : '.' }}</div>
                <div class="san-sig-line"></div>
                <div class="san-sig-role">{{ $s['role'] }}</div>
            </td>
        </tr>
    </table>
@endforeach
