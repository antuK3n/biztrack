{{--
    The BPLO letterhead and document-control block of MCG-BPLO-FO-001/002,
    as the paper prints them: seals left, the four-line office block right,
    then the form title beside Document ID / Version No. / Preparation Date /
    Page No.

    The paper carries three marks — the city seal, the BPLO seal and the
    Bagong Pilipinas logo. Only the city seal is on file (public/malabon-seal.png,
    the one the issued permit already uses), so only it prints; the other two
    are not redrawn from memory.
--}}
<table>
    <tr>
        <td style="width: 44%; vertical-align: middle; padding-left: 40px;">
            @if($f['seal'])
                <img src="{{ $f['seal'] }}" style="height: 54px;" alt="">
            @endif
        </td>
        <td style="vertical-align: middle;">
            <div class="bold" style="font-size: 10px;">REPUBLIC OF THE PHILIPPINES</div>
            <div class="bold" style="font-size: 10px;">CITY OF MALABON</div>
            <div class="bold" style="font-size: 11px;">BUSINESS PERMIT AND LICENSING OFFICE</div>
            <div class="tiny bold">Malabon City Hall, 3/F, F. Sevilla Blvd., Brgy. Tañong, Malabon City</div>
            <div class="tiny bold">Trunkline No.: 8281-4999 | Email: bplo@malabon.gov.ph | Website: malabon.gov.ph</div>
        </td>
    </tr>
</table>
<table style="margin-top: 6px;">
    <tr>
        <td class="b" style="width: 52%; vertical-align: middle; padding: 8px;">
            <span class="bold" style="font-size: 11.5px;">{{ $title }}</span>
        </td>
        <td class="b" style="padding: 0;">
            <table>
                <tr><td class="bb br" style="width: 42%;">Document ID</td><td class="bb">{{ $doc['id'] }}</td></tr>
                <tr><td class="bb br">Version No.</td><td class="bb">{{ $doc['version'] }}</td></tr>
                <tr><td class="bb br">Preparation Date:</td><td class="bb">{{ $doc['prepared'] }}</td></tr>
                <tr><td class="br">Page No.</td><td>{{ $page }}</td></tr>
            </table>
        </td>
    </tr>
</table>
