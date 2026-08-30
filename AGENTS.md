# BizTrack — working agreement

**This is the shared, tracked file. Read it before the first edit.**

`CLAUDE.md` is gitignored here — it sits under "local tooling and editor state"
beside `.claude/`, `.cursor/` and `.gemini/`, so it is personal to one machine
and does not travel with a clone. The conventions everyone is bound by therefore
live in this file, which is tracked and which several AI coding tools read by
default.

**Setting up a new machine:** create a one-line local `CLAUDE.md` so Claude Code,
which auto-loads that filename, is pointed here:

```
Read AGENTS.md in the repository root before doing anything. It is the working agreement.
```

Keep personal notes in `CLAUDE.md`; keep anything binding on the team in here.

Every rule below exists because something went wrong once; the line under each is
what it cost.

**Confidence marks.** Not everything below is equally certain, and a rule you
obey should say how it is known:

- **[V]** verified by running it or reading the file, and the check is named
- **[C]** taken from a code comment or committed document
- **[J]** a judgement call or a convention adopted in practice, not handed down

If a **[J]** rule gets in your way, say so — those are the negotiable ones. If a
**[V]** rule seems wrong, re-run the check before believing yourself.

---

## 1. Git

**`main` is the client's. Only they push or merge to it. [J]**

```
you:      commit → dev → push        (repeat)
          gh pr create --base main --head dev    ← when the client asks
client:   reviews → merges
```

- **Work on `dev` by default. [J]** It is the shared integration branch and is
  currently identical to `main`. Your teammate pulls from it freely.
- **When another developer is actively working, take your own branch off `dev`
  and PR into `dev`. [J]** Never both commit to the same branch. This session
  ran six agents in parallel and the only real damage came from two writers on
  one file.
- **You open PRs; the client merges. [J]** Put the verification results in the
  PR body (see §3) so the merge decision has evidence attached.
- **Never `git stash`** on the shared working tree — other work may be
  uncommitted. **[J]**
- **Never `git reset --hard`** a tree someone else is working in. **[J]**
- **Check what `git add -A` is about to take. [V]** An unrelated uncommitted
  change (`web/e2e/auth.setup.ts`) was swept into an unrelated commit this way.

### Commit as you go, and say so out loud [J]

**Commit at each finished piece of work, not once at the end of a session.** A
piece is finished when the suites for it pass — not when the whole task is done.

**Actively remind the developer to commit.** Do not wait to be asked. Say it
when any of these is true:

- a coherent change is complete and its checks pass
- the working tree has grown past roughly a dozen changed files
- you are about to start something unrelated to what is already uncommitted
- you are about to do anything that could lose work: switching branches,
  deleting files, rewriting data, running a migration, restarting a stack
- a long-running or parallel task is about to begin

Uncommitted work is the only work with no way back. It is also what makes
`git add -A` dangerous — an unrelated change sitting in the tree gets swept into
whatever commit comes next, which is exactly how `web/e2e/auth.setup.ts` ended
up inside a commit about removing R. **[V]**

Two things that are NOT reasons to delay a commit: the work not being deployed
yet, and the branch not being ready to merge. `dev` is a working branch; a
commit on it is a save point, not a claim of completeness.

**Push after committing** unless there is a reason not to. A commit that exists
only on this machine is a backup that has not happened.

If the developer declines, drop it — do not re-ask on the same piece of work.

**Branches that exist and why [V]** — audited 2026-08-30:

| Branch | |
|---|---|
| `main` | **protected on GitHub [V]** — PRs required, force-push and deletion blocked, linear history. Client merges. |
| `dev` | shared working branch; **what the tunnel deploys from** |
| `demo` | **pinned**; fast-forwarded from `dev`, never from `main` |
| `backup/all-work-c50fbb4` | 17 commits, explicit backup — do not delete |
| `feat/demo-autofill` | PR #50 **closed, not merged** — holds real work |

**Auditing whether a branch is merged — two obvious tests are both wrong. [V]**
`git rev-list main..branch` counts by commit hash, so a squash-merged PR looks
unmerged. `git diff main..branch` compares in *both* directions, so an old
branch shows hundreds of changed paths purely because `main` moved on. Use the
PR state:

```bash
gh pr list --state merged --limit 100 --json headRefName --jq '.[].headRefName'
```

### Commit messages [J]

Subject: lowercase, `type(scope): what changed, in plain words`. The body
explains **why the old behaviour was wrong**, not what the diff does. Examples
from the history:

