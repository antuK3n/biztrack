# 2. Renewal: naming the permit being renewed

## What changed, and why

A renewal is a filing that says "renew this". Until this commit, seven of them
in the register did not say *which*.

Three things were done about that. The identification system was settled and
written down. The renewal filing was made to name its permit, with an explicit
way out for the applicants who cannot. And the permit chain — which permit
succeeded which — was made a stored fact instead of something reconstructed from
dates.

A defect in the business chooser was found on the way, and is described at the
end.

## The identification system

The client asked us to settle what identifies what. Three numbers, three jobs:

| Identifier | Format | Answers | Lifetime |
|---|---|---|---|
| Business account no. (`businesses.ban`) | `BP-2026-0001` | which business? | permanent, across years |
| Permit number | `MCB-2026-000001` | which permit is being renewed? | one permit |
| Tracking ID | `BIZ-2026-00473` | where is my filing? | one application, in flight |

All three formats are confirmed in `api/app/Support/Numbering.php` — the
tracking id at line 33 (`BIZ-%d-%05d`), the permit number at line 42
(`%s-%d-%06d`), the account number at line 82 (`BP-%d-%04d`).

The permit prefix is per permit *type*, read from
`permit_types.permit_number_prefix` and passed in at `WorkflowService.php:1118`.
The live set is `BUSINESS → MCB`, `SANITARY → MCS`, `FSIC → MCF`,
`OCCUPANCY → MCO`, `CEC → MCE`, `MARKET → MCM`, `ZONING → MCZ`. So an `MCB`
number is a Mayor's / Business Permit specifically.

**One correction.** "One permit, one type, one year" describes how the system is
used, not a rule it enforces. The only database constraint on a permit number is
that it is unique (`2026_07_24_000041_create_permits_table.php:13`); there is no
composite unique on business, type and year, and the sequence runs globally per
prefix per year rather than per business. Nothing today creates a second live
permit of one type for one business in one year, but nothing stops it either. If
BPLO's rule is genuinely one-per-year, that is a constraint worth adding, and it
would be cheap.

### BAN became BP

The account number used to be formatted `BAN-2026-0001`. BizTrack has a
blacklist feature, so `BAN-` on an owner's own record read as a notice that they
had been banned. Every number was rewritten keeping its sequence
(`2026_08_30_000000_rename_ban_prefix_to_bp.php:44-47`), and the database column
is still called `ban`, so nothing downstream had to change.

**A correction on the count.** The commit message says 718 numbers were
rewritten. The migration uses a raw table update with no soft-delete filter, so
it actually rewrote **779** rows — every business in the register. 718 is the
count of *live* businesses; the other 61 are soft-deleted. The migration is
right to include them: `Numbering::ban()` uses `withTrashed()` when it works out
the next sequence number, so leaving the deleted rows on the old prefix would
have corrupted the sequence.

## The seven orphan renewals

At the time the work was done, 749 of 756 renewals in the register carried a
prior permit and 7 did not. Checking against the register today, it has grown to
758 renewals, 751 with a prior permit — and the same 7 without. The orphans are
stable; they were not a continuing leak, they were a set of historical rows.

Three routes in, one root cause: **a null was accepted as an answer without
anyone ever having to give it.**

| Filing | Status | Permits the business held | How |
|---|---|---|---|
| 1 | approved | 2 | written directly by `DemoSeeder` |
| 7, 3367, 3382, 5008, 5011 | draft | 0 | the question was never asked |
| 3391 | draft | 3 | the question was skipped past |

The five are the interesting case. The picker's escape hatch was the *absence*
of the question — the old wizard read, at `ApplyWizard.tsx:1397`:

```ts
: applicationType === 'renewal' && permits.length > 0 && permitId === null
```

and at line 1478, where a business held no permits, it rendered a static
paragraph and no control at all. The comment above it was honest about why
(line 1385): *"`permits.length > 0` is the escape the client's year-one
applicants live on."* The intention was right. The implementation made the
escape unrecorded.

Because afterwards, a null nobody was shown and a null somebody chose are the
same row. The register could not tell "my permit was issued on paper" from
"nobody asked me", and those need different handling: the first is a normal
year-one renewal, the second is a filing that should never have been accepted.

## The fix: an answer you give

`applications.prior_permit_declared_none` — a boolean, default false,
`2026_08_30_000020_make_the_renewal_chain_a_fact.php:81-90`.

The escape is now a radio option in the same group as the permits
(`ApplyWizard.tsx:1601-1631`), rendered as the last child of the same list. Its
label depends on whether there is anything above it:

- with permits listed: *"None of these — my permit was issued on paper"*
- with no permits listed: *"This business has no permit issued through BizTrack"*

and either way a sub-label: *"Upload your paper permit under Documentary
Requirements and we will carry on from there."*

Submit refuses a renewal or an amendment that carries neither
(`ApplicationController.php:320-330`):

```php
in_array($application->application_type, [ApplicationType::Renewal, ApplicationType::Amendment], true)
    && $application->prior_permit_id === null
    && ! $application->prior_permit_declared_none
```

> Say which permit you are renewing — pick it from your permits, or tell us this
> business has no permit issued through BizTrack.

(`renewing` becomes `amending` for an amendment; that is the only difference.)

Both writers resolve the contradiction the same way — if a permit is named, the
"none" flag is cleared (`ApplicationController.php:190-191`,
`PriorPermitController.php:76-82`). A permit picked is a stronger answer than a
box ticked.

The permit itself is never typed. It is chosen from the business's own permits,
delivered by the prefill endpoint, and cross-business ids are stripped when the
applicant switches business (`ApplyWizard.tsx:1371-1375`). The server checks the
same thing twice over, on create and on update, and refuses with *"The selected
prior permit does not belong to this business."*

