import pymupdf

SRC = "/Users/kenmondragon/Downloads/R INTEGRATION DRAFTS.pdf"
OUT = "/Users/kenmondragon/Desktop/R INTEGRATION - ANNOTATED (Prof Notes).pdf"

PW, PH = 612.0, 792.0
GUT = 12.0          # gap between original page and notes column
COLW = 248.0        # notes column width
NW = PW + GUT + COLW + 16   # new page width

COL_X = PW + GUT + 8

KIND = {
    "rename":   ((0.85, 0.42, 0.05), "RENAME"),
    "split":    ((0.78, 0.10, 0.10), "SPLIT / RESTRUCTURE"),
    "system":   ((0.10, 0.32, 0.72), "UI / SYSTEM"),
    "paper":    ((0.42, 0.20, 0.62), "PAPER"),
    "side":     ((0.05, 0.48, 0.28), "WHOSE SIDE"),
    "ok":       ((0.05, 0.48, 0.28), "APPROVED - NO CHANGE"),
    "demo":     ((0.55, 0.35, 0.05), "DEMO READINESS"),
}

# (page_index, anchor_text, occurrence, kind, heading, body, source)
NOTES = [
    (0, "1. Analytics Dashboard", 0, "side",
     "Side: Admin / BPLO - state it",
     "Not marked on the paper. She required every feature to name whose side it is on, "
     "so this must be written in. Also raised here: \"Wala akong nakikita na forecasting. "
     "Puro dashboard lang 'to.\" Feature 1 is entirely descriptive - make the predictive "
     "element visible or say where it lives.",
     "SAID"),

    (3, "Average Processing", 0, "rename",
     "Title corrected on the page",
     "Parentheses inserted around (RA 11032); the word \"by\" struck through; a word "
     "inserted beneath, reading \"for\".\n"
     "-> \"Average Processing Time for (RA 11032) Tier\".\n"
     "AMBIGUOUS: the inserted word overlaps the printed word \"Tier\", so she may have "
     "meant to delete that too. Confirm the exact wording with her.",
     "MARKED ON PAGE 4 + NOTES"),

    (3, "Average Processing", 0, "paper",
     "Where do the tiers land in the 43%?",
     "\"Meron kayong Simple, Complex and everything. Saan 'yun nakasama sa 43%? Ang "
     "computation ninyo, kung Simple plus ganyan plus ganyan, overall, divided by ganoon?\"\n"
     "-> Show how the three tier averages relate to the headline compliance figure. "
     "Right now they sit side by side with no stated relationship.",
     "SAID"),

    (3, "Time-in-Stage", 0, "rename",
     "Row circled, with a question mark",
     "\"Time-in-Stage\" is internal jargon. Rename to something an LGU reader understands "
     "(e.g. Average Time per Department) and define what starts and stops the clock.",
     "MARKED ON PAGE 4"),

    (4, "Monitoring", 0, "rename",
     "Rename - written on the page",
     "\"Rate\" was added after the row title, \"thru RA ...\" written beneath, and "
     "\"? change title\" in the margin.\n"
     "-> Section becomes \"Compliance Monitoring Rate through RA 11032\".\n"
     "-> The indicator \"RA 11032 Processing\" becomes \"Processing Rate Compliance to "
     "RA 11032\". She rejected \"legal processing\": \"Hindi legal 'yan.\"",
     "MARKED ON PAGE 5 + SAID + NOTES"),

    (4, "Monitoring", 0, "split",
     "Split the three indicators apart",
     "\"Ano naman 'yung Business Permit Compliance, at saka 'yung Renewal Compliance? Pag "
     "in-add ko together, divided by 2, hindi siya papasok kay Processing ng RA 11032.\" "
     "... \"Iwalay kasi.\"\n"
     "-> Three different denominators cannot share one card or one heading. Present each "
     "separately with its denominator stated.\n"
     "-> Present 43% as a FAILING figure: \"Ibig sabihin, hindi ka compliant.\"",
     "SAID + NOTES"),

    (4, "Approaching Expiry", 0, "split",
     "Wrong title, wrong contents",
     "\"Parang mali 'yung title. Pinapatay kayo talaga ng title.\"\n"
     "-> Drop the 30 / 60 / 90-day windows: \"Ano ba ang kaibahan ng 30, 60, at saka 90?\" "
     "Replace with three states: PENDING, NEAR (approaching), OVERDUE / EXPIRED.\n"
     "-> Show it per clearance, not per business permit: \"Hindi business permit ang "
     "nakasulat, kasi ang output n'un WOULD BE the business permit. Ang kailangan ko ay "
     "Sanitary. Inspection. Application.\" The six office clearances are what expire and "
     "block renewal.\n"
     "-> Suggested order: business renewal, renewal permit, monitoring.",
     "MARKED ON PAGE 5 + SAID + NOTES"),

    (4, "Top Barangays", 0, "paper",
     "Title must match the data",
     "Used as her worked example of the rule: \"Pag sinabi mong Top Barangay, Analytics "
     "Dashboard - mali pa rin. Tingnan mo kung may kinalaman 'yung title mo doon sa "
     "subtitle at sa data na binibigay mo.\"",
     "SAID + NOTES"),

    (4, "Lines", 0, "rename",
     "Top Lines of Business -> Top 5 Lines of Business",
     "\"Eh 'Top Lines' eh. Dapat TOP 5.\" The shares shown (6.8 + 5.7 + 5.7 + 5.5 + 5.3) "
     "do not sum to 100 - only defensible if the title says top 5.\n"
     "-> Also: use a PIE CHART, not a table. \"Ayan 'yung sinasabi kong pwedeng maging pie "
     "chart. Hindi puro table.\"",
     "MARKED ON PAGE 6 + SAID + NOTES"),

    (5, "Inspections", 0, "system",
     "Question mark on the row",
     "She walked the numbers (scheduled 419, completed 485, passed / failed / conditional, "
     "rating 3.9%) then asked: \"Do you think that is favorable doon sa tao - kung sino ang "
     "nagre-record ng sanitary, ng mga inspections?\"\n"
     "-> Present it from the point of view of whoever has to act, and tie it to her "
     "scenario: \"Lalo na pag malapit nang mag-expire, hindi pa natin natatapos.\" Backlog "
     "against upcoming expiry is the useful cut.\n"
     "-> State on screen that pass rate is over COMPLETED, not scheduled.",
     "MARKED ON PAGE 6 + SAID"),

    (5, "Officer Activity", 0, "split",
     "Split it, and rename it",
     "\"Ano 'yung Officer Activity? Response, request, meetings? ... If I were you, hindi "
     "OFFICER ACTIVITY ang ilalagay ko dito.\"\n"
     "-> Three unrelated measures are averaged under one label. Break them out and name "
     "each for what it answers: how fast staff respond / how many requests / how many "
     "meetings.\n"
     "-> \"Sino ba si officer - si staff?\" Use the role name the system uses.\n"
     "-> \"Ang ini-expect ko diyan, pag in-add mo, 100%.\" Any breakdown shown must "
     "reconcile to 100%, or must not be shown as a breakdown.",
     "SAID + NOTES"),

    (4, "Organization", 0, "ok",
     "The only item she approved",
     "\"I'm sold. Partnership 556. Ayan, medyo malinaw 'yan. Okay?\"\n"
     "Keep it as the reference for how the other panels should read.",
     "SAID"),

    (5, "2. Renewal", 0, "side",
     "Side: Admin / BPLO - state it",
     "Not marked. State it, per her rule that every feature names its side.",
     "SAID"),

    (5, "2. Renewal", 0, "paper",
     "\"Bakit kailangan ng renewal risk?\"",
     "\"Dapat may reason bakit kayo maglalagay.\" Answer this in the opening paragraph - "
     "why the feature exists, who uses it, and what they do with the result.",
     "SAID"),

    (6, "Estimated", 0, "system",
     "Risk must read as a per-renewal probability",
     "In the submitted copy this row is called RISK SCORE (0-100 weighted composite). She "
     "rejected it: \"Ano 'yung risk score? ... Parang magulo 'yung risk for me.\"\n"
     "She restated what she expected: \"Pag nag-renew ako, bibigyan mo ako ng risk - mga 3% "
     "ang risk nito. Parang ganito.\" ... \"Bawat renewal, meron siyang risk.\"\n"
     "-> Keep the weighted formula, but explain each weight in plain language and present "
     "the output as a probability on an individual renewal.",
     "SAID"),

    (7, "Recommended", 0, "system",
     "Businesses at Risk must be colour-coded",
     "\"Pag Business Success at Risk, meron pula, meron green. Dito mga pula 'to, every one "
     "of these - pag i-click mo, o doon ka lang mag-hover, ano-ano 'yung ano. Kaysa "
     "ganyan.\"\n"
     "-> Red for high risk, green for low, and each row expands on click or hover to show "
     "WHY it is at risk. The flat table she saw was rejected.\n"
     "-> Recommended Actions stay: \"Kaya ako sinusunod, ng risk. So dapat meron ka diyan.\"",
     "SAID + NOTES"),

    (7, "3. Notifications for Business Owners", 0, "side",
     "Side: BUSINESS OWNERS - confirmed by her",
     "\"Iba po pala - for business owners.\" This is the one feature whose side she "
     "confirmed out loud, and the exchange that produced the rule. Label the other five "
     "the same way.",
     "SAID + NOTES"),

    (8, "Notifications for Business Owners feature", 0, "system",
     "Group notifications per application",
     "\"Lalabas po dito. Sa lahat? Isa-isa pa 'yan eh. ... Pag lumabas natin, uurong lahat "
     "'to. Paano kung APAT 'yung may application?\"\n"
     "-> A flat chronological stream does not scale. Group by application so four live "
     "applications do not push each other off the screen.",
     "SAID"),

    (9, "Meanwhile", 0, "system",
     "\"Application Updates\" is too vague",
     "\"Application updates - ibig sabihin, numbers. ... Pag kinlik mo 'yan, makikita ko "
     "dito ano-ano 'yung mga 'yun.\"\n"
     "-> Put a COUNT on the heading and make it EXPAND to the detail on click.",
     "SAID"),

    (9, "Meanwhile", 0, "demo",
     "Could not be demonstrated in the room",
     "She asked to see it logged in as an actual business owner: \"Hindi nga kayo nag-login "
     "as user?\" It was not shown cleanly.\n"
     "-> Have a working business-owner session ready before the next defense.",
     "SAID"),

    (9, "4. Business Growth Analysis", 0, "side",
     "Side: ( Admin, City ) - written on the page",
     "Handwritten beside the heading: \"( Admin, City )\".\n"
     "\"Ito ay para kanino naman? Kay user o admin? ... Ito ay admin.\" ... \"ng buong "
     "munisipyo ninyo?\" -> admin, city-wide in scope. Put both in the paper.",
     "MARKED ON PAGE 12 + SAID"),

    (10, "Business Growth", 0, "demo",
     "Show the numbers actually moving",
     "\"Pakita ninyo sa akin na nagbabago-bago 'yan.\"\n"
     "-> At the next defense, demonstrate the figures changing rather than showing a "
     "static screen.\n"
     "No objection was raised to the six reports themselves - but the justify-every-number "
     "rule still applies to each.",
     "SAID"),

    (11, "5. Business Location Insights", 0, "side",
     "Side: ( City residents, Stakeholders ) - written on the page",
     "Handwritten beside the heading: \"( City residents, Stakeholders )\", answering her "
     "own question: \"Saan 'to? ... 'Yung mga residents, city residents - tama? Or "
     "stakeholders?\"\n"
     "This is also the counterweight to her complaint on Feature 6 that everything is "
     "admin-facing.",
     "MARKED ON PAGE 15 + SAID"),

    (12, "Business Location Insights is an R-powered", 0, "system",
     "It did not work in the demo - now fixed",
     "\"Sabi mo mag-choose ako ng line of business, di ba? ... Later step po kasi.\" Then: "
     "\"WALA. HINDI SIYA GUMAGANA. A matter of yes or no - hindi siya gumagana.\"\n"
     "-> Cause: line of business was chosen at a later wizard step than the map, so the "
     "insight had nothing to compute against.\n"
     "-> FIXED on branch fix/location-line-of-business. Re-verify live - she will retry "
     "this exact path.",
     "SAID"),

    (12, "Business Location Insights is an R-powered", 0, "paper",
     "Trace it from the resident's actual question",
     "\"So kung ako ang resident, bakit ko siya gagamitin? ... Gusto kong magtayo ng coffee "
     "shop - ano ang gagawin ko ngayon? ... Paano ko nga makikita?\"\n"
     "She never got a satisfying answer. Walk the path end to end: from \"is this area "
     "saturated for a coffee shop?\" to the number on screen.",
     "SAID"),

    (14, "6. Permit Processing Time Monitoring", 0, "side",
     "Side: ( Admin ) - written on the page",
     "Handwritten beside the heading: \"( Admin )\".\n"
     "\"Sino naman ang gumagamit nito? Admin na naman.\"\n"
     "-> State it in the paper, and name who in the office actually opens this view.",
     "MARKED ON PAGE 17 + SAID"),

    (14, "6. Permit Processing Time Monitoring", 0, "paper",
     "Nothing here is for the user",
     "Said while marking this feature, but aimed at the whole submission: \"WALA KAYONG "
     "NILAGAY PARA SA USER, kaya user, kaya sa resident.\"\n"
     "-> Five of the six features serve admin. Feature 5 is the only resident-facing one. "
     "Address the imbalance in the paper rather than letting her count it herself.",
     "SAID"),

    (14, "6. Permit Processing Time Monitoring", 0, "paper",
     "Justify the entire feature",
     "This is where she pressed hardest: \"Kailangan malaman kung may need diyan. "
     "Otherwise, nilalagyan na natin lahat ng data kasi NEED to be. ... Is it included in "
     "the report? It is the most usual question that is being asked.\"\n"
     "-> Answer directly: does departmental processing-time monitoring appear in any report "
     "the admin actually submits? If not, justify it another way or cut it.",
     "SAID"),

    (14, "evaluates every new week", 0, "paper",
     "The reporting period may not be weeks",
     "\"Baka naman hindi WEEKS ang tao - kasi meron pong operational terms.\"\n"
     "-> Separate point from the wording of Flagged Weeks: she doubted the weekly bucket "
     "itself. Check how the office actually periodises its reports before defending it.",
     "SAID"),

    (15, "Department", 0, "paper",
     "Define the metric and its direction",
     "\"Anong 8.5? ... Ito bang PROCESSING ay PROCESSED? O processing time is from the "
     "application until the process?\"\n"
     "-> Define what event starts the clock and what stops it.\n"
     "-> \"So dapat bumaba ba? Hindi dapat tumataas - kasi processing time 'yan.\" State "
     "the desired direction on the chart (lower is better). Without it the chart cannot be "
     "read as good or bad.",
     "SAID + NOTES"),

    (15, "Process      Status", 0, "rename",
     "\"Inside / Outside Normal Range\" is opaque",
     "A struck-through mark with an arrow pointing straight at the \"Inside\" status pill.\n"
     "\"Ito, ano 'yung INSIDE? ... Ano 'yung FLAG?\"\n"
     "-> Replace the control-chart vocabulary with plain language describing the "
     "situation, not the statistical state.",
     "MARKED ON PAGE 17 + SAID"),

    (15, "Flagged Weeks", 0, "rename",
     "\"Government Terms\" - written beside this row",
     "Her handwriting next to Flagged Weeks reads: \"- Government Terms\".\n"
     "-> Confirm the term the office actually uses for a period that breached its service "
     "standard, and adopt it. (\"I-check sa office kung tama ba 'yung term ng flagged "
     "weeks.\")",
     "MARKED ON PAGE 17 + NOTES"),

    (15, "Gradual Slowdown", 0, "rename",
     "The name did not land",
     "\"Ano 'to, GRADUAL SLOW-MO? Ano na 'yan?\"\n"
     "-> Rename and re-explain it, or drop it under her \"masyadong maraming data\" rule.",
     "SAID"),
]

