# System Testing Checklist — status

Against `SYSTEM TESTING CHECKLIST.pdf` (25 items). Every item below was
checked in a real browser against the running app, not only by test suite
or type-checker. Where an item was already fixed in an earlier round, that
is stated plainly rather than re-claimed as new work.

Backend: 113 Pest tests, 394 assertions, all passing. Frontend: `tsc
--noEmit` clean.

| # | Item | Status |
|---|------|--------|
| 1 | Edit profile not working | Already fixed. Verified: changed a name, confirmed it persisted to the database. |
| 2 | Form does not match the real application form | **Blocked on the LGU.** See "Open questions" below. |
| 3 | Line of business has fixed choices | Fixed. 15 PSIC codes to 135, plus an "Other (not listed)" escape hatch that takes free text. |
| 4 | GUI issue in zoning | Already fixed (the overlapping conformance banner is gone). Additionally retitled the modal, see below. |
| 5 | Mayor's permit must not be here; should be zoning clearance | Fixed. The business permit is now implicit; a Zoning / Locational Clearance card (CPDO) takes its place. |
| 6 | No application form presented once clicked | Already fixed. Verified: the full step map shows upfront and an office form slots in when its certificate is picked. |
| 7 | Chatbot does not work properly | Fixed. It now answers about the permit you named instead of dumping all of them. |
| 8 | Sorting/filter in payment history | Already fixed. Verified: "Oldest first" reorders the list. |
| 9 | Birthday needs validation, none in the future | Already fixed, on both the client and the API. Kept under regression test. |
| 10 | Verify the attached image is part of the user side | Fixed. Same finding as item 23, see there. |
| 11 | Application dates should be auto-filled | Fixed. Derived from `submitted_at`, shown read-only. |
| 12 | Other fields are optional; should be required | Fixed. Registration number, TIN and registration type are required in both the wizard and the API. Trade name stays optional, since many sole proprietors have none. |
| 13 | Other Requirements should allow multiple responses | Fixed. Replies append to a thread, visible to both sides. |
| 14 | Drafts are not saved | Already fixed. Verified: saved, reopened, every field restored. |
| 15 | No validation for TIN | Fixed. 9 digits plus optional branch code; separators normalised, so `123 456 789 000` stores as `123-456-789-000`. |
| 16 | Date issued should be auto-generated on the admin side | Fixed. Issuance dates moved off the applicant form onto the officer review screen. |
| 17 | Next button should not be clickable until required fields are entered | Already fixed. Verified: Next stays disabled and names what is missing. |
| 18 | Revenue code sections must not be mentioned | Fixed. Hidden in the UI already; now also stripped from the API payload for anyone without `application.review`. Still to be confirmed with the LGU. |
| 19 | Other Requirements page is locked on the admin side | Already fixed. Verified: the page loads for BPLO staff. |
| 20 | Application form did not reflect on the admin side | Already fixed. Verified against a submitted application: office form answers and fee declaration both render for the officer. |
| 21 | Add security related to sessions | Fixed. See "Session security" below. |
| 22 | Fix the notification description on the admin side | Fixed. The empty state was applicant copy shown to officers. |
| 23 | Application asks what type of form (renewal, new) | Fixed. Derived from the application record and shown read-only. A client-supplied value is not trusted. |
| 24 | Admin/super admin login should be a different page/session | Fixed. `/login` and `/staff/login`, enforced server-side. |
| 25 | Download Receipt does not work | Already fixed. Verified: downloads a valid PDF with the correct reference, business and amount. |

## Bugs found while testing that were not on the checklist

These surfaced from walking the flows rather than from the list. Each is
the kind that passes a type-check and fails in front of a user.

1. **A new application demanded a "Previous Mayor's Permit."** The
   requirement is correctly marked renewal-only in the database and the
   API sends that context, but the wizard ignored it. A new business was
   blocked by a document that cannot exist.
2. **Every file attachment from the Respond form was rejected.** The form
   has no document-type picker, so it never sent one, while the API
   required it alongside the file.
3. **Unauthenticated API requests returned a 500, not a 401.** Laravel was
   redirecting guests to a `login` route this API-only app does not have.
   A tester pasting a URL into the address bar got a server error.
4. **Renewal prefill blanked a free-text line of business,** so renewing a
   business whose trade is "Other" silently lost the text and blocked a
   step the applicant had completed the previous year.
5. **Office forms validated `form_data` as required,** which rejects an
   empty object. With the FSIC sheet now fully derived, the wizard would
   have failed on Next with nothing to send.
6. **Recording issuance dates was unreachable for officers.** The route
   required `application.create`, which officers do not hold.
7. **The chatbot quoted fees from a superseded column.** It answered "₱700
   for FSIC" from the legacy flat schedule; under the ordinance FSIC is
   10% of the mayor's permit plus regulatory fees. It no longer quotes
   peso figures it cannot source from an active fee rule.
8. **The zoning modal said "CONGRATULATIONS!" in green** for what is only a
   recorded map pin. The system does not evaluate zoning; CPDO rules on
   conformance during processing. Announcing a result we have not
   determined is how an applicant comes to believe their zoning passed.
   Retitled "Location recorded".
9. **The chat button covered the panel's own send button.**

## Session security (item 21)

The tester marked this optional. What is in place:

- Separate sign-in doors for citizens and staff, enforced on the server.
  A staff credential is refused at the public sign-in and vice versa. The
  refusal only happens after the password has already verified, so it
  cannot be used to discover which accounts are staff.
- Response hardening on every reply: `X-Frame-Options`, `nosniff`,
  `Referrer-Policy`, a restrictive CSP, `Permissions-Policy`, and HSTS
  over TLS. The bearer token lives in `localStorage`, so cross-site
  scripting and framing are the realistic hijacking routes; these close
  both. Set in the application rather than the proxy so they also hold in
  development and behind a tunnel.
- Officer and admin routes are permission-guarded in the router, not only
  by the API's 403.
- Carried over from earlier: 12-hour token expiry, revocation on password
  change, per-account lockout after 5 failed attempts, and a 10 per minute
  login rate limit.

Deliberately **not** done: binding a session to an IP or user agent. It
would break testers who move between mobile networks, for little gain
against the actual threat.

## Open questions for the LGU

1. **Item 2, the real application form.** This one needs a scan or photo
   of the paper form to close. Comparing what the wizard collects against
   the standard DILG/DTI unified form, the likely gaps are: lessor details
   (name, address, monthly rental) when the premises are rented; an
   emergency contact; mode of payment (annual, semi-annual, quarterly);
   the number of employees residing in Malabon; and any tax incentives
   enjoyed. Worth confirming before building to a guess.
2. **Item 18.** Citations are now hidden from applicants. Confirm the LGU
   wants them hidden rather than shown, since the officer view keeps them
   and the switch is one line either way.
3. **Zoning clearance fee.** The new zoning permit type carries a
   placeholder base fee. The revenue code extract has no CPDO schedule, so
   this needs the real figure.
4. Fee amounts generally remain subject to the questions raised in
   `BizTrack-Fee-Engine.pdf` (Q1 to Q10).