```
fix(errors): stop blaming the API for a gateway's error page
fix(apply): ask for capital once, and say what the category actually is
fix(ui): a placeholder shows the shape of an answer, never an answer
```

---

## 2. The five rules that prevent damage

### 2.1 `tsc --noEmit` checks nothing here [V]

`web/tsconfig.json` is `{ "files": [], "references": [...] }`. With `files: []`
and no `include`, `--noEmit` type-checks **zero files** and exits 0 — a green
light wired to nothing.

```bash
cd web && npx tsc -b --force        # the only correct check
```

`npm run typecheck` (`tsc -b`) and `npm run build` are fine. Only `--noEmit` lies.

### 2.2 The live database holds real tester filings [C]

`api/database/database.sqlite` is not a fixture — City Hall staff and business
owners have filed against it.

- Never `migrate:fresh`, `db:wipe`, or a blanket `db:seed` against it.
- Never delete a row you did not create.
- Run one migration at a time:
  `php artisan migrate --path=database/migrations/<file>.php --force`
- Report row counts either side of any write. "Nothing was lost" is a
  measurement, not an assurance.

**`./dev.sh` opens with `migrate:fresh --seed`. [V]** Do not run it unless the
database is disposable.

### 2.3 Playwright must never point at `:5173` [V]

`:5173` proxies to the API holding the live register, and Playwright creates and
mutates applications. The isolated stack is **`:5199`** (web) / **`:8081`** (API),
raised by `npm run e2e:stack`, which copies the SQLite file to a throwaway.

Vite binds IPv6 here, so `http://localhost:5199` works where
`http://127.0.0.1:5199` may refuse. **[V]**

### 2.4 Run the E2E suite serially [V]

`playwright.config.ts` sets `workers: 4` locally against **one shared SQLite
file**, which produces failures that pass in isolation.

```bash
cd web && npx playwright test --workers=1
```

Judge a failure by whether it reproduces alone. Never "fix" a contention
artefact, and conclude nothing from a run that overlapped another run.

### 2.5 `.env` files are off limits [C]

`api/.env`, `web/.env`, `web/.env.development` are permission-blocked and
gitignored. If a change is needed there, name the line — do not attempt it.
Also local-only: `Revenue Code.pdf`, `api/database/*.sqlite`.

---

## 3. Verification — what "done" means

| | Command | Bar |
|---|---|---|
| Backend | `cd api && php artisan test --compact` | **0 failed** |
| Types | `cd web && npx tsc -b --force` | exit 0 |
| E2E | `cd web && npx playwright test --workers=1` | 0 failed |
| PHP style | `cd api && vendor/bin/pint --dirty` | clean |
| Lint | `cd web && npx oxlint` | no new warnings |

Baseline at 2026-08-30: **832 Pest / 10,582 assertions**, **158 Playwright
passed / 1 skipped**. Pest takes ~85s; Playwright ~9 min serially. **[V]**

Some E2E specs skip themselves when the copied register holds no suitable
filing, so the passed/skipped split moves with the data, not the code. **[V]**

**Never claim a suite passes without running it.** If something fails, say so
with the output.

**`e2e-stack.sh` copies the live database and never migrates it. [V]** When the
register lags the codebase a fresh stack 500s on new columns:

```bash
DB_DATABASE=<abs>/api/database/e2e.sqlite php artisan migrate --force
```

---

## 4. Orientation before code

A hook enforces this. **[V]**

```bash
graphify query "<question>"    # scoped subgraph — start here
graphify path "<A>" "<B>"
graphify explain "<concept>"
graphify update .              # after modifying code; AST-only, no API cost
```

`graphify-out/wiki/index.md` for broad navigation; `GRAPH_REPORT.md` only for
architecture review. In practice `query` tells you *which files matter* and you
still read them.

---

## 5. Repository shape

```
api/     Laravel 13 / PHP 8.4, Sanctum, SQLite (Postgres in the prod runbook)
web/     React 18 + TypeScript + Vite + Tailwind v4 + react-router
docs/    specs, runbooks, the LGU question register, reports
scripts/ demo-deploy.sh · demo-up.sh · demo-down.sh
infra/   docker-compose for the MISD box
```

Worktrees: `biztrack/` on `main`, `biztrack-demo/` on `demo`. The demo worktree
is **pinned deliberately** — testers once watched fields change under them
because the tunnel served the working tree. Your edits cannot reach testers until
someone deploys. **[C]**

**There is no R. [V]** A separate R/plumber service computed the analytics until
2026-08-30; every statistic is now PHP (`app/Support/*Analytics.php`, `Spc`,
`Des`, `Glm`). A comment citing `r/R/*.R` names the historical prototype, not a
dependency.

