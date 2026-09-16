# System Testing Checklist

Client-reported items from testing. Owner names stripped; the work is tracked by
item, not by person.

Status vocabulary: **open** · **done** · **superseded** (the rule changed) ·
**blocked** (needs a decision from the client or the lead).

---

## Business owner — Register

1. Integrate email verification upon creation of account. — **open**

## Business owner — Login

1. Put a CAPTCHA before signing in. — **open**
2. Remove the "use the other sign-in page" banner. — **done**
3. There should be no hint on the business owner side that an admin must not log
   in there. — **open**

## Business owner — Apply for permit (zoning)

1. The zoning border is not strictly Malabon — pins land in Caloocan and
   Valenzuela, and the border is drawn as a large square. — **done**
2. Add a satellite view on the zoning map. — **done**
3. The zoning pin must match the barangay selected; mismatches are not allowed.
   — **done**
4. The map should be locked until a line of business is selected. — **done**
5. The applications for the other permits are missing. Return them. — **done**
6. Lines of business should be categorised so users do not scroll too much.
   — **done**
7. Automatic pinning on entering house number and street address, with the pin
   still manually adjustable (as ride-hailing apps work). — **done**
8. Allow pinning only after a barangay is selected; changing the barangay clears
   the pin. — **superseded** — the client later ruled the pin may be dropped in
   any order, and is cleared only when it contradicts the barangay named.
9. The confirmation message after zoning should be a different colour and a bit
   bigger so it is not overlooked. — **done**
10. Two entries for contact information, landline and mobile, each formatted
    (landline grouped from its area code; mobile as +63). — **done**
11. Display "documents uploaded will be verified against the original" in the
    upload section. — **done**
12. Put decimal places in capitalisation. — **done**
13. State "number of" before each type of employee asked for. — **done**
14. An editable preview of the WHOLE form in the last section, and a modal on
    Submit confirming the applicant has finished reviewing. — **open**
15. All date formats must state the whole month name (September, not Sep).
    — **done**
16. Improve the interface — fields such as TIN should not take so much space;
    proper formatting and spacing throughout. — **open**
17. Allow multiple files per documentary requirement. — **done**
18. Check the line-of-business categories for accuracy. — **open**
19. Check the zoning rules for accuracy. — **open**
20. Detect the conforming / non-conforming message live, rather than only after
    Next is clicked. — **open**
21. Implement validation rules for DTI / SEC / CDA. — **open**
22. Put an asterisk on every required field. — **open**
23. Number the fields so the applicant can tell which one the "still needed"
    list is pointing at. — **open**
24. The view-application form for the other permits is missing. Return it.
    — **open**
25. Submissions for other permits are not working. — **open**
26. Validation in DTI / SEC / CDA. — **open** (duplicate of 21)

## Business owner — Renew permit

1. Allow multiple permit renewal selections, creating a section per permit
   selected. — **done**
2. On renewing other permits, state that payment for them will appear on the
   renewal payment of the business permit. — **open**

## Business owner — Track applications

1. The notification icon needs an indicator when there are new ones. — **done**
2. There should be no "Not Yet Submitted" while the new application has not
   reached initial approval. — **open**
3. "Not billed yet" should read "For Initial Approval". — **open**

## Business owner — Manage drafts

No items recorded. — **blocked** (needs a stated requirement)

## Business owner — Chatbot

No items recorded. — **blocked** (needs a stated requirement)

## Business owner — Communicate online

1. Messages between offices work; testing still needed. — **done**
2. Fix the layout / UI. — **open**

## Business owner — Profile / approved permits

1. A dedicated page for approved permits, for visibility and accessibility.
   — **open**

## Business owner — Edit settings

1. Profile photo upload and required-field signposting. — **done**
2. Should the profile carry additional information, such as home address?
   — **blocked** (question, not a defect)

## Business owner — Apply for amendment

No items recorded. — **blocked** (needs a stated requirement)

## Business owner — View other requirements

1. The Other Requirements icon on Home should carry a count, like notifications,
   which does not reduce until the requirement is submitted. — **open**

## Business owner — View payment history

1. The receipt must be viewable. — **done**

---

## Office admin — Login

1. Allow multiple accounts per office. Exactly one super admin, and only that
   account may create office admin accounts. — **done**
2. Distinguish admin accounts by office (e.g. `email@bfp`). — **open**

## Office admin — Communicate online

1. Fix the layout / UI. — **open**

## Office admin — Manage applications

1. The OLDEST application should appear at the top, not the latest. — **open**
2. Remove "Paid" from the right side of Application Verification — every filing
   there is already paid. — **open**
3. Correct RBAC: each office sees only its own application form. City Health
   must not see Fire Safety fields, and so on. — **done**
4. Correct scheduling for inspections. — **done**
5. Allow the admin to edit the zoning maps database, so the system can be
   maintained as the ordinance changes. — **open**
6. An initial application that has been approved and paid still shows as For
   Approval. — **open**
7. The initial-approval view already shows answers for the other offices' forms.
   Remove them. — **open**
8. After BPLO's initial approval, while the applicant is applying for other
   permits, the application disappears from the admin's view. — **open**
9. Add filtering for application / renewal / amendment. — **open**
10. Clicking a notification logs the user out. — **open**
11. The whole initial-approval form should stay visible to BPLO; hide it only
    from the other five offices. — **open**
12. A permit uploaded by the applicant does not appear on the office admin side.
    — **open**

## Office admin — Manage renewals

No items recorded. — **blocked** (needs a stated requirement)

## Office admin — Manage approved permits

1. An analytics dashboard covering ALL offices. — **open**
2. A page listing ALL approved permits as a table, with only the necessary
   columns, allowing the admin to view a permit, revoke it, and the other
   actions in the use case diagram. — **open**
3. GIS mapping showing all businesses and whether their permit is still active.
   — **open**

## Office admin — Manage amendments

No items recorded. — **blocked** (needs a stated requirement)

## Office admin — Create other requirements

No items recorded. — **blocked** (needs a stated requirement)

---

## Super admin — Login

1. A separate login for the super admin. — **open**

## Super admin — Manage officer-in-charge

1. Officer assignment is not working properly. Add an "Assign to Me" button and
   a "My Assigned" section or filter. — **open**
2. Reassign must allow moving a single permit held by an office admin, rather
   than requiring all of that officer's permits to move together. — **done**

## Super admin — Manage business owner status

No items recorded. — **blocked** (needs a stated requirement)

---

## Other

1. Responsiveness. — **open**
2. Cleaning: remove unnecessary or inaccessible pages. — **open**