CROSS = [
    ("Every data element must be justified",
     "For each item on a dashboard or report, state WHY it is there, WHO uses it, HOW they use "
     "it, and WHETHER it appears in an official report. \"There should be a basic idea why it's "
     "there. It is the most usual question that is being asked.\""),
    ("Indicate whose side each feature is on",
     "Admin / BPLO, business owner, or city resident / stakeholder. She wrote this directly on "
     "Features 4, 5 and 6, and confirmed Feature 3 out loud. Features 1 and 2 still need it."),
    ("Titles must match the data underneath them",
     "Raised on Top Barangays, Compliance Monitoring, Permits Approaching Expiry and Department "
     "Processing Time Chart. \"Tingnan mo kung may kinalaman 'yung title mo doon sa subtitle at "
     "sa data na binibigay mo.\""),
    ("Do not lump unrelated indicators together",
     "Said three separate times - \"pinagbigit-bigit niyo na naman.\" If two numbers cannot be "
     "meaningfully added or averaged, they do not belong in one card. Applies to Compliance "
     "Monitoring and to Officer Activity."),
    ("There is too much data",
     "\"Parang masyadong maraming data.\" Anything that fails rule 1 should be cut rather than "
     "defended."),
    ("The dashboard must be fast to read and fast to search",
     "Her scenario: an owner phones to follow up a renewal and has lost the receipt. Staff must "
     "be able to find it BY OWNER NAME, not only by reference number. \"Kung wala 'yung resibo, "
     "may alternate way para gawin niyo. So why not give it?\""),
    ("Where is the forecasting?",
     "\"Wala akong nakikita na forecasting. Puro dashboard lang 'to.\" Feature 1 is entirely "
     "descriptive; the predictive work sits in Features 2 and 5 and does not read as prediction."),
    ("She will check these next time",
     "\"Yung susunod na group work ninyo lang ang kukunin ko sa task ng mga ganyan.\" And for "
     "anything left unchanged she wants a stated reason: \"Gusto naman ng explanation bakit "
     "hindi.\" Silence will be read as the note being ignored."),
]