---

## 6. How code is written here

### 6.1 Comments carry the reasoning [J — client-confirmed]

Unusually high comment density, deliberately. Comments explain **why**, record
what was tried and rejected, and name the condition that would make a decision
wrong again.

- When you delete something, say what went and what would bring it back.
- **When a comment argues for a design you are changing, rewrite the comment.**
  One defending an architecture that no longer exists is worse than none — a
  reader trusts it enough to be misled.
- Cite the source of a decision: checklist item, client instruction, document §.

### 6.2 Accessibility [C — WCAG 2.1 AA is the stated target in PRODUCT.md]

- **Never `disabled` on an interactive control.** Use `readOnly` /
  `aria-disabled`. Screen readers skip `disabled`, taking the control *and* its
  explanation with it. A passing test asserts this.
  - **Playwright treats `aria-disabled` as disabled. [V]**
    `getAriaDisabled()` is `isNativelyDisabled() || hasExplicitAriaDisabled()`.
    A `.click()` on one times out — that is the gate working, not a missing
    control. This cost seven failing tests and a wrong diagnosis.
- **Repeated controls need distinct accessible names.** Six buttons reading
  "Apply" are six identical stops. Put the distinction in `aria-label` and keep
  the visible label short.
- Charts render SVG a screen reader cannot read, so every chart also renders its
  numbers as a real table (`components/charts/ChartFrame.tsx`).

### 6.3 Design [C — DESIGN.md]

- **Red Means Stop.** `#bd0000` is for errors and destructive actions, never a
  category label. A busy block is not an error; a falling growth rate is not an
  error.
- **Never Color Alone.** Every colour-coded value is also readable as text.
- Royal `#3242ca`; civic-blue `#0025cc` family; errors `#bd0000`.
- BizTrack is a **product-register** app — design serves the workflow. Permit
  processing for business owners and LGU officers. Approachable, modern,
  helpful — never a dated PH gov portal, generic SaaS dashboard, playful
  consumer app, or sterile enterprise UI.
- **`BizTrack Prototype Linked.pdf` is a flow reference only.** Keep its screen
  flows and civic-blue palette; do **not** reproduce its visual execution.
- Read `PRODUCT.md` (users, purpose, principles, WCAG target) and `DESIGN.md`
  (visual system) before any UI work.

### 6.4 Copy [J — from repeated client feedback]

- **Say it once.** If a neighbouring label says it, delete it. Stacked
  restatement is what reads as machine-written.
- **A placeholder shows the shape of an answer, never an answer.**
  `11 digits, starting 09` — not `e.g. 0917 123 4567`, which reads as real.
- **Name both numbers.** "15 of 48" says nothing; "15 of the 48 businesses near
  this pin" does.
- **A dash, never a zero**, where a figure genuinely has no value.
- Write for a BPLO clerk. Keep a term that names the *thing* (PSIC, RA 11032
  tier) and gloss it; replace one that names a *method* (Kaplan-Meier, cohort,
  censoring) with what it does.

---

## 7. Tests

- **A test's name states the rule it enforces. [J]** When behaviour changes,
  rename it to the new rule.
- **Never weaken an assertion to make it pass. [J — client-confirmed]** If the
  product is wrong, change the product. If the test encodes a rule that is no
  longer true, rewrite it and say which you did.
- **Prove a regression test fails against the bug** before fixing it. A test that
  never went red is not known to work. **[J]**
- **Beware fixtures that assert nothing. [C]**
  `UploadedFile::fake()->create()` writes an **empty** file, so byte assertions
  compare zero to zero. A checklist item "passed" for weeks that way.
- **Do not guess accessible names** — query the DOM. **[J]**
- **`web/` has no unit-test runner [V]** (no vitest/jest). Playwright is the only
  frontend harness; to prove a pure function, drive it with
  `page.route(...).fulfill(...)`.

---

## 8. Assumptions and open questions [C — client asked for this file]

A guess is never left in the code alone. `docs/questions-for-malabon.md` is the
single register, ordered by who can answer (A BPLO · B MISD · C CPDO · D adviser
· E documents). Each entry has three parts:

1. **the question**, in plain language
2. **why it matters** — what breaks, or what we guessed
3. **what we assumed meanwhile** — and that if an answer contradicts it, the
   system changes; the assumption is not defended

