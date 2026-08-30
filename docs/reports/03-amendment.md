# 3. Amendment

## What changed, and why

An amendment is a filing that alters an existing permit — a change of ownership,
of location, or of the nature of the business. BizTrack could already record
*what* was being amended. It could not record *which permit* was being amended.

That is the whole of the change here. An amendment now names its permit, the
same way a renewal does, and the server refuses to accept one that names
neither a permit nor a reason there is none to name.

Everything else in this section is an audit result rather than a change. We went
looking for the amendment feature expecting to build it, found it already built,
and are reporting what is there so that nobody builds it twice.

## What was already working

Checked line by line, and all of it predates this commit:

| Part | Where |
|---|---|
| Amendment columns writable on the model | `api/app/Models/Application.php:48-49` |
| Booleans cast, so the API returns `true` and not `1` | `api/app/Models/Application.php:77-80` |
| `has_amendments` computed on the server, never accepted from the browser | `api/app/Http/Controllers/Api/ApplicationController.php:582` |
| Validation when the application is created | `ApplicationController.php:161` |
| Validation when a draft is updated | `ApplicationController.php:230` |
| Exposed to the frontend as `amendments` | `api/app/Http/Resources/ApplicationResource.php:34-43` |
| Rendered on the officer's review sheet | `web/src/pages/officer/ReviewPage.tsx:1991-2019` |
| Tests | `api/tests/Feature/AmendmentDetailsTest.php`, 9 cases |

The `has_amendments` point is worth spelling out because it is the kind of thing
that looks like a detail and is not. The flag is derived at
`ApplicationController.php:582` from the four answers:

```php
'has_amendments' => $ownership || $location || $nature || $other !== '',
```

It is deliberately absent from the validation rules, so a browser cannot send
it. A client that could set the flag itself could file an amendment that claimed
to amend nothing, or claimed to amend something it had not filled in.

The test count did not change in this commit. The diff shows ten changed lines
in `AmendmentDetailsTest.php`, but that is one existing test edited, not a test
added — `it('submits once something is actually being amended')` at line 114 now
carries a prior permit, because without one that filing no longer submits at all.

## The paper form's "Amendment from:" block

`AmendmentState` in `web/src/pages/applicant/ApplyWizard.tsx:368-374` models the
block as three tick boxes and one text field. `AMENDMENT_KINDS` at lines 388-392
holds **three** entries, not four:

```ts
{ key: 'ownership', label: 'Ownership' },
{ key: 'location',  label: 'Location' },
{ key: 'nature',    label: 'Nature of Business' },
```

"Others (specify)" is deliberately outside that list, because on the paper form
you cannot tick Others without writing the other in. So typing **is** ticking:
the text field is rendered on its own with no checkbox beside it, and a separate
tick could only ever contradict the text. That rule holds in four independent
places — the wizard's own submit gate (`ApplyWizard.tsx:1389-1390`), the
rendering (`:1678-1689`), the server's derivation
(`ApplicationController.php:579-586`), and the summary line on the model
(`Application.php:98`, which prints `Others: <text>`).

There is no fifth boolean column for "others", and that is on purpose.

## The gap that was real: naming the permit

The permit picker was already shown for amendments. What it was not, was
required. The old gate read:

```ts
applicationType === 'renewal' && permits.length > 0 && permitId === null
```

Two ways past it: you were not a renewal, or your business held no permits. An
amendment could therefore be confirmed and submitted naming nothing at all.

The rule is now the same for both filing types, on the server, in one place —
`ApplicationController.php:320-330`:

```php
throw ValidationException::withMessages([
    'prior_permit_id' => ["Say which permit you are {$verb} — pick it from your permits, or tell us this business has no permit issued through BizTrack."],
]);
```

`$verb` is "renewing" or "amending"; it is the only difference between the two
cases. The escape hatch is the same one described in section 2: a radio option
reading *"None of these — my permit was issued on paper"*, recorded on
`applications.prior_permit_declared_none`, which has to be chosen rather than
fallen through.

## Two gaps reported rather than half-fixed

**There is no application-form PDF anywhere in the system.** We enumerated every
document generator in the codebase. All of them use `barryvdh/laravel-dompdf`
with views under `api/resources/views/pdf/`, and the complete list is:

1. the permit certificate (`PermitController::pdf`)
2. the payment receipt (`PaymentController::receipt`)
3. four analytics reports (dashboard, processing time, business growth, renewal risk)
5. one CSV export

There is exactly one `window.print()` in the whole frontend, at
`web/src/pages/applicant/PermitDetailPage.tsx:289`, and it prints the permit
certificate. There are no `@media print` rules in `web/src` at all.

So the "Amendment from:" block is captured, stored, validated and shown on
screen, and printed nowhere — because *no* application form is printed. That is
an absent feature, not a broken wire, and it is the same absence for a new
application and a renewal. Building it is a separate piece of work, and it needs
the paper form first (see section 4).

**The applicant cannot see their own amendment block.** The officer's review
sheet renders it under the heading "Amendment From". The applicant's
`ApplicationDetailPage.tsx` contains no reference to amendments at all — 797
lines, zero occurrences of the string. This asymmetry predates the commit; we
are naming it rather than fixing it, because where that block should sit on the
applicant's screen is a design question, not a bug fix.

One thing that page does handle well and is worth copying: the officer's sheet
has a fallback for filings created before the amendment question existed —

> This filing predates the amendment question and does not record what is being
> amended. Ask the applicant through Messages before deciding.

which is the right answer to "the data is missing" on a register that has
history in it.

## Open question logged

**A20c. Does an amendment also name the permit it amends?**

*Why it matters.* An amendment alters one permit's record. "Amend my business"
tells the counter no more than "renew my business" does when the shop holds
three permits with three expiry dates. Checklist item 50 asked for the choice on
renewals; we could see no reason it stops there.

*What we assumed meanwhile.* Yes — the same question, the same dialog, the same
submit gate. If the paper amendment form has no permit-number box, this is one
field too many and we take it out. That is a smaller change than the one we
made, not a larger one.
