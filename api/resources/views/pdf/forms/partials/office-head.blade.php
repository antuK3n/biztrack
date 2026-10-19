{{--
    Letterhead + document-control block for the three office sheets with no
    paper photograph on file (Zoning, Sanitary, CENRO). It is the City's own
    controlled-document head — the same arrangement the BPLO, CED and CPDD
    papers that WERE photographed all share — with the office's name and its
    form code, so the three read as siblings of the forms around them rather
    than as screens printed out.
--}}
<table>
    <tr>
        <td style="width: 30%; text-align: right; vertical-align: middle; padding-right: 14px;">
            @if($f['seal'])<img src="{{ $f['seal'] }}" style="height: 56px;" alt="">@endif
        </td>
        <td style="vertical-align: middle;">
            <div class="bold" style="font-size: 9.6px;">REPUBLIC OF THE PHILIPPINES</div>
            <div class="bold" style="font-size: 9.6px;">CITY OF MALABON</div>
            <div class="bold" style="font-size: 10.6px;">{{ $office }}</div>
            <div class="tiny bold">{{ $officeAddress }}</div>
            @if(! empty($officeLines))<div class="tiny bold">{{ $officeLines }}</div>@endif
        </td>
    </tr>
</table>
<table style="margin-top: 6px;">
    <tr>
        <td class="b" style="width: 58%; vertical-align: middle; padding: 6px 8px;">
            <span class="bold" style="font-size: 11px;">{{ $title }}</span>
            @if(! empty($subtitle))<div class="small">{{ $subtitle }}</div>@endif
        </td>
        <td class="b" style="padding: 0;">
            <table>
                <tr><td class="bb br" style="width: 44%;">Document ID</td><td class="bb">{{ $docId }}</td></tr>
                <tr><td class="bb br">Version No.</td><td class="bb">{{ $docVersion }}</td></tr>
                <tr><td class="br">Application No.</td><td><span class="muted small">(for office use)</span></td></tr>
            </table>
        </td>
    </tr>
</table>
