# Checklist round 2 — items 26 to 60

Source: `SYSTEM TESTING CHECKLIST.pdf` (16 pages), two batches dated 28 July 2026
("1st checking" = 26 to 45, "2nd checking" = 46 to 60). 35 new items on top of
the original 25.

## A. Auth and account

| # | Item | Read |
|---|---|---|
| 26 | Validation description too vague | Contact Number error says "Enter a Philippine mobile number, like 09171234567." Wants the message to say what is actually wrong. |
| 27 | View-password toggle disappears on blur | The eye icon vanishes once focus leaves the field. |
| 40 | Profile page not accessible | Avatar menu has Settings / Profile / Log Out; Profile routes to Settings. Needs its own page. |
| 42 | Message box persists after "Go there now" | The wrong-portal alert stays on screen after following the link to the other sign-in page. Regression from the portal split. |

## B. Apply wizard

| # | Item | Read |
|---|---|---|
| 30 | Remove step-map description | The "This is the complete list of sections..." paragraph. |
| 31 | Remove Mayor's Permit panel | The "You are applying for the Mayor's / Business Permit" explainer added for item 5. |
| 32 | Drafts must autosave | Remove the Save draft button and its hint entirely. |
| 33 | Zoning is misplaced, should be first | Needs confirmation: first overall, or first after permits? |
| 34 | Flow order incorrect | Related to 33. Current: permits, business, lines, zoning, office forms, documents, tax, review. |
| 35 | Capital field | Automatic thousands separators, and make it required. |
| 36 | Allow renaming drafts | The draft title is currently the business name, not editable. |
| 38 | Zoning validation incomplete | Only Barangay and House No./Street are required; other fields need validation too. |
| 39 | Validation on tax profile inputs | Photo shows the Business & Tax Profile step. |
| 47 | Remove button on attached files | Uploaded requirements can be replaced but not removed. |
| 59 | Upload an existing permit | Rather than applying afresh for a permit the applicant already holds. |

## C. Chatbot

| # | Item | Read |
|---|---|---|
| 37 | Not properly functioning | Asked "in the sanitary permit application, what is the water source for?" and got the generic per-permit menu. Cannot answer questions about form fields. |
| 41 | Does not persist messages | Confirmed independently: `chatbot_conversations` held one row for one user; the thread was client-side only. |

## D. Messaging

| # | Item | Read |
|---|---|---|
| 43 | No sender/receiver distinction | Both sides render identically on applicant and officer views. |
| 45 | Enter should send | Officer side requires clicking Send. |
| 49 | No dedicated messaging page | Note says "Follow the revised GUI" — needs the GUI reference. |

## E. Officer and admin

| # | Item | Read |
|---|---|---|
| 46 | "Checked" applications have bugs | Unspecified. Needs reproduction. |
| 51 | Accepted applications not notified | Approval sends no notification to the applicant. |
| 53 | Planning/Zoning admin account missing | CPDO has a department and a permit type but no seeded user. |
| 54 | No view/edit mode in admin review | Officer cannot switch between reading and editing the form. |
| 55 | Document view/download shows JSON | Opens a JSON response in a new tab instead of the file. |
| 56 | Office scoping of applications | Wording ambiguous: almost certainly offices should NOT see applications outside their own queue. Currently every reviewer holds `application.view_all`. |
| 57 | Choose recipient in Other Requirements | Officer cannot pick which office/person the request goes to. |
| 60 | Coding error in BPLO admin | "No query results for model [ApplicationAssignment] 12". The queue links with an application id where an assignment id is expected. |

## F. Receipts and documents

| # | Item | Read |
|---|---|---|
| 48 | Downloaded receipt is malformed | Renders as near-blank with stray marks. `pdfinfo` reports 0 pages and the font streams error. Previously dismissed as a tooling quirk; it is a real defect. |
| 58 | Receipt line items too verbose | Remove the parenthetical detail on each line. |

## G. Renewal and amendment

| # | Item | Read |
|---|---|---|
| 44 | Approved applications should move to the profile | They stay in the active list. |
| 50 | Choose which permit to renew | Renewal should offer the specific permit, as the official site does. |
| 52 | Amendment form is incorrect | No image, no detail. Needs clarification. |

## H. Questions, not code

| # | Item | Answer to give |
|---|---|---|
| 28 | Integrate email/SMS? | Marked "subject to validation". Currently simulated: `sms.log` and the log mail driver. Real delivery needs a provider and budget. |
| 29 | Does email consent matter if not given? | Explain what breaks without it. |

## Blocking ambiguities to confirm

1. **33/34** — where exactly does zoning belong in the order?
2. **56** — should offices be restricted to their own applications?
3. **49** — where is the revised messaging GUI?
4. **52** — what is wrong with the amendment form?
5. **46** — what breaks on a checked application?
