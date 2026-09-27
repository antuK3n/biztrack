<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Serif, serif; }
        body { color: #1a1a1a; margin: 0; padding: 54px 56px; font-size: 12.5px; line-height: 1.6; }
        .ident { font-size: 10px; color: #333; }
        .ident .row { margin: 1px 0; }
        .ident .k { display: inline-block; width: 96px; color: #666; }
        .ident-rule { border-bottom: 1px solid #1a1a1a; margin: 10px 0 22px; }
        h1 { font-size: 13px; text-align: center; letter-spacing: 0.5px; margin: 0 0 22px; }
        p.clause { margin: 0 0 14px; text-align: justify; }
        table.sig { width: 100%; margin-top: 52px; border-collapse: collapse; }
        table.sig td { width: 50%; text-align: center; vertical-align: bottom; padding: 0 14px; }
        table.sig .rule { border-top: 1px solid #1a1a1a; padding-top: 2px; }
        table.sig .sub { font-size: 10px; }
        p.jurat { margin-top: 30px; text-align: justify; }
        .book { margin-top: 22px; }
        .book div { margin: 1px 0; }
    </style>
</head>
<body>
    {{--
        The affidavit BFP-QSF-FSED-002 asks for on its new-business branch:
        "AFFIDAVIT OF UNDERTAKING THAT THERE WAS NO SUBSTANTIAL CHANGES MADE ON
        BUILDING/ESTABLISHMENT".

        ── Why this one gets a template and the Certificate of Completion does
           not ──────────────────────────────────────────────────────────────

        The BFP paper names this affidavit and prints no form for it — there is
        no prescribed layout, so an applicant either finds wording of their own
        or their notary drafts it. Handing them the clauses is the whole value,
        exactly as it is for CPDD's Section X declaration.

        Form B-10, the Certificate of Completion, is the opposite case: the
        Office of the Building Official issues that form, prescribes its layout
        and expects its own copy back, signed and SEALED by the architect or
        civil engineer. Rendering our own version would hand applicants a
        document that looks official, is not, and could be refused at the
        counter — so the checklist points them at the office instead.

        ── The two identifiers, and why they sit outside the clauses ─────────

        Same reasoning as the zoning declaration, and it is not cosmetic. A
        notarised page is sworn to as a whole, so anything inside the clauses is
        something the affiant is swearing is true. This page is downloaded while
        the sheet is still editable — an applicant who swore to a floor area and
        then corrected it before submitting would have sworn to something false
        and nobody would catch it. Two facts that cannot go stale between
        download and submission, above the line, and no more.
    --}}
    <div class="ident">
        <div class="row">Application for a Fire Safety Inspection Certificate</div>
        <div class="row"><span class="k">Tracking ID</span>{{ $tracking_id }}</div>
        <div class="row"><span class="k">Establishment</span>{{ $business_name }}</div>
    </div>
    <div class="ident-rule"></div>

    <h1>AFFIDAVIT OF UNDERTAKING</h1>

    <p class="clause">
        That I am the owner, or the duly authorised representative of the owner, of the
        establishment identified above, and I am applying for a <strong>Fire Safety Inspection
        Certificate</strong> in support of a business permit.
    </p>

    <p class="clause">
        That <strong>no substantial changes have been made on the building or
        establishment</strong> since the Certificate of Occupancy for it was issued; that its
        occupancy, its floor area and its means of egress remain as they were approved; and that no
        alteration requiring a building permit has been undertaken.
    </p>

    <p class="clause">
        That I undertake to notify the Bureau of Fire Protection, and to secure the corresponding
        permits and clearances, before carrying out any such change.
    </p>

    <p class="clause">
        That I am executing this affidavit to attest to the truth of the foregoing and for whatever
        lawful purpose it may serve.
    </p>

    {{--
        Both rules blank, as on CPDD's. The affiant's name is not pre-printed:
        "(Signature over Printed Name)" is an instruction they carry out in
        front of the notary.
    --}}
    <table class="sig">
        <tr>
            <td>
                <div class="rule">Owner/Authorized Representative</div>
                <div class="sub">(Signature over Printed Name)</div>
            </td>
            <td>
                <div class="rule">Position/Title</div>
            </td>
        </tr>
    </table>

    <p class="jurat">
        Subscribed and sworn before me this ____ day of __________, 20____ at ______________,
        affiant exhibiting to me his/her ______________ with ID No. ______________, issued on
        __________.
    </p>

    <div class="book">
        <div>Doc. No.:</div>
        <div>Page No.:</div>
        <div>Book No.:</div>
        <div>Series of:</div>
    </div>
</body>
</html>
