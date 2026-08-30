# 5. Messaging: a conversation now has an addressee

## What changed, and why

Until this commit, a message in BizTrack was addressed to a *filing*. It was not
addressed to anybody.

`message_threads` had no department column at all. The columns were `id`,
`application_id`, `subject`, `status` and the two timestamps — confirmed against
the original create migration
(`api/database/migrations/2026_07_24_000060_create_message_threads_table.php:11-15`),
the later column addition
(`2026_07_24_000073_align_tables_with_manuscript.php:105-108`), and the schema
of an August snapshot database.

That absence matters more than it sounds. "Send this to the correct office" was
not a rule the system was enforcing badly — it was not a concept the system
could express. One filing had one conversation, everybody who could see the
filing wrote into it, and who a given message was *for* was something a reader
had to infer from its contents.

A thread is now `(application, department)`. An owner may write to any office
actually handling their filing, plus BPLO. An officer sees their own office's
conversations and no others. Both rules are enforced on the server, not hidden
in the browser.

## The migration

`api/database/migrations/2026_08_30_000010_scope_a_message_thread_to_an_office.php`

- **Adds the column** (line 77-81):
  `$table->foreignId('department_id')->nullable()->after('application_id')->constrained()->restrictOnDelete();`
  `restrictOnDelete` rather than cascade: deleting a department should fail
  loudly while conversations reference it, not silently take 520 threads with
  it.
- **Backfills to BPLO by code, not by id** (line 90-95):
  `$bplo = DB::table('departments')->where('code', 'BPLO')->value('id');`
  guarded by `if ($bplo !== null)`. A hard-coded `1` would be right on our
  machine and wrong on the city's.
- **Swaps the unique index** (line 98-99): drops
  `message_threads_application_id_unique`, adds
  `message_threads_application_id_department_id_unique`. This is the change that
  makes six conversations on one filing legal; without it the second office to
  be written to would collide with the first.

**A correction to how this was described to us.** The column was made nullable
and then backfilled, on the stated reasoning that a `NOT NULL` column forces
SQLite to rebuild the table, and the register holds real tester data. The first
half of that is true and the invariant is genuinely held above the schema (see
below) — but the table was rebuilt anyway. Adding the foreign key did it.
Comparing the stored schema before and after, the *pre-existing* `application_id`
foreign key text changed from

```
references "applications"("id") on delete cascade
```

to

```
references applications("id") on delete cascade on update no action
```

An in-place `ALTER TABLE ADD COLUMN` cannot rewrite another column's foreign key
clause; that rewrite is Laravel re-deriving the constraints during a
create-copy-drop-rename. No data was lost and the outcome is correct, but
"nullable avoids the rebuild" is not what happened. If we had wanted to avoid
the rebuild we would have had to skip the foreign key too, which is not a trade
worth making.

The invariant is held in the model instead — `api/app/Models/MessageThread.php:37-44`:

```php
protected static function booted(): void
{
    static::creating(function (self $thread) {
        if ($thread->department_id === null) {
            $thread->department_id = Department::where('code', 'BPLO')->value('id');
        }
    });
}
```

So a thread created without a department gets BPLO, whatever created it.

## Row counts

Measured directly against `api/database/database.sqlite` after the migration:

| | count |
|---|---|
| `message_threads` | 520 |
| `messages` | 2,094 |
| `message_attachments` | 0 |
| threads with a null department | 0 |
| threads grouped by department | `BPLO` — 520, and nothing else |

The "before" side of those figures cannot be measured directly — there is no
pre-migration snapshot of that file — so the honest statement is: the migration
contains no `DELETE` and no `INSERT`, and the August 7 snapshot
(`api/database/e2e-final.sqlite`) holds 519 threads and 2,088 messages, which is
consistent with 520 → 520 and 2,094 → 2,094 across three weeks of ordinary use.
The `e2e.sqlite` copy also migrated with zero null departments.

## Two questions, deliberately kept apart

This is the part worth reading twice, because conflating these two questions is
how permission bugs get written.

### May you READ a conversation?

`api/app/Support/ApplicationVisibility.php:166-169`:

```php
public static function readsThreadOf(?User $user, ?int $threadDepartmentId): bool
{
    return self::readsOfficeSheet($user, $threadDepartmentId);
}
```

It delegates rather than deciding. `readsOfficeSheet` (lines 91-106) is the same
predicate that already governs office forms, issued clearances and inspection
findings — four call sites, one function. If the rule for who may read a
sanitary sheet is right, the rule for who may read the sanitary conversation is
right too, and it cannot drift out of step with it.

The branches:

| Reader | Result | Line |
|---|---|---|
| No user | false | 93-95 |
| BPLO, super admin — anyone with `application.view_any_office` | every conversation | 96-98 |
| Applicant | every conversation on their own filing | 100-102 |
| Officer with a department | only their own department's | 104-105 |
| Reviewer with **no** department | nothing | 104-105, fail-closed |

One point of precision the code deserves: the applicant branch literally tests
"is not a reviewer" (`! $user->hasPermission(self::VIEW_ALL)`), not "owns this
filing". Ownership is enforced one door earlier, by `canView()` (lines 237-252)
via `MessageController::authorizeParticipant()` (lines 810-817). The effect is
what we describe, but the ownership check is not in this function.

