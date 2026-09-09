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
        Section X of MCG-CPDD-FO-003 v1.2, and NOTHING else.

        ── What came off, and why ────────────────────────────────────────────

        The first build of this page carried a CPDD masthead, the Document
        ID/Version/Effectivity table, the heading "X. APPLICANT DECLARATION
        (MUST BE NOTARIZED PRIOR TO SUBMISSION OF APPLICATION)", a dashed
        how-to-use box and the controlled-document footer. The client removed
        all of it: *"there are unnecessary stuf that doesn't need to be there.
        Just focus on the content of the declaration only and remove the 'X'."*

        They are right, and the reason is what this page IS. It is not a copy of
        MCG-CPDD-FO-003 — it is one section of it, lifted out so the applicant
        can take it to a notary. Reproducing the form's letterhead and document
        control block around a fragment makes it look like the controlled
        document without being it, which is the worst of the three options: a
        counter comparing it against their master copy finds a form that is
        missing items I to IX. The "X." itself was the same mistake in one
        character — a section number with no sections around it.

        The wording of the two clauses, the signature block, the jurat and the
        Doc./Page/Book/Series lines are TRANSCRIBED FROM THE PAPER, including
        the sentence that reads "share said date" where the office plainly meant
        "data". A template that silently corrects the LGU's own controlled
        document no longer matches the one at the counter, and the applicant is
        the person who gets told so at the window.

        ── The one thing that is ours ────────────────────────────────────────

        The three identification lines at the top, above a rule that separates
        them from the sworn text. They exist because this page leaves the system
        and comes back as a scan: without them CPDD matches a loose notarised
        sheet to a filing by the business name the affiant happened to write.

        They are ABOVE the declaration and outside it, deliberately. A notarised
        page is sworn to as a whole, so anything printed inside the clauses is
        something the affiant is swearing is true — and this page is downloaded
        mid-flow, while the form is still editable. An applicant who notarised a
        page reading "120 sq. m." and then corrected the figure to 140 before
        submitting would have sworn to something false, and nobody would notice.
        That is why the fuller version the client asked about — owner address,
        contact numbers, floor area, line of business — was recommended against
        and is not here. Two facts that cannot go stale between download and
        submission, and no more.
    --}}
    <div class="ident">
        <div class="row">Application for Locational Clearance (Business Activities)</div>
        <div class="row"><span class="k">Tracking ID</span>{{ $tracking_id }}</div>
        <div class="row"><span class="k">Name of Firm</span>{{ $business_name }}</div>
    </div>
    <div class="ident-rule"></div>

    <p class="clause">
        That, I am an applicant for a <strong>Locational Clearance</strong>; That I have caused the
        above application and the herein attached prepared documents, and have read and with full
        knowledge of the contents thereof and that all information therein are true and correct to
        the best of my knowledge.
    </p>

    <p class="clause">
        That I have read and understood the <strong>Data Privacy</strong> Policy and hereby give my
        consent to the <strong>City Government of Malabon</strong>, and any person acting in its
        behalf to collect, store, record, process, and update my personal data as part of its
        database and share said date to the national government, its agencies and instrumentalities
        and other local government units.
    </p>

    {{--
        Both rules are blank, as they are on the paper. The applicant's name is
        NOT pre-printed on the left one: "(Signature over Printed Name)" is an
        instruction to the affiant, and the notary watches them carry it out.
    --}}
    <table class="sig">
        <tr>
            <td>
                <div class="rule">Applicant/Representative</div>
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
