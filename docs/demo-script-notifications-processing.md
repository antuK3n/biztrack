DEMO RUN SHEET — NOTIFICATIONS & PERMIT PROCESSING TIME
(final: short slide intros, most of the talk on the live site)


STAGE SETUP (morning of, not during)

- Two browser windows, both logged in before the talk:
  Window A: admin@biztrack.local (start on Analytics).
  Window B: owner@biztrack.local (Nena), sitting on her Notifications page.
- The Send Reminder button fires once per day per permit. Do not rehearse on
  Nena's row on defense day, or have it re-armed after the last rehearsal.
- Rehearse the processing-time walkthrough once against the live screen so the
  CHO flags and the steep drop are where the script points.


PART 1 — NOTIFICATIONS

SLIDE

SAY — "This module streamlines communication by notifying owners automatically
whenever their permit hits a milestone: a status change while it is being
processed, or a permit nearing the end of its validity. Let me show you both
sides of that."

SITE

DO — Window A (admin): Analytics, then Renewal Risk.

SAY — "Most notifications send themselves. Every time an application changes
status, the owner is notified automatically, and permits nearing expiry get
reminders at 30, 15, 7, and 1 day before the deadline, each sent once, every
night after midnight. But an officer can also step in directly. This is the
renewal watchlist. Here is a business the system flagged, with the warning
signs listed on its row."

DO — Click Send Reminder on Nena's row.

SAY — "One click. The system composes the message, records it in a ledger so
it can never be sent twice in a day, and delivers it to the owner's account.
Now the other side."

DO — Alt-tab to Window B (Nena). Refresh the Notifications page. Point at the
top entry.

SAY — "Same second, her side. She did not ask for this and she did not need to
visit the municipal hall. The follow-up from the BPLO is in her notifications,
telling her exactly which permit and what to do. Milestones and reminders reach
the owner the moment they matter."

LANDMINES

- "Are these real emails?" — "Every message is composed and recorded; delivery
  is switched to a log for the demo so no real inbox gets test mail. Turning it
  on is a server setting, not a rebuild."
- "Can I change when reminders go out?" — "Deliberately not on any screen.
  Reminder timing is policy, and policy should not be editable by whoever is
  logged in."
- Nena's permit is already expired, so the message reads as the urgent
  follow-up wording. Do not say "before it expires."


PART 2 — PERMIT PROCESSING TIME MONITORING

SLIDE (short)

SAY — "This module applies statistical process control to each office's own
processing history. Every department's weekly average is measured against the
range that office normally produces, so ordinary variation is ignored and only
genuine changes are flagged. Rather than explain it here, let me just show it
running."

SITE

DO — Window A: Analytics, then Processing Time. Land on BPLO.

SAY — "Each point is one week's average review turnaround for this office. The
shaded band is the range this office normally produces, worked out from its own
history. BPLO: every week inside the band. A slow week here is just a slow
week, and the system stays quiet."

DO — Click the CHO card.

SAY — "Same rules, different office. Here the recent weeks walk out of the
band, and that is what a genuine departure looks like. Notice we never compared
CHO to BPLO. Each office is measured against its own normal, so a heavier
workload is not a penalty."

DO — Scroll to the Noted Delays panel.

SAY — "Every flagged week lands here, with its date and how far outside normal
it went. So an administrator is not told the office is slow. They are shown the
exact weeks things changed, and can trace each one back to a staff absence, an
outage, or a surge in filings."

DO — Scroll to the Gradual Slowdown Detector.

SAY — "This second check catches the problem the chart above can miss: small
increases that build up week after week and would never trip a single-week
alarm. It weighs recent weeks more heavily and reads each office as steady,
rising, or speeding up. CHO here reads Rising, about half a day, which is
exactly the kind of slow drift that hides inside normal-looking weeks."

DO — Filter icon, top right. Window, Last 104 weeks. Then click Refresh now.

SAY — "Two years with one click, and recomputed on request. That timestamp
just became now. Normally nobody presses this; the system does it on its own
every night at three."

DO — Point at the steep drop in the recent weeks.

SAY — "One last thing, this steep drop. The register's history is simulated,
but these last weeks are real: while testing, pabalik-balik kami between
accounts, approving permits within minutes instead of days. We never told the
chart. It noticed on its own. Those weeks are flagged as outside the normal
range, labelled faster than usual, and the trend reads Speeding up. The chart
flags the change, the administrator supplies the cause. This time, the cause
was us."

LANDMINES

- "Is CHO breaking the law?" — "Different question. This chart is each office
  against its own normal. Legal compliance with the 3, 7 and 20 working-day
  limits is on the Analytics Dashboard."
- "So your five-minute approvals are in the other charts too?" — "Everything on
  this register mixes simulated history with our real test actions; that is
  what a demo register is. In production the only actions are the office's
  own."
- Word discipline: say "outside its normal range" and "gradual slowdown".
  Never "predict" or "forecast".