### May you ADDRESS an office?

A different question with a different answer: every department holding an
`ApplicationAssignment` on that filing, **plus BPLO** — because BPLO coordinates
and an applicant must always be able to reach the front desk.

`MessageController::addressableOffices()` (lines 94-108) builds the set from the
filing's assignments and then adds BPLO if it is not already there, resolving it
by code (`Department::where('code', 'BPLO')`) rather than by id.

Refused server-side on both endpoints, with the same status and the same
sentence:

- **POST** a message — `resolveAddressee()`, lines 182-186
- **GET** the transcript with `?department_id=` — `index()`, lines 627-631

```php
abort_unless(
    $office !== null && ApplicationVisibility::readsThreadOf($user, $office->id),
    403,
    'That office is not handling this application, so it cannot be messaged about it.'
);
```

Hiding the option in the UI would have been easier and would not have been a
rule. The tests check the refusal, not the absence of a button:

- `it('refuses an applicant writing to an office that is not on their filing')` —
  `OfficeScopingTest.php:612`, asserts 403 on **both** the POST and the
  `GET ?department_id=`, then asserts the two legitimate offices are accepted
- `it('will not let one office post into another office\'s conversation')` — line 655
- `it('offers an applicant only the offices actually on their filing')` — line 641
- `it('shows an officer only its own office among a filing\'s conversations')` —
  line 673; CHO sees `['CHO']`, BFP sees `['BFP']`, BPLO sees `['BFP','BPLO','CHO']`

## Two latent bugs went with the rewrite

Both are worth naming, because neither was reported by anyone and both are the
kind that get discovered by an auditor rather than a user.

**BPLO's coordinating turns were invisible to the offices being coordinated.**
The old code said so itself, in its own docblock (lines 60-66 of the previous
`MessageController`):

> Deliberately left OUT, and this is the visible trade: BPLO's and the super
> admin's turns are hidden from a scoped office too. BPLO is another office by
> this rule and the client named no exception for it.

It was a known trade, honestly recorded, and it was the wrong one. The office
whose job is to coordinate the other six was the one office whose messages the
other six could not see.

**The old boundary keyed on the sender's *current* department.** Old
`MessageController`, lines 95-100:

```php
if ($deptId) {
    $q->orWhereExists(fn ($sub) => $sub->selectRaw('1')
        ->from('users as vu')
        ->whereColumn('vu.id', 'messages.sender_user_id')
        ->where('vu.department_id', $deptId));
}
```

`users.department_id` is a live column. Move an officer from CHO to BFP and
every message they had ever sent moved with them: their old CHO turns
disappeared from CHO's view and appeared in BFP's. The new query joins
`message_threads as vt` on `vt.department_id` instead (lines 229-232) — a fact
fixed at the moment of sending, which is what an audit trail has to be.

## The assumption with a visible cost

All 520 historical threads were backfilled to BPLO, because that is where they
came from and because BPLO is the fail-closed answer. The consequence is
immediate and will be noticed on a demo: a scoped office no longer sees its own
historical turns. Log in as `sanitary@biztrack.local` (the demo account seeded at
`DemoSeeder.php:52`, City Health Office) and the message inbox is empty of
history, because `readsThreadOf(sanitaryOfficer, bploId)` is false for every one
of those 520 threads.

The migration records the cost itself, at lines 52-57: *"a historical turn
written by, say, the fire inspector now lives in a BPLO thread, so the fire
office no longer sees its own old words."*

We think this is the right direction to fail — showing an office somebody else's
correspondence is a worse mistake than showing it too little of its own — but it
is a change in visible behaviour and should not surprise anyone mid-demonstration.

> **Open question.** If BPLO would rather the history were distributed to the
> offices that wrote it, that is a second backfill: for each thread, resolve the
> department from the messages inside it. It is doable, it is not free, and it
> would be guessing on threads where two offices both wrote.

## No new routes

The routes did not change — `git diff 9a33fe6^ 9a33fe6 -- api/routes/` is empty.
The addressee arrives as an optional `department_id` parameter on the endpoints
that already existed (`api/routes/workflow.php:113-116`), validated at
`MessageController.php:616` for the GET and `:708` for the POST.

The list of offices you may write to rides on `meta.offices`, produced by
`officeRowsForFiling()` (lines 675-691) and attached to the transcript's meta at
line 640. Each entry carries `department_id`, `code`, `name`, `thread_id`,
`messages_count`, `last_message_at` and `can_message`, sorted busiest-first with
silent offices last by name. The frontend reads it at
`web/src/components/MessagesPanel.tsx:304` and `can_message` drives the
closed-conversation state.

One correction: `meta.offices` is exact for the transcript endpoint, but the
**inbox** endpoint carries the same array one level down, on each row as
`data[].offices` (`threadRow()`, line 434). Same shape, different place.

## Tests

Eight cases added across two files, and several existing ones rewritten in place
(so the diff is larger than the net count):

- `api/tests/Feature/MessageThreadsTest.php` — 6 → 9
- `api/tests/Feature/OfficeScopingTest.php` — 28 → 33

New names include `it('names the offices an applicant may talk to on each inbox
row')`, `it('counts each office\'s conversation separately on the inbox row')`,
and `it('names the office every message turn belongs to')`.