def wrap(page, rect, text, size, font="helv", color=(0, 0, 0), align=0):
    """Insert text, shrinking until it fits. Returns consumed height."""
    s = size
    while s > 3.6:
        rc = page.insert_textbox(rect, text, fontsize=s, fontname=font,
                                 color=color, align=align, lineheight=1.28)
        if rc >= 0:
            return s
        s -= 0.3
    return s


_FONTS = {}


def measure(text, size, width, font="helv"):
    """Rough height of wrapped text, with headroom."""
    if font not in _FONTS:
        _FONTS[font] = pymupdf.Font(fontname=font)
    f = _FONTS[font]
    lines = 0
    for para in text.split("\n"):
        if not para:
            lines += 1
            continue
        cur = ""
        n = 1
        for w in para.split(" "):
            t = (cur + " " + w).strip()
            if f.text_length(t, size) > width and cur:
                n += 1
                cur = w
            else:
                cur = t
        lines += n
    return lines * size * 1.35 + 4


def header(page, title, sub=None):
    page.draw_rect(pymupdf.Rect(0, 0, NW, 46), color=None, fill=(0.11, 0.15, 0.42))
    wrap(page, pymupdf.Rect(40, 10, NW - 40, 37), title, 13, font="hebo", color=(1, 1, 1))
    if sub:
        wrap(page, pymupdf.Rect(40, 28, NW - 40, 46), sub, 8, color=(0.78, 0.82, 0.95))


