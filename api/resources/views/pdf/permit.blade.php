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
    - Nothing here is a person's name. The signature block arrives built, from
      PermitController::certificateData: the Mayor and the officer who issued
      this permit, frozen onto the permit at signature, followed by any
      office_signatories rows the issuing office configured (see the
      create_office_signatories_table migration). A role whose name is null
      still prints, as a blank ruled line under its caption, because a blank
      line is a document waiting for a wet signature while an invented name is
      a forgery.
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
        /*
         * Matched to the on-screen certificate, which sets both lines in the
         * same bold ink. This was 9px grey over a 15px black CITY OF MALABON,
         * so the download and the website disagreed about which line of the
         * city block was the heading.
         */
        .republic { font-size: 15px; font-weight: bold; letter-spacing: 1px; color: #1a1a1a; }
        .city { font-size: 15px; font-weight: bold; letter-spacing: 1px; margin-top: 2px; }
        .office { font-size: 9px; font-weight: bold; letter-spacing: 1px; color: #444; margin-top: 2px; }

        .qr-cell { width: 108px; text-align: center; }
        .qr-cell img { width: 92px; height: 92px; }
        .qr-cell .caption { font-size: 8px; color: #777; margin-top: 2px; }

        /* Serif, as the screen sets it (display-serif). A bold sans title was
         * the most visible difference between the two renderings of one
         * document: the download looked like a form, the screen like a
         * certificate. */
        .title { text-align: center; font-family: "DejaVu Serif", serif; font-size: 22px; letter-spacing: 5px; margin: 22px 0 0; color: #1a1a1a; }
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
        /* The Mayor's Permit rules its boxes in ink on white, as the City's
         * pad does and as the screen draws it. The tinted fill stays on the
         * clearances, which are tinted on screen too. */
        td.value.ruled { border: 1px solid #555; background: #fff; font-weight: bold; }

        .rule { height: 3px; background: #0025cc; opacity: 0.7; margin-top: 16px; }

        /*
         * The Mayor's Permit's own furniture. Boxes are ruled on all four
         * sides because the City's form is: a reader holding the paper beside
         * the print should see the same shapes in the same places.
         */
        .number-cell { width: 150px; vertical-align: top; padding-right: 10px; }
        .box-label { font-size: 6.5px; font-weight: bold; letter-spacing: 0.5px; text-transform: uppercase; color: #1a1a1a; margin-top: 5px; }
        .box { border: 1px solid #555; padding: 3px 5px; font-size: 9px; font-weight: bold; margin-top: 2px; min-height: 11px; }
        table.fields.triple { width: 100%; margin-top: 8px; }
        table.fields.triple td { width: 33.33%; padding-right: 10px; vertical-align: top; }
        .fee-line { font-size: 9px; color: #1a1a1a; margin-top: 7px; }
        .fee-label { font-size: 8px; font-weight: bold; letter-spacing: 0.5px; text-transform: uppercase; }
        .enforcement { text-align: center; font-size: 8px; font-weight: bold; margin-top: 22px; color: #1a1a1a; }
        .enforcement .stop { color: #c11212; }
        .expires { text-align: center; font-size: 8px; font-weight: bold; letter-spacing: 0.4px; margin-top: 4px; color: #1a1a1a; }
        .remarks-label { font-size: 8.5px; font-weight: bold; letter-spacing: 0.6px; color: #555; text-transform: uppercase; margin-top: 12px; }
        .remarks-box { border: 1px solid #d5d9e2; height: 46px; margin-top: 3px; }
        .remarks-box.ruled { border: 1px solid #555; margin-top: 0; }
        table.fields.remarks-row { margin-top: 10px; }

        /* `margin-top` on the first row only; later rows sit closer, as the
         * screen's grid gap does. dompdf has no :first-of-type, so the spacing
         * is carried by the row class below. */
        table.signatures { width: 100%; border-collapse: collapse; margin-top: 26px; }
        table.signatures td { text-align: center; padding: 0 14px; vertical-align: bottom; }
        .sig-name { font-size: 11.5px; font-weight: bold; padding-bottom: 2px; }
        /* Holds the line's height when there is no name above it, so signature
           cells sit on the same baseline whether or not the office is staffed. */
        .sig-name.blank { color: transparent; }
        /* 170px and centred, as the screen draws it (w-44). A rule run across
         * the whole half-width read as a table border, not a signature line. */
        .sig-line { border-bottom: 1px solid #2b2b2b; width: 170px; margin: 0 auto; }
        .sig-role { font-size: 8.5px; font-weight: bold; letter-spacing: 0.6px; color: #555; text-transform: uppercase; padding-top: 3px; }

        /*
         * ── The Mayor's Permit at the City's own scale ──────────────────────
         *
         * Measured off the photographed pad [client, 4 October 2026]: on an
         * 11-inch sheet the field captions are about 12pt, the title about
         * 28pt, the fee line about 10pt, and the ruled rows sit nearly
         * touching. Set at the clearance sizes the sheet read as a form
         * shrunk to fit — "ang liliit ng font, ang lalaki ng spacing" — so
         * these override only under body.mayors and leave the clearances at
         * the scale their own screen uses.
         */
        /* Vertical rhythm pulled in so the expiry line clears the frame on
         * Letter's 8.5-inch height; at the first pass it sat on the border. */
        body.mayors .title { font-size: 28px; letter-spacing: 6px; margin-top: 14px; }
        body.mayors table.fields { border-spacing: 0 5px; margin-top: 12px; }
        body.mayors table.signatures { margin-top: 20px; }
        body.mayors .rule { margin-top: 12px; }
        body.mayors td.label { width: 150px; font-size: 11.5px; letter-spacing: 0.8px; color: #1a1a1a; }
        body.mayors td.value.ruled { font-size: 13px; padding: 6px 9px; }
        body.mayors table.fields.triple { margin-top: 6px; }
        body.mayors .box-label { font-size: 9px; margin-top: 4px; }
        body.mayors .box { font-size: 12.5px; padding: 5px 8px; }
        body.mayors .number-cell { width: 170px; }
        body.mayors .number-cell .box-label { font-size: 8px; }
        body.mayors .number-cell .box { font-size: 11px; padding: 4px 6px; }
        body.mayors .fee-line { font-size: 11px; margin-top: 8px; }
        body.mayors .fee-label { font-size: 10px; }
        body.mayors .remarks-box.ruled { height: 46px; }
        body.mayors .sig-name { font-size: 12.5px; }
        body.mayors .sig-role { font-size: 9.5px; color: #1a1a1a; }
        body.mayors .enforcement { font-size: 9.5px; margin-top: 14px; }
        body.mayors .expires { font-size: 9.5px; }

        /*
         * ── CENRO's Certificate of Environment Clearance ────────────────────
         *
         * Green, because the client asked for the sheet in the office's own
         * colour [4 October 2026] — a soft tint rather than a saturated fill,
         * so the small italic clause and the receipt block still clear AA on
         * it. dompdf paints `body` background to the page edge, so the tint
         * is set on the frame instead and the margin stays paper-white, as a
         * printed sheet's would.
         *
         * The business is named in the middle of the page in the size the
         * office prints it (about 22pt on an 11-inch sheet), with the trade
         * and address underlined beneath it exactly as the issued copy sets
         * them; the compliance clause is italic for the same reason.
         */
        body.cenro .sheet { background: #e8f3ea; border-color: #2e7d4f; }
        body.cenro .letterhead { font-size: 8.5px; color: #333; margin-top: 2px; }
        body.cenro .city { font-size: 14px; line-height: 1.15; max-width: 330px; }
        /* Spacing spread to the sample's rhythm: the first pass left the
         * bottom third of the sheet empty under the receipt block. */
        body.cenro .cenro-title { font-family: DejaVu Sans, sans-serif; font-weight: bold; font-size: 22px; letter-spacing: 1px; margin-top: 20px; }
        body.cenro .cenro-number { text-align: center; font-size: 13px; margin-top: 6px; }
        body.cenro .cenro-issued-to { text-align: center; font-size: 12px; margin-top: 3px; }
        body.cenro .cenro-business { text-align: center; font-size: 24px; font-weight: bold; text-decoration: underline; margin-top: 18px; }
        body.cenro .cenro-trade { text-align: center; font-size: 10.5px; font-weight: bold; text-decoration: underline; margin-top: 8px; }
        body.cenro .cenro-clause { text-align: center; font-style: italic; font-size: 10.5px; line-height: 1.5; margin: 16px 40px 0; }
        body.cenro .cenro-dated { text-align: center; font-style: italic; font-size: 11px; margin-top: 16px; }
        body.cenro table.signatures { margin-top: 24px; }
        body.cenro .sig-name { font-size: 13.5px; }
        body.cenro .sig-role { font-size: 11px; color: #1a1a1a; text-transform: none; letter-spacing: 0; }
        body.cenro .cenro-receipt { font-size: 9.5px; line-height: 1.5; margin-top: 16px; }
        body.cenro .cenro-receipt-label { font-weight: bold; }

        /*
         * ── The BFP's Fire Safety Inspection Certificate ────────────────────
         *
         * Portrait Letter, set to the issued sheet's proportions [client,
         * 4 October 2026]: a centred agency letterhead, the FSIC NO. in red,
         * the title in the Bureau's blue, a running paragraph with ruled
         * fill-in lines and their italic captions beneath, then fees left and
         * signatures right. The red PAALALA and the motto close it.
         */
        body.fsic .sheet { padding: 14px 28px 12px; }
        .fsic-seal-cell { width: 108px; vertical-align: middle; }
        .fsic-seal { height: 68px; }
        .fsic-head { text-align: center; font-size: 10.5px; line-height: 1.25; }
        .fsic-dilg { font-weight: bold; font-size: 11.5px; }
        .fsic-bureau { font-weight: bold; font-size: 15px; color: #1f4e9c; letter-spacing: 0.5px; }
        table.fsic-numbers { margin-top: 8px; }
        table.fsic-numbers td { vertical-align: bottom; }
        .fsic-no-label { color: #c11212; font-weight: bold; font-size: 15px; }
        .fsic-no { display: inline-block; min-width: 190px; border-bottom: 1px solid #2b2b2b; font-size: 13px; padding: 0 6px 1px; margin-left: 6px; }
        .fsic-date-cell { width: 190px; text-align: center; }
        .fsic-date { border-bottom: 1px solid #2b2b2b; font-size: 12px; padding-bottom: 1px; }
        .fsic-title { text-align: center; font-weight: bold; font-size: 22px; color: #1f4e9c; letter-spacing: 0.5px; margin-top: 10px; }
        .fsic-purposes { margin: 4px 0 0 150px; font-size: 10.5px; font-weight: bold; color: #1f4e9c; line-height: 1.4; }
        .fsic-check { display: inline-block; width: 11px; height: 11px; line-height: 11px; border: 1px solid #1f4e9c; text-align: center; font-size: 9px; color: #1a1a1a; margin-right: 4px; }
        .fsic-others { display: inline-block; min-width: 230px; border-bottom: 1px solid #2b2b2b; color: #1a1a1a; font-weight: normal; }
        .fsic-concern { font-weight: bold; font-size: 11.5px; margin-top: 8px; }
        .fsic-body { font-size: 10.5px; line-height: 1.4; margin-top: 4px; }
        .fsic-body p { margin: 0 0 2px; text-align: justify; }
        .fsic-indent { text-indent: 48px; }
        .fsic-fill { border-bottom: 1px solid #2b2b2b; text-align: center; font-size: 11.5px; font-weight: bold; padding: 1px 4px 0; min-height: 14px; }
        .fsic-fill.fsic-nowrap { white-space: nowrap; font-size: 11px; }
        .fsic-fill.fsic-small { font-size: 9.5px; font-weight: normal; text-align: left; }
        .fsic-caption { text-align: center; font-size: 8px; font-style: italic; color: #333; margin-bottom: 3px; }
        table.fsic-owned td { vertical-align: top; }
        .fsic-lead { width: 140px; padding-top: 3px !important; white-space: nowrap; }
        .fsic-trail { width: 135px; padding: 3px 0 0 8px !important; white-space: nowrap; }
        .fsic-indent-cell { width: 150px; padding-left: 48px !important; }
        table.fsic-valid { margin-top: 2px; }
        .fsic-until-label { width: 64px; padding: 3px 0 0 8px !important; white-space: nowrap; }
        .fsic-until { width: 150px; }
        .fsic-violation { margin-top: 6px !important; }
        table.fsic-foot { margin-top: 12px; }
        .fsic-fees { width: 46%; font-size: 11px; }
        .fsic-fees-title { font-weight: bold; }
        table.fsic-fee-table { border-collapse: collapse; margin-top: 2px; }
        table.fsic-fee-table td { padding: 1px 6px 1px 0; font-size: 11px; }
        td.fsic-fee-value { border-bottom: 1px solid #2b2b2b; min-width: 150px; width: 160px; text-align: center; }
        .fsic-control { font-size: 10px; margin-top: 8px; }
        .fsic-signs { font-size: 11px; }
        .fsic-action { font-weight: bold; font-size: 11px; margin-top: 2px; }
        .fsic-sig-name { text-align: center; font-weight: bold; font-size: 12.5px; margin-top: 12px; }
        .fsic-sig-name.blank { color: transparent; }
        .fsic-sig-line { border-bottom: 1px solid #2b2b2b; margin: 1px 10px 0; }
        .fsic-sig-role { text-align: center; font-size: 9px; margin-bottom: 4px; }
        .fsic-note { text-align: center; font-style: italic; font-weight: bold; font-size: 9px; line-height: 1.3; margin: 12px 30px 0; }
        .fsic-posted { text-align: center; font-weight: bold; font-size: 13px; letter-spacing: 0.5px; margin-top: 5px; }
        .fsic-paalala { text-align: center; color: #c11212; font-weight: bold; font-size: 8px; line-height: 1.4; margin: 3px 20px 0; }
        table.fsic-last { margin-top: 4px; }
        table.fsic-last td { vertical-align: middle; }
        .fsic-copy-cell { width: 170px; }
        .fsic-copy { border: 1px solid #2b2b2b; padding: 2px 6px; font-size: 9.5px; font-weight: bold; font-style: italic; }
        .fsic-motto { font-weight: bold; font-size: 13px; }

        /*
         * ── The CPDO's Zoning Clearance ─────────────────────────────────────
         *
         * Portrait Letter, set to the issued sheet [client, 5 October 2026]:
         * a centred city head, the underlined title, a ruled box of
         * fill-in lines with bold caps values, the ticked conditions, one
         * signature, and the public-view line at the foot. The city seal sits
         * faint behind it, as the office's seal does on the paper.
         */
        body.zoning .sheet { padding: 22px 34px 20px; }
        .zoning-watermark { position: absolute; top: 300px; left: 160px; width: 400px; opacity: 0.07; }
        .zoning-seal-cell { width: 108px; vertical-align: middle; }
        .zoning-seal { height: 78px; }
        .zoning-head { text-align: center; font-family: "DejaVu Serif", serif; font-size: 12px; line-height: 1.35; padding-top: 14px; }
        .zoning-city { font-weight: bold; font-size: 14px; }
        .zoning-office { font-weight: bold; font-size: 12px; }
        .zoning-title { text-align: center; font-family: "DejaVu Serif", serif; font-weight: bold; font-size: 22px; text-decoration: underline; margin-top: 28px; }
        .zoning-subtitle { text-align: center; font-family: "DejaVu Serif", serif; font-weight: bold; font-size: 11.5px; margin-top: 2px; }
        .zoning-box { border: 1.5px solid #2b2b2b; padding: 16px 22px 18px; margin-top: 22px; }
        table.zoning-line { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.zoning-line td { vertical-align: bottom; padding: 0; }
        .zoning-label { width: 1%; white-space: nowrap; font-size: 12px; padding-right: 8px !important; }
        .zoning-label-right { padding-left: 14px !important; }
        .zoning-value { border-bottom: 1px solid #2b2b2b; font-family: "DejaVu Serif", serif; font-weight: bold; font-size: 11px; padding: 0 6px 1px !important; }
        .zoning-value.zoning-big { font-size: 15px; }
        .zoning-half { width: 30%; text-align: center; }
        .zoning-nowrap { white-space: nowrap; }
        .zoning-decision { font-size: 12px; margin-top: 14px; }
        .zoning-decision b { font-family: "DejaVu Serif", serif; }
        .zoning-conditions-title { font-weight: bold; font-size: 12px; margin: 26px 0 0 24px; }
        table.zoning-conditions { width: 560px; border-collapse: collapse; margin: 8px 0 0 44px; font-size: 11.5px; line-height: 1.35; }
        table.zoning-conditions td { vertical-align: top; padding: 0 0 3px; }
        td.zoning-tick { width: 16px; font-size: 10px; padding-top: 1px !important; }
        .zoning-sign { width: 400px; margin: 84px auto 0; text-align: center; }
        .zoning-sig-name { font-weight: bold; font-size: 13px; font-family: "DejaVu Serif", serif; }
        .zoning-sig-name.blank { color: transparent; }
        .zoning-sig-line { border-bottom: 1px solid #2b2b2b; margin-top: 1px; }
        .zoning-sig-role { font-weight: bold; font-size: 10.5px; font-family: "DejaVu Serif", serif; margin-top: 3px; }
        .zoning-public { text-align: center; font-family: "DejaVu Serif", serif; font-size: 12px; margin-top: 96px; }

        /*
         * ── The CHO's Sanitary Permit to Operate ────────────────────────────
         *
         * Portrait Letter, set to the issued sheet [client, 5 October 2026]:
         * a bold city head with the office in serif, the wide title, ruled
         * fill-in lines with bold caps values and small captions, the
         * clause in caps, and two signatures stepped right as the paper sets
         * them — Recommending Approval, then Approved further over.
         */
        body.sanitary .sheet { padding: 26px 40px 24px; }
        .san-seal-cell { width: 108px; vertical-align: middle; }
        .san-seal { height: 84px; }
        .san-head { text-align: center; padding-top: 8px; }
        .san-head-line { font-weight: bold; font-size: 12.5px; line-height: 1.35; }
        .san-office { font-family: "DejaVu Serif", serif; font-size: 15px; margin-top: 6px; }
        .san-title { text-align: center; font-weight: bold; font-size: 28px; letter-spacing: 0.5px; margin-top: 34px; }
        table.san-line { margin-top: 22px; }
        table.san-line td { vertical-align: bottom; }
        table.san-first { margin-top: 42px; }
        .san-label { width: 1%; white-space: nowrap; font-size: 12px; padding-right: 10px !important; padding-bottom: 2px !important; }
        .san-label-right { padding-left: 14px !important; }
        .san-half { width: 30%; }
        .san-fill { border-bottom: 1px solid #2b2b2b; text-align: center; font-family: "DejaVu Serif", serif; font-weight: bold; font-size: 12px; padding: 0 4px 1px; min-height: 15px; }
        .san-fill.san-wide { margin-top: 26px; font-size: 13.5px; }
        .san-nowrap { white-space: nowrap; }
        .san-caption { text-align: center; font-size: 10px; margin-top: 1px; }
        .san-clause { font-size: 12px; line-height: 1.55; margin-top: 44px; }
        table.san-sign { width: auto; margin-top: 40px; }
        table.san-sign-first { margin-top: 56px; }
        table.san-sign td { vertical-align: bottom; }
        .san-sign-pad { padding: 0 !important; }
        .san-pad-recommend { width: 90px; }
        .san-pad-approve { width: 180px; }
        .san-action { width: 150px; text-align: right; white-space: nowrap; font-size: 12px; padding: 0 10px 18px 0 !important; }
        .san-sign-cell { width: 250px; text-align: center; }
        .san-sig-name { font-family: "DejaVu Serif", serif; font-weight: bold; font-size: 12.5px; }
        .san-sig-name.blank { color: transparent; }
        .san-sig-line { border-bottom: 1px solid #2b2b2b; }
        .san-sig-role { font-size: 11.5px; margin-top: 2px; }

        /*
         * ── Malabon's Certificate of Occupancy (NBC Form B-13) ──────────────
         *
         * Landscape Letter, two pages side by side in the City's blue riveted
         * frame [client, 5 October 2026]. Each panel is absolutely placed in
         * the unbordered sheet; dompdf resolves absolute children against an
         * absolute parent, which is what lets the rivets sit in the corners.
         * Caps throughout, as the form sets it.
         */
        body.occupancy .sheet { border: none; padding: 0; top: 14px; left: 14px; right: 14px; bottom: 14px; }
        .occ-panel { position: absolute; top: 0; bottom: 0; width: 48.4%; border: 9px solid #2a5db0; background: #fff; }
        .occ-left { left: 0; }
        .occ-right { right: 0; }
        .occ-inner { position: absolute; top: 5px; left: 5px; right: 5px; bottom: 5px; border: 1.5px solid #1a1a1a; padding: 16px 20px 14px; }
        .occ-rivet { position: absolute; width: 9px; height: 9px; border-radius: 5px; background: #6b5434; }
        .occ-tl { top: 5px; left: 5px; } .occ-tr { top: 5px; right: 5px; }
        .occ-bl { bottom: 5px; left: 5px; } .occ-br { bottom: 5px; right: 5px; }
        .occ-form-no { font-size: 10px; letter-spacing: 0.5px; margin-left: 14px; }
        .occ-submitted { text-align: center; font-size: 10.5px; font-weight: bold; letter-spacing: 0.4px; margin-top: 8px; }
        .occ-date-line { width: 220px; margin: 8px auto 0; border-bottom: 1px solid #2b2b2b; text-align: center; font-size: 10px; font-weight: bold; min-height: 13px; }
        .occ-caption { text-align: center; font-size: 8px; letter-spacing: 0.4px; margin-top: 1px; }
        table.occ-checks { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.occ-checks td { font-size: 9px; padding: 3px 0; }
        .occ-box { display: inline-block; width: 8px; height: 8px; border: 1px solid #1a1a1a; margin-right: 5px; }
        .occ-blank { display: inline-block; border-bottom: 1px solid #2b2b2b; height: 9px; }
        .occ-para { font-size: 9.2px; line-height: 1.8; text-align: justify; margin-top: 14px; }
        .occ-indent { text-indent: 26px; }
        .occ-captioned { position: relative; }
        .occ-captioned span { position: absolute; top: -8px; left: 0; right: 0; text-align: center; font-size: 7px; font-weight: bold; text-indent: 0; }
        .occ-verified { text-align: center; font-size: 10.5px; font-weight: bold; letter-spacing: 0; margin-top: 24px; white-space: nowrap; }
        .occ-para.occ-left-align { text-align: left; }
        table.occ-reqs { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.occ-reqs td { width: 33.33%; padding: 12px 7px 0; vertical-align: top; }
        .occ-req-line { border-bottom: 1px solid #2b2b2b; text-align: center; font-size: 8.5px; font-weight: bold; min-height: 12px; }
        .occ-req-label { text-align: center; font-size: 8px; font-weight: bold; margin-top: 2px; line-height: 1.25; }
        .occ-fill { display: inline-block; min-width: 95px; border-bottom: 1px solid #2b2b2b; text-align: center; font-weight: bold; text-indent: 0; }
        .occ-recommend { margin-top: 30px; }
        .occ-seal-cell { width: 76px; vertical-align: middle; }
        .occ-seal { height: 64px; }
        .occ-head { text-align: center; padding-right: 50px; }
        .occ-republic { font-size: 9px; letter-spacing: 0.5px; }
        .occ-city { font-size: 20px; letter-spacing: 0.5px; margin-top: 1px; }
        .occ-office { font-size: 11.5px; letter-spacing: 0.5px; margin-top: 2px; }
        .occ-title { text-align: center; font-size: 22px; font-weight: bold; color: #1b2f6e; letter-spacing: 0.5px; margin-top: 8px; }
        table.occ-receipt-row { margin-top: 6px; }
        .occ-receipt-pad { width: 70px; }
        table.occ-receipt-table { border-collapse: collapse; }
        table.occ-receipt-table td { font-size: 9px; padding: 1px 6px 1px 0; vertical-align: bottom; }
        td.occ-receipt-value { border-bottom: 1px solid #2b2b2b; width: 170px; text-align: center; font-weight: bold; }
        .occ-qr { width: 96px; }
        .occ-qr img { width: 72px; height: 72px; }
        .occ-issued { width: 170px; }
        .occ-issued-line { border-bottom: 1px solid #2b2b2b; text-align: center; font-size: 9.5px; font-weight: bold; min-height: 12px; }
        .occ-grant { margin-top: 10px; font-size: 9.5px; line-height: 1.4; }
        table.occ-fields { width: 100%; border-collapse: collapse; margin-top: 2px; }
        table.occ-fields.occ-first-field { margin-top: 6px; }
        table.occ-fields td { font-size: 9.5px; padding: 5px 0 0; vertical-align: bottom; }
        td.occ-f-label { width: 1%; white-space: nowrap; padding-right: 6px !important; }
        td.occ-f-value { border-bottom: 1px solid #2b2b2b; font-weight: bold; text-align: center; }
        td.occ-f-area { padding-left: 10px !important; }
        td.occ-f-area-value { width: 90px; }
        .occ-small { font-size: 8.1px; line-height: 1.4; text-align: justify; margin-top: 8px; }
        .occ-sign { position: absolute; right: 26px; bottom: 16px; width: 230px; text-align: center; }
        .occ-sig-name { font-weight: bold; font-size: 10.5px; }
        .occ-sig-name.blank { color: transparent; }
        .occ-sig-line { border-bottom: 1px solid #2b2b2b; }
        .occ-sig-role { font-size: 8.5px; font-weight: bold; letter-spacing: 0.4px; margin-top: 2px; }

        .note { text-align: center; font-size: 8px; line-height: 1.5; color: #777; margin-top: 26px; }
        .verify-code { font-family: DejaVu Sans Mono, monospace; letter-spacing: 1px; }
    </style>
</head>
{{-- `mayors` scopes the City-scale type rules above to the Mayor's Permit;
     $mayors itself is only defined further down, so the raw flag is read here. --}}
<body class="{{ ($is_business_permit ?? false) ? 'mayors' : (($is_cenro_certificate ?? false) ? 'cenro' : (($is_fsic ?? false) ? 'fsic' : (($is_zoning ?? false) ? 'zoning' : (($is_sanitary ?? false) ? 'sanitary' : (($is_occupancy ?? false) ? 'occupancy' : ''))))) }}">
<div class="sheet">
@if($is_occupancy ?? false)
    @include('pdf.occupancy')
@elseif($is_fsic ?? false)
    @include('pdf.fsic')
@elseif($is_zoning ?? false)
    @include('pdf.zoning')
@elseif($is_sanitary ?? false)
    @include('pdf.sanitary')
@else
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
                @if(($is_cenro_certificate ?? false) && ! empty($letterhead))
                    {{-- CENRO's own letterhead, as the issued sheet carries it:
                         the office name as the heading, then its address and
                         lines. From config/biztrack.php, not typed here. --}}
                    <div class="republic">REPUBLIC OF THE PHILIPPINES</div>
                    <div class="city">{{ strtoupper($department_name) }}</div>
                    <div class="letterhead">{{ $letterhead['address'] }}</div>
                    <div class="letterhead">Trunkline No: {{ $letterhead['trunkline'] }} &nbsp;|&nbsp; Email: {{ $letterhead['email'] }}</div>
                    <div class="letterhead">Website: {{ $letterhead['website'] }}</div>
                @else
                    <div class="republic">REPUBLIC OF THE PHILIPPINES</div>
                    <div class="city">CITY OF MALABON</div>
                    <div class="office">{{ strtoupper($department_name ?? 'Business Permits and Licensing Office') }}</div>
                @endif
            </td>
            {{-- The City's two numbered boxes, on the Mayor's Permit only.
                 A clearance has no Business Account Number on its face and
                 prints its number in the field grid below. --}}
            @if(($is_business_permit ?? false))
                <td class="number-cell">
                    <div class="box-label">Business Account Number</div>
                    <div class="box">{{ $ban ?: ' ' }}</div>
                    <div class="box-label">Mayor's Permit Number</div>
                    <div class="box">{{ $permit_number }}</div>
                </td>
            @endif
            <td class="qr-cell">
                @if($qr)
                    <img src="{{ $qr }}" alt="Verification QR code">
                    <div class="caption">Scan to verify</div>
                @endif
            </td>
        </tr>
    </table>

    @if($is_cenro_certificate ?? false)
        {{-- CENRO's sheet names the business in the middle of the page rather
             than in a field grid: title, number, "is hereby issued to", the
             business, its trade and address, then the compliance clause. --}}
        <div class="title cenro-title">CERTIFICATE OF ENVIRONMENT CLEARANCE</div>
        <div class="cenro-number">{{ $permit_number }}</div>
        <div class="cenro-issued-to">is hereby issued to</div>
        <div class="cenro-business">{{ $business_name ?: 'Business removed from register' }}</div>
        <div class="cenro-trade">
            {{ $line_of_business ?: '—' }} &ndash; with address at {{ collect([$address, $barangay])->filter()->implode(', ') ?: '—' }}, {{ str_contains(strtoupper($city ?: ''), 'CITY') ? strtoupper($city) : strtoupper(trim(($city ?: 'Malabon').' City')) }}
        </div>
        <div class="cenro-clause">
            This issuance of certificate shall not exempt the grantee from compliance with applicable permits
            required by DENR and the City Government of Malabon as stated in the application form and in
            accordance with Article W &ndash; Environmental Protection and Preservation Fees of the City Ordinance
            A10-2016, The New Revenue Code of the City of Malabon.
        </div>
        <div class="cenro-dated">Issued this {{ $valid_from }} at the Malabon City Hall.</div>
    @else
        <div class="title">{{ strtoupper($permit_type_name) }}</div>
    @endif
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
        $fullAddress = collect([$address, $barangay, $city])->filter()->implode(', ') ?: null;
        $mayors = $is_business_permit ?? false;
        $cenro = $is_cenro_certificate ?? false;

        /*
         * The Mayor's Permit carries the City's own three rows and nothing
         * else at the head: owner, business, address. Its number is already in
         * the box above, its line of business and receipt sit under the rule,
         * and the issue date shares a line with area and headcount. A
         * clearance keeps the full grid, which is the only place its number
         * and tracking ID appear.
         */
        $rows = $mayors
            ? [
                ['Name of Owner', $cell($owner_name)],
                ['Business Name', $cell($business_name, 'Business removed from register')],
                ['Address', $cell($fullAddress)],
            ]
            : [
                ['Name of Owner', $cell($owner_name)],
                ['Business Name', $cell($business_name, 'Business removed from register')],
            ];

        if (! $mayors) {
            if ($trade_name) {
                $rows[] = ['Trade Name', $cell($trade_name)];
            }
            $rows[] = ['Business Address', $cell($fullAddress)];
            if ($line_of_business) {
                $rows[] = ['Line of Business', $cell($line_of_business)];
            }
            $rows[] = ['Permit No.', $cell($permit_number)];
            $rows[] = ['Date of Issue', $cell($valid_from)];
            $rows[] = ['Valid Until', $cell($valid_until)];
            $rows[] = ['Business Account No.', $cell($ban)];
        }
    @endphp

    {{-- No field grid on CENRO's sheet: the business is named in the block
         above, and the rest of its face is the receipt at the foot. --}}
    @if(! $cenro)
    <table class="fields">
        @foreach($rows as [$label, $box])
            <tr>
                <td class="label">{{ $label }}</td>
                <td class="{{ $box['class'] }}{{ $mayors ? ' ruled' : '' }}">{{ $box['text'] }}</td>
            </tr>
        @endforeach
    </table>
    @endif

    {{-- Date of issue, area and headcount share one line, as the paper sets
         them. Only on the Mayor's Permit; a clearance has neither figure. --}}
    @if($mayors)
        <table class="fields triple">
            <tr>
                <td><div class="box-label">Date of Issue</div><div class="box">{{ $valid_from ?: ' ' }}</div></td>
                <td><div class="box-label">Area</div><div class="box">{{ $area_sqm ?: ' ' }}</div></td>
                <td><div class="box-label">Employees</div><div class="box">{{ $employees ?: ' ' }}</div></td>
            </tr>
        </table>
    @endif

    @if(! $cenro)
        <div class="rule"></div>
    @endif

    {{-- The fee line, under the rule, exactly where the City prints it. --}}
    @if($mayors)
        <div class="fee-line"><span class="fee-label">Line of Business:</span> {{ $line_of_business ?: ' ' }}</div>
        <div class="fee-line">
            <span class="fee-label">Amount Paid:</span> {{ $amount_paid ?: ' ' }}
            &nbsp;&nbsp;&nbsp;<span class="fee-label">OR No.:</span> {{ $or_number ?: ' ' }}
            &nbsp;&nbsp;&nbsp;<span class="fee-label">Date Paid:</span> {{ $date_paid ?: ' ' }}
        </div>
    @endif

    {{-- Remarks: caption to the LEFT on the Mayor's Permit, as both the City's
         pad and our own screen set it; above the box on a clearance, which is
         how that sheet reads on screen too. --}}
    @if($mayors)
        <table class="fields remarks-row">
            <tr>
                <td class="label">Remarks:</td>
                <td><div class="remarks-box ruled"></div></td>
            </tr>
        </table>
    @elseif(! $cenro)
        <div class="remarks-label">Remarks</div>
        <div class="remarks-box"></div>
    @endif

    @php
        /*
         * Whatever the controller built, with no fallback of its own.
         *
         * This held a literal City Mayor / Officer-in-Charge pair, duplicated
         * in the React certificate, for the days when the register knew of no
         * signatory but CENRO's. Both roles now come down in `$signatories`
         * with their names on them — see PermitFace::signatureBlock — so a
         * fallback here could only ever print a second, nameless copy of a
         * line that is already on the sheet.
         */
        $blocks = $signatories;
    @endphp
    {{--
        TWO to a row, as the screen sets them (sm:grid-cols-2).

        They were all in one row, each column `100 / count` wide. With CENRO's
        four — City Mayor, Officer-in-Charge, Evaluator, Chief-CENRO — that is
        25% each on portrait A4, and the fourth ran off the right edge of the
        page: "Mark Lloyd A. Mesina" was cut mid-name in the download while the
        website showed all four. Found by rendering the PDF and looking at it,
        which is the only way this class of fault shows up.

        Chunking also keeps the signature lines at their drawn width rather
        than squeezing them narrower with every office that adds a signatory.
    --}}
    @foreach(array_chunk($blocks, 2) as $pair)
        <table class="signatures">
            <tr>
                @foreach($pair as $block)
                    {{-- A sheet with ONE signature (CENRO's Chief) centres it
                         across the page instead of leaving it in the left half. --}}
                    <td style="width: {{ count($blocks) === 1 ? '100' : '50' }}%">
                        <div class="sig-name {{ $block['name'] ? '' : 'blank' }}">{{ $block['name'] ?: '.' }}</div>
                        <div class="sig-line"></div>
                        <div class="sig-role">{{ $block['role'] }}</div>
                    </td>
                @endforeach
                {{-- An odd count leaves the second half empty rather than
                     stretching one signature across the sheet. --}}
                @if(count($pair) === 1 && count($blocks) > 1)
                    <td style="width: 50%"></td>
                @endif
            </tr>
        </table>
    @endforeach

    {{-- The City's own enforcement terms, off the paper, red where the paper
         sets them red. Only on the sheet that carries them. --}}
    @if($mayors)
        <div class="enforcement">
            <span class="stop">Subject for inspection.</span>
            Display in a conspicuous place at business establishment.
            <span class="stop">Not valid without official receipt.</span>
        </div>
        <div class="expires">(THIS PERMIT WILL EXPIRE ON {{ strtoupper($valid_until ?: '') }})</div>
    @endif

    {{-- CENRO's receipt block, bottom-left as the office prints it. Amount
         Paid is CENRO's share of the filing's bill, not the total - see the
         controller. Every line prints, blank or not, so the sheet reads
         against the paper one line for line. --}}
    @if($cenro)
        <div class="cenro-receipt">
            <div><span class="cenro-receipt-label">Official Receipt:</span> {{ $or_number ?: '' }}</div>
            <div><span class="cenro-receipt-label">Amount Paid:</span> {{ $office_amount_paid ?: '' }}</div>
            <div><span class="cenro-receipt-label">Date Paid:</span> {{ $date_paid ?: '' }}</div>
            <div><span class="cenro-receipt-label">Business Account No.:</span> {{ $ban ?: '' }}</div>
        </div>
    @endif

    {{-- Off the Mayor's Permit on the client's instruction [4 October 2026].
         That sheet already carries the City's own enforcement terms above, and
         the QR beside the seal is how a permit is checked; a printed verify URL
         across the foot was a second warning in our words and an address nobody
         types. The clearances keep it - they have no enforcement block, so
         without this they would say nothing about verifying at all. --}}
    @if(! $mayors && ! $cenro)
        <div class="note">
            Subject to revocation for non-compliance with existing laws, ordinances, rules and regulations.<br>
            Verify authenticity with code <span class="verify-code">{{ $permit_number }}</span> at {{ $verify_url }}
        </div>
    @endif
@endif
</div>
</body>
</html>