> **The escape must stay.** In year one, most renewals are renewals of permits
> the old counter process issued on paper. Removing the escape would trap
> exactly the people the system exists to serve. If BPLO would rather a business
> with no permit in the register filed as **new** instead, that is a smaller
> change than this one, not a larger — and it is logged as question A20b.

## The permit chain is now a stored fact

`permits.prior_permit_id`: nullable, indexed, and deliberately **without** a
foreign key (`2026_08_30_000020...:76-77`). The reason is in the migration:
`permits` is self-referencing, and SQLite rebuilds the entire table to add a
self-referencing constraint. On a register with real tester data in it that is a
risk taken for very little — the column is written by one code path and
backfilled by one statement, both of which check the target themselves.

The backfill is a correlated update with a same-business guard in the subquery
and a `where prior_permit_id is null` so it can be re-run safely. Its figures
were measured before the run and recorded in the migration's own docblock; we
checked all five against the register and they are exact:

| | |
|---|---|
| permits total | 5,942 |
| chained | 2,722 |
| null (the `new` filings) | 3,220 |
| links crossing to another business | 0 |
| permits linked to themselves | 0 |

**It is written by a `creating` hook on the model** (`Permit.php:51-75`), not by
the workflow service. The hook reads the application's `prior_permit_id`, bails
out if the column is already set or there is no application, and writes only
when the prior permit belongs to the same business. `approveAndIssue` does not
touch the column at all — the hook is the only writer.

That placement is the point. A chain maintained at one call site is exactly the
half-kept invariant that produced the seven orphans in the first place: correct
wherever somebody remembered, silently absent everywhere else.

**A correction.** The reasoning was given to us as "`Permit::create()` has three
callers". There are ten call sites; three are outside the test suite
(`WorkflowService.php:1117`, and `DemoSeeder.php:115` and `:178`). The argument
holds — the seeder mints permits directly and would have bypassed a
service-level write — but "three callers" means three non-test callers.

A second, smaller inaccuracy is in the code itself: the docblock at
`Permit.php:41-42` says "DemoSeeder and AnalyticsHistorySeeder both mint permits
directly". `AnalyticsHistorySeeder` does not; it goes through
`approveAndIssue()`. The conclusion is unaffected, but the comment names the
wrong file.

### Why this matters beyond tidiness

`RenewalOutcomes` decides whether a renewal was late. It does that by
reconstructing the chain, and — this is worth being precise about — **it still
does**. `RenewalOutcomes::cycles()` (`api/app/Support/RenewalOutcomes.php:313-378`)
groups permits by `business_id : permit_type_id`, sorts by `valid_from` with the
id as a tie-break, and treats consecutive pairs as a renewal, late if the gap
exceeds `LATE_GRACE_DAYS` (which is 1).

That inference is a coin toss exactly where it matters most: on a business
holding two permits of one type, where the sort order between them is decided by
dates that may be identical. And the late/on-time label it produces is what
trains the fitted renewal model used on the analytics screens.

So the honest statement is: **the column is written, and not yet read by the
analytics it was added for.** The chain is now a fact, but `RenewalOutcomes` has
not been switched over to use it. That is the follow-up piece of work, and it is
now a small one — the data is there and verified.

## A real defect in the business chooser

Found while testing the picker, and worth reporting in full because the first
fix we tried was wrong in an instructive way.

**The defect.** The business chooser fetches one page of `PICKER_PAGE_SIZE`
businesses — 200 (`web/src/lib/resources.ts:128`) — ordered newest-first. An
owner past 200 businesses lost the oldest off the end. The oldest are precisely
the ones whose permits are old enough to need renewing, so the chooser could
render 200 businesses that cannot be renewed and drop the one that can.

**The wrong fix, and why it was wrong.** The obvious repair is to filter the
list to businesses that hold a permit. It was tried, and it breaks the rule
established one section above: *a business whose permits are on paper is not
trapped, but must say so.* Filtering would remove from the chooser exactly the
applicants the paper escape exists for — a business with a paper permit holds no
permit in BizTrack, so it would vanish from its own owner's list. The wrong fix
is documented in the code that replaced it, at `BusinessController.php:62-87`.

**The shipped fix is ordering, not exclusion** — `BusinessController.php:88-91`:

```php
->withCount('permits')
->orderByRaw('CASE WHEN permits_count > 0 THEN 0 ELSE 1 END')
->orderByDesc('created_at')
->orderByDesc('id')
```

Permit holders sort first; businesses with paper permits stay reachable behind
them. Nobody is excluded, and the row that matters is on the first page.

**Two things this does not do, which we should say rather than let someone
discover.**

- **The 200 cap is still there.** Ordering makes it survivable, not gone. An
  owner with more than 200 permit-holding businesses would still lose the tail.
  The server supports paging — `page` is validated, `per_page` is clamped to
  200, `meta.last_page` is returned — and the client never asks for page two. A
  paging helper, `businesses.page()`, exists in `resources.ts:224` and has zero
  callers. Nobody in the register is near that number today.
- **No test covers the ordering.** There is no assertion anywhere in the PHP or
  browser suites that permit-holders sort first. This fix is currently protected
  by nothing, and it is the kind of clause a later refactor of the list endpoint
  would drop without noticing.

**A correction on the figure.** We were told one demo owner holds 239 businesses
of which 5 have a permit, and that number is also in the code comment at
`BusinessController.php:69-71`. It is not reproducible in either database. In
`database.sqlite` the largest owner holds 35 businesses with 1 permit; in
`e2e.sqlite` the largest holds 261 with 6. The shape of the defect is real and
lives in the end-to-end register — which grows on every run, which is why the
exact pair drifted. The code comment should carry the shape, not a number that
was true for an afternoon.
