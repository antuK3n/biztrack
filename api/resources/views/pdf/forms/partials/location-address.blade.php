{{--
    "Business Location Address" in the paper's two ruled rows. Tables rather
    than inline underlines: dompdf lifts an inline-block's text off its own
    bottom border when the row wraps, which printed the house number floating
    a line above its rule.
--}}
<table>
    <tr>
        <td class="bold" style="width: 25%; white-space: nowrap;">{{ $num }}. Business Location Address:</td>
        <td style="width: 11%; white-space: nowrap;">House/Bldg. No.</td>
        <td class="fill v" style="width: 26%;">{{ $f['house'] }}</td>
        <td style="width: 6%;">Street</td>
        <td class="fill v">{{ $f['street'] }}</td>
    </tr>
</table>
<table style="margin-top: 2px;">
    <tr>
        <td style="width: 8%;">Barangay</td>
        <td class="fill v" style="width: 22%;">{{ $f['barangay'] }}</td>
        <td style="width: 8%;">City/Mun.</td>
        <td class="fill v" style="width: 16%;">{{ $f['city'] }}</td>
        <td style="width: 8%;">Province</td>
        <td class="fill v" style="width: 16%;">{{ $f['province'] }}</td>
        <td style="width: 10%;">Postal Code</td>
        <td class="fill v">{{ $f['postal'] }}</td>
    </tr>
</table>
