{{--
    The permit certificate, as the paper one is laid out.

    This used to be a label/value table under a coloured header — correct data,
    but nothing a counter clerk would recognise as the document they stamp. The
    face is now the same document the applicant sees on screen
    (web/src/pages/applicant/PermitDetailPage.tsx): ruled outer frame, city
    header with the verification QR opposite it, the permit title, the field
    grid, then the signature block and the revocation note.

    Two constraints shape the markup:

    - dompdf has no flexbox and no grid. Every side-by-side pair below is a
      table with fixed column widths; that is the only layout primitive that
      renders the same in dompdf as it measures.
    - Nothing here is a person's name. The signature block is rendered from
      office_signatories rows (see the create_office_signatories_table
      migration). When the issuing office has no signatories on file the block
      still prints, as blank ruled lines under their role captions, because a
      blank line is a document waiting for a wet signature while an invented
      name is a forgery.
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1a1a1a; margin: 0; padding: 0; font-size: 12px; }

        /*
         * The ruled frame the paper certificate is printed inside, pinned to the
         * page rather than sized to its contents: content-sized, the border shut
         * two-thirds down and left a third of the sheet outside the document,
         * which reads as a page that failed to finish printing.
         *
         * dompdf resolves `position: absolute` against the page box when no
         * positioned ancestor exists, which is what makes this work at all —
         * `height: 100%` on a block does not.
         */
        .sheet {
            position: absolute;
            top: 26px; left: 26px; right: 26px; bottom: 26px;
            border: 2px solid #2b2b2b;
            padding: 22px 26px 26px;
        }

        table.row { width: 100%; border-collapse: collapse; }
        table.row td { vertical-align: top; padding: 0; }

        .seal { height: 46px; margin-bottom: 4px; }
        .republic { font-size: 9px; letter-spacing: 1px; color: #555; }
        .city { font-size: 15px; font-weight: bold; letter-spacing: 1px; margin-top: 2px; }
        .office { font-size: 9px; font-weight: bold; letter-spacing: 1px; color: #444; margin-top: 2px; }

        .qr-cell { width: 108px; text-align: center; }
        .qr-cell img { width: 92px; height: 92px; }
        .qr-cell .caption { font-size: 8px; color: #777; margin-top: 2px; }

        .title { text-align: center; font-size: 22px; font-weight: bold; letter-spacing: 5px; margin: 22px 0 0; }
        /* Status only shows when it is something other than "Active": a permit
           that is expired or revoked must say so on its own face, or a stale
           download passes for a current one. */
        .status { text-align: center; font-size: 11px; font-weight: bold; letter-spacing: 2px; color: #bd0000; margin-top: 4px; }

        table.fields { width: 100%; border-collapse: separate; border-spacing: 0 7px; margin-top: 18px; }
        table.fields td { vertical-align: middle; }
        td.label { width: 118px; font-size: 8.5px; font-weight: bold; letter-spacing: 0.6px; color: #555; text-transform: uppercase; padding-right: 8px; }
        td.value { border: 1px solid #d5d9e2; background: #f2f5ff; padding: 4px 8px; font-size: 11.5px; }
        /* Removed-from-register and other absent values read as grey, so an
           empty box is never mistaken for a value that failed to print. */
        td.value.absent { color: #8a8f99; font-style: italic; }

        .rule { height: 3px; background: #0025cc; opacity: 0.7; margin-top: 16px; }

        .remarks-label { font-size: 8.5px; font-weight: bold; letter-spacing: 0.6px; color: #555; text-transform: uppercase; margin-top: 12px; }
        .remarks-box { border: 1px solid #d5d9e2; height: 46px; margin-top: 3px; }

        table.signatures { width: 100%; border-collapse: collapse; margin-top: 34px; }
        table.signatures td { text-align: center; padding: 0 14px; vertical-align: bottom; }
        .sig-name { font-size: 11.5px; font-weight: bold; padding-bottom: 2px; }
        /* Holds the line's height when there is no name above it, so signature
           cells sit on the same baseline whether or not the office is staffed. */
        .sig-name.blank { color: transparent; }
        .sig-line { border-bottom: 1px solid #2b2b2b; }
        .sig-role { font-size: 8.5px; font-weight: bold; letter-spacing: 0.6px; color: #555; text-transform: uppercase; padding-top: 3px; }

        .note { text-align: center; font-size: 8px; line-height: 1.5; color: #777; margin-top: 26px; }
        .verify-code { font-family: DejaVu Sans Mono, monospace; letter-spacing: 1px; }
    </style>
</head>
<body>
<div class="sheet">
    {{-- Header: city block left, verification QR right. --}}
    <table class="row">
        <tr>
            <td>
                {{-- Item 95: the city's seal, not ours. dompdf reads it off
                     disk rather than over HTTP, so it renders the same whether
                     or not the app is reachable from wherever the PDF is
                     generated. --}}
                @if(file_exists(public_path('malabon-seal.png')))
                    <img class="seal" src="{{ public_path('malabon-seal.png') }}" alt="">
                @endif
                <div class="republic">REPUBLIC OF THE PHILIPPINES</div>
                <div class="city">CITY OF MALABON</div>
                <div class="office">{{ strtoupper($department_name ?? 'Business Permits and Licensing Office') }}</div>
            </td>
            <td class="qr-cell">
                @if($qr)
                    <img src="{{ $qr }}" alt="Verification QR code">
                    <div class="caption">Scan to verify</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="title">{{ strtoupper($permit_type_name) }}</div>
    @if($status_label && $status_label !== 'Active')
        <div class="status">{{ strtoupper($status_label) }}</div>
    @endif

    @php
        /*
         * One helper for the whole grid: prints the value, or an explanation of
         * why there isn't one. `business_name` is null when the business was
         * removed from the register while its permit stayed on it — the permit
         * still has to be readable, and "—" would hide what happened.
         */
        $cell = function (?string $value, string $absent = '—') {
            return $value !== null && $value !== ''
                ? ['text' => $value, 'class' => 'value']
                : ['text' => $absent, 'class' => 'value absent'];
        };
        $rows = [
            ['Name of Owner', $cell($owner_name)],
            ['Business Name', $cell($business_name, 'Business removed from register')],
        ];
        if ($trade_name) {
            $rows[] = ['Trade Name', $cell($trade_name)];
        }
        $rows[] = ['Business Address', $cell(collect([$address, $barangay, $city])->filter()->implode(', ') ?: null)];
        if ($line_of_business) {
            $rows[] = ['Line of Business', $cell($line_of_business)];
        }
        $rows[] = ['Permit No.', $cell($permit_number)];
        $rows[] = ['Date of Issue', $cell($valid_from)];
        $rows[] = ['Valid Until', $cell($valid_until)];
        $rows[] = ['Tracking ID', $cell($tracking_id)];
    @endphp

    <table class="fields">
        @foreach($rows as [$label, $box])
            <tr>
                <td class="label">{{ $label }}</td>
                <td class="{{ $box['class'] }}">{{ $box['text'] }}</td>
            </tr>
        @endforeach
    </table>

    <div class="rule"></div>

    <div class="remarks-label">Remarks</div>
    <div class="remarks-box"></div>

    @php
        /*
         * Role captions only — no names. Used when the issuing office has no
         * office_signatories rows yet, which is every office but CENRO on a
         * freshly seeded register: the seeder deliberately seeds only the names
         * that were read off an actual printed form.
         */
        $blocks = $signatories ?: [
            ['role' => 'City Mayor', 'name' => null],
            ['role' => 'Officer-in-Charge', 'name' => null],
        ];
    @endphp
    <table class="signatures">
        <tr>
            @foreach($blocks as $block)
                <td style="width: {{ round(100 / max(count($blocks), 1), 2) }}%">
                    <div class="sig-name {{ $block['name'] ? '' : 'blank' }}">{{ $block['name'] ?: '.' }}</div>
                    <div class="sig-line"></div>
                    <div class="sig-role">{{ $block['role'] }}</div>
                </td>
            @endforeach
        </tr>
    </table>

    <div class="note">
        Subject to revocation for non-compliance with existing laws, ordinances, rules and regulations.<br>
        Verify authenticity with code <span class="verify-code">{{ $permit_number }}</span> at {{ $verify_url }}
    </div>
</div>
</body>
</html>