Standing example: the Fire Code and sanitary inspection fees are gated on their
clearance being requested, which is arguably wrong under RA 9514. Changing what a
citizen is charged on our own reading of a statute is not ours to decide, so it
is written down rather than quietly fixed.

---

## 9. Deploying to testers [V]

```bash
./scripts/demo-deploy.sh    # zero-downtime: new tunnel up before the old goes down
./scripts/demo-up.sh · ./scripts/demo-down.sh
```

Builds the **pinned `demo` worktree** as a bundle — not the dev server — serves
Laravel on `:8082` with `APP_DEBUG=false` and `vite preview` on `:5180`, then
opens a Cloudflare quick tunnel.

- **Always deploy from `dev`. Never from `main` unless the client says so
  explicitly. [J — client instruction, 2026-08-30]**

  ```bash
  git -C ../biztrack-demo merge --ff-only dev
  ```

  `main` is the reviewed, merged history and moves only when the client merges a
  PR; `dev` is what is actually being tested. Deploying `main` would put testers
  on work that is by definition older than the work they are being asked to test.
- **A redeploy drops every open tester session and mints a new URL.** Confirm
  before doing it unless asked.
- `APP_DEBUG=false` stays off while the tunnel is public — a stack trace on a
  public URL leaks paths and config. **[C]**
- The script refuses to start if something that should be localhost-only is bound
  to `0.0.0.0`. Do not remove that guard. **[C]**
- Verify **through** the tunnel with a `POST` (login): a dead quick-tunnel can
  still serve a cached page. **[V]**

---

## 10. Security [C]

- Seeded accounts all use `biztrack1`. Demo-only; never reused.
- **Never ask for, accept, or echo a password.**
- **Office separability is a boundary, not a preference.**
  `App\Support\ApplicationVisibility` is the predicate and every office-scoped
  read goes through it. Widening it caused a real leak — a sanitary officer saw
  115 filings of which 38 were theirs.
- Analytics is split: `analytics.view` (BPLO — dashboard, renewal risk, business
  growth) and `analytics.processing_time` (super admin — that screen only).
  Neither role holds both, deliberately: Processing Time measures the
  departments, BPLO among them.

---

## 11. Domain traps

- **Soft deletes leak nulls. [C]** `Business` and `User` are soft-deleted, so a
  payload embedding either can be `null` while the type claims otherwise. Type it
  honestly and let the compiler find the call sites — that is how a production
  crash was traced (139 applications pointing at deleted businesses).
- **RA 11032 tiers are 3 / 7 / 20 working days**, from `App\Support\Ra11032`.
  Never hard-code a day count; the chatbot once quoted "10", which is not a tier
  that exists. **[C]**
- **Renewal identification [V]:** business account no. (`BP-2026-0001`,
  permanent) → permit number (`MCB-2026-000001`, which permit) → tracking ID
  (`BIZ-2026-00473`, the filing in flight). A renewal is keyed on
  `applications.prior_permit_id`. **A business with no permit can still file a
  renewal** — its permit was issued on paper, and `prior_permit_declared_none`
  exists so they can say so. Do not tidy that escape away; in year one it is the
  common case.
- **Clearance ordering has been reversed twice.** As of 2026-08-30 payment comes
  first: the wizard is the business permit alone, and the six LGU clearances open
  as a stage once the first Tax Order of Payment clears. **[V]**
  `docs/clearances-after-payment.md` is the live spec — **its header still reads
  SUPERSEDED and needs swapping with `clearances-before-payment.md`.**

---

## 12. Where to look

| Question | File |
|---|---|
| Product strategy, users, WCAG target | `PRODUCT.md` |
| Visual system | `DESIGN.md` |
| Open questions for City Hall | `docs/questions-for-malabon.md` |
| Clearance / payment ordering | `docs/clearances-after-payment.md` |
| What shipped 2026-08-30 and why | `docs/reports/` |
| Deployment to the MISD box | `docs/runbook-deploy.md` |
| The tester tunnel | `docs/demo-tunnel.md` |
| Adviser's recorded revisions | `docs/r-integration-revisions.md` |

---

## 13. Working style [J]

- **Act when you have enough to act.** Do not re-litigate a settled decision or
  narrate options you will not take.
- **Verify before asserting.** Numbers and behaviour claims are measurements. An
  audit of one session's summaries found **sixteen** confident claims that
  disagreed with the code.
- **Say what you did not do.** A skipped step, a weakened test, an unverified
  claim — surface it rather than letting it read as complete.
- **Confirm before outward-facing or irreversible actions**: deploying, deleting
  untracked files, rewriting live data. Approval in one context does not extend
  to the next.
