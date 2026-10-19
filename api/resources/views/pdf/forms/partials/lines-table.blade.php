{{--
    The Line of Business table both BPLO forms print: three numbered rows at
    least, more when the business has more lines. PSIC in its own boxes, as the
    paper's "PSIC Code for BPLO/CTO only" column is ruled. `$withSales` adds
    the renewal form's Gross Sales/Receipts column.
--}}
@php
    $rows = $lines;
    while (count($rows) < 3) { $rows[] = ['line' => '', 'products' => '', 'psic' => '', 'gross_sales' => '']; }
@endphp
<table class="grid">
    <tr>
        <th class="c" style="width: {{ $withSales ? '30%' : '40%' }};">Line of Business</th>
        <th class="c" style="width: {{ $withSales ? '25%' : '38%' }};">Products/Services</th>
        <th class="c tiny" style="width: 22%; font-weight: normal;">PSIC Code for BPLO/CTO only</th>
        @if($withSales)
            <th class="c" style="width: 23%;">Gross Sales/Receipts</th>
        @endif
    </tr>
    @foreach($rows as $i => $row)
        <tr>
            <td><span class="small">{{ $i + 1 }}.</span> <span class="v">{{ $row['line'] }}</span></td>
            <td><span class="v">{{ $row['products'] }}</span></td>
            <td style="padding: 0;">
                <table class="plain"><tr>
                    @php $digits = str_split(str_pad(preg_replace('/\D/', '', $row['psic']), 6, ' ', STR_PAD_RIGHT)); @endphp
                    @foreach(array_slice($digits, 0, 6) as $j => $d)
                        <td class="c v {{ $j ? 'bl' : '' }}" style="padding: 2px 0; width: 16.6%; height: 11px;">{{ trim($d) }}</td>
                    @endforeach
                </tr></table>
            </td>
            @if($withSales)
                <td class="r"><span class="v">{{ $row['gross_sales'] !== '' ? '₱ '.$row['gross_sales'] : '' }}</span></td>
            @endif
        </tr>
    @endforeach
</table>