def build():
    src = pymupdf.open(SRC)
    out = pymupdf.open()

    # ---------- cover ----------
    p = out.new_page(width=NW, height=PH)
    p.draw_rect(pymupdf.Rect(0, 0, NW, 200), color=None, fill=(0.11, 0.15, 0.42))
    p.insert_textbox(pymupdf.Rect(60, 58, NW - 60, 120),
                     "INTEGRATION OF R PROGRAMMING",
                     fontsize=24, fontname="hebo", color=(1, 1, 1))
    p.insert_textbox(pymupdf.Rect(60, 112, NW - 60, 180),
                     "Annotated with the notes given during the midterm defense",
                     fontsize=13, fontname="helv", color=(0.80, 0.84, 0.96))
    y = 236
    p.insert_textbox(pymupdf.Rect(60, y, NW - 60, y + 90),
                     "This is the original document, unchanged. Every note she gave is placed in "
                     "the right-hand margin, beside the exact row or heading it refers to. "
                     "Nothing in the original text has been edited.",
                     fontsize=10.5, fontname="helv", lineheight=1.45)
    y += 74
    p.insert_textbox(pymupdf.Rect(60, y, NW - 60, y + 20), "WHERE EACH NOTE COMES FROM",
                     fontsize=9, fontname="hebo", color=(0.11, 0.15, 0.42))
    y += 22
    for tag, desc in [
        ("MARKED ON PAGE n", "Her handwriting on the submitted copy. The page number is the "
                             "printed copy's page, not this document's."),
        ("SAID", "Spoken during the defense, quoted from the transcript."),
        ("NOTES", "Corroborated by the bulleted notes taken in the room."),
    ]:
        wrap(p, pymupdf.Rect(60, y - 2, 200, y + 30), tag, 8.5, font="hebo")
        wrap(p, pymupdf.Rect(205, y - 2, NW - 60, y + 34), desc, 8.5)
        y += 30
    y += 16
    p.insert_textbox(pymupdf.Rect(60, y, NW - 60, y + 20), "COLOUR OF EACH NOTE",
                     fontsize=9, fontname="hebo", color=(0.11, 0.15, 0.42))
    y += 22
    for k in ["rename", "split", "system", "paper", "side", "demo", "ok"]:
        col, lab = KIND[k]
        p.draw_rect(pymupdf.Rect(60, y + 1, 70, y + 10), color=None, fill=col)
        wrap(p, pymupdf.Rect(78, y - 2, 330, y + 16), lab, 8.5, font="hebo", color=col)
        y += 17
    y += 14
    p.draw_line(pymupdf.Point(60, y), pymupdf.Point(NW - 60, y), color=(0.75, 0.75, 0.8))
    y += 14
    p.insert_textbox(pymupdf.Rect(60, y, NW - 60, y + 60),
                     "Only one item in the whole document was approved without change: "
                     "Form of Organization. Everything else carries a note.",
                     fontsize=9.5, fontname="hebo", color=(0.05, 0.48, 0.28), lineheight=1.4)

    # ---------- cross-cutting page ----------
    p = out.new_page(width=NW, height=PH)
    header(p, "The rules she applied to everything",
           "Stated as general requirements, not tied to any one feature. She said she will check "
           "these specifically next time.")
    y = 72
    for i, (t, b) in enumerate(CROSS, 1):
        p.draw_rect(pymupdf.Rect(46, y, 62, y + 16), color=None, fill=(0.11, 0.15, 0.42))
        wrap(p, pymupdf.Rect(46, y + 1, 62, y + 18), str(i), 9, font="hebo",
             color=(1, 1, 1), align=1)
        wrap(p, pymupdf.Rect(72, y - 1, NW - 46, y + 20), t, 10.5, font="hebo",
             color=(0.11, 0.15, 0.42))
        h = measure(b, 9.2, NW - 118)
        wrap(p, pymupdf.Rect(72, y + 19, NW - 46, y + 24 + h), b, 9.2)
        y += 26 + h + 12

    # ---------- annotated original ----------
    by_page = {}
    for n in NOTES:
        by_page.setdefault(n[0], []).append(n)

    unresolved = []
    counter = 0

    for i in range(src.page_count):
        p = out.new_page(width=NW, height=PH)
        p.show_pdf_page(pymupdf.Rect(0, 0, PW, PH), src, i)
        p.draw_line(pymupdf.Point(PW + 4, 0), pymupdf.Point(PW + 4, PH),
                    color=(0.80, 0.82, 0.88), width=0.8)

        notes = by_page.get(i, [])
        # place notes in reading order down the page
        def _y(n):
            r = p.search_for(n[1])
            return r[n[2]].y0 if r and n[2] < len(r) else 1e6
        notes = sorted(notes, key=lambda n: (_y(n), NOTES.index(n)))
        if not notes:
            p.insert_textbox(pymupdf.Rect(COL_X, 40, NW - 12, 60), "no notes on this page",
                             fontsize=7.5, fontname="helv", color=(0.65, 0.66, 0.72))
            continue

        cursor = 34.0
        for (_, anchor, occ, kind, head, body, srctag) in notes:
            counter += 1
            col, lab = KIND[kind]

            rects = p.search_for(anchor)
            ay = None
            if rects and occ < len(rects):
                r = rects[occ]
                ay = r.y0
                # highlight the anchor on the original
                p.draw_rect(pymupdf.Rect(r.x0 - 1.5, r.y0 - 1, r.x1 + 1.5, r.y1 + 1),
                            color=col, width=0.9, fill=None)
                # numbered badge in the left margin
                bx = max(8.0, r.x0 - 22)
                p.draw_circle(pymupdf.Point(bx, r.y0 + 4), 7.2, color=None, fill=col)
                wrap(p, pymupdf.Rect(bx - 7.2, r.y0 - 1.5, bx + 7.2, r.y0 + 10.5),
                     str(counter), 7, font="hebo", color=(1, 1, 1), align=1)
                # leader line to the margin
                p.draw_line(pymupdf.Point(r.x1 + 3, r.y0 + 4), pymupdf.Point(PW + 4, r.y0 + 4),
                            color=col, width=0.5, dashes="[1 2] 0")
            else:
                unresolved.append((i, anchor))

            top = max(cursor, (ay - 6) if ay is not None else cursor)
            bw = COLW - 16

            hh = measure(head, 9.0, bw - 40, "hebo")
            bh = measure(body, 7.9, bw - 18)
            box_h = 20 + hh + 6 + bh + 16

            if top + box_h > PH - 24:
                top = max(24.0, PH - 24 - box_h)

            box = pymupdf.Rect(COL_X, top, COL_X + bw, top + box_h)
            p.draw_rect(box, color=(0.88, 0.89, 0.93), fill=(0.985, 0.985, 0.99),
                        width=0.6, radius=0.04)
            p.draw_rect(pymupdf.Rect(box.x0, box.y0, box.x0 + 3.2, box.y1),
                        color=None, fill=col)

            p.draw_circle(pymupdf.Point(box.x0 + 16, box.y0 + 11), 7.0, color=None, fill=col)
            wrap(p, pymupdf.Rect(box.x0 + 8, box.y0 + 5, box.x0 + 24, box.y0 + 19),
                 str(counter), 7, font="hebo", color=(1, 1, 1), align=1)
            wrap(p, pymupdf.Rect(box.x0 + 27, box.y0 + 3, box.x1 - 6, box.y0 + 18),
                 lab, 6.2, font="hebo", color=col)
            wrap(p, pymupdf.Rect(box.x0 + 10, box.y0 + 19, box.x1 - 8, box.y0 + 21 + hh + 6),
                 head, 9.0, font="hebo", color=(0.12, 0.12, 0.18))
            wrap(p, pymupdf.Rect(box.x0 + 10, box.y0 + 21 + hh + 4, box.x1 - 8, box.y1 - 13),
                 body, 7.9, font="helv", color=(0.18, 0.18, 0.24))
            wrap(p, pymupdf.Rect(box.x0 + 10, box.y1 - 13, box.x1 - 6, box.y1 - 1),
                 srctag, 6.0, font="hebo", color=(0.52, 0.53, 0.60))

            cursor = box.y1 + 8

    # ---------- closing page ----------
    p = out.new_page(width=NW, height=PH)
    header(p, "What still has to be asked",
           "Three things could not be settled from the paper, the recording, or the notes.")
    y = 74
    for i, (t, b) in enumerate([
        ("The exact corrected title on Feature 1's processing-time row",
         "She inserted parentheses around (RA 11032), struck \"by\", and wrote a word that reads "
         "\"for\" - but it overlaps the printed word \"Tier\", so she may have meant to delete "
         "that as well. The written notes only confirm the parentheses. Ask her to restate it."),
        ("The office's own term for a period that missed its standard",
         "She wrote \"Government Terms\" beside Flagged Weeks. Someone has to ask the office what "
         "they actually call it - and whether they report by week at all, since she doubted that "
         "too: \"Baka naman hindi weeks ang tao.\""),
        ("Whether processing-time monitoring appears in any report the admin submits",
         "This was her test for whether Feature 6 survives at all: \"Is it included in the "
         "report?\" If the answer is no, the feature needs a different justification or it "
         "should be cut."),
    ], 1):
        p.draw_rect(pymupdf.Rect(46, y, 62, y + 16), color=None, fill=(0.78, 0.10, 0.10))
        wrap(p, pymupdf.Rect(46, y + 1, 62, y + 18), str(i), 9, font="hebo",
             color=(1, 1, 1), align=1)
        wrap(p, pymupdf.Rect(72, y - 1, NW - 46, y + 32), t, 10.5, font="hebo",
             color=(0.11, 0.15, 0.42))
        hh = measure(t, 10.5, NW - 118, "hebo")
        h = measure(b, 9.2, NW - 118)
        wrap(p, pymupdf.Rect(72, y + hh + 6, NW - 46, y + hh + 12 + h), b, 9.2)
        y += hh + h + 30

    y += 10
    p.draw_line(pymupdf.Point(46, y), pymupdf.Point(NW - 46, y), color=(0.75, 0.75, 0.8))
    y += 18
    p.insert_textbox(pymupdf.Rect(46, y, NW - 46, y + 60),
                     f"{counter} notes placed across the document. "
                     "Sources: the marked-up copy (19 photographed pages), the defense recording, "
                     "and the notes taken in the room. Where the three disagreed, the handwriting "
                     "on the page was treated as final.",
                     fontsize=9.2, fontname="helv", color=(0.35, 0.36, 0.42), lineheight=1.4)

    out.set_metadata({"title": "Integration of R Programming - annotated with the professor's notes",
                      "author": "BSIT 3-3 Group 12"})
    out.save(OUT, garbage=4, deflate=True)
    print("wrote:", OUT)
    print("notes placed:", counter)
    if unresolved:
        print("UNRESOLVED ANCHORS:")
        for u in unresolved:
            print("   page", u[0], "->", repr(u[1]))
    else:
        print("all anchors resolved")


build()
