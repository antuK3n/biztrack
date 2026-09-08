import { useCallback, useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { CheckIcon, InfoCircleIcon, UploadIcon } from '../../components/icons'
import { Alert } from '../../components/ui/Alert'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { PillButton, ProtoModal } from '../../components/ui/Proto'
import { businessName, formatBytes, pesoToNumber } from '../../lib/format'
import { toApiError } from '../../lib/api'
import { applications, clearances, officeForms } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import {
  OfficeFormSheet,
  hasOfficeForm,
  officeFormMissing,
  type CarriedOverBusiness,
  type OfficeFormCode,
  type OfficeFormData,
} from './OfficeFormStep'
import { ACCEPT_ATTR, fileRejection, uploadErrorMessage } from './uploads'
import type { Application, Clearance, ClearanceMeta } from '../../lib/types'

/*
 * ── LGU Clearances · the stage that opens once the first payment clears ────
 *
 * Decided with the client on 28 August 2026: payment first, clearances after.
 * The reasoning is docs/clearances-after-payment.md and this screen is the half
 * the applicant sees. The flow around it:
 *
 *     wizard (business permit only) → submit → BPLO approves the form
 *         → Tax Order of Payment → PAID
 *         → THIS STAGE unlocks
 *         → Apply sends that clearance to its office (one office at a time)
 *         → each office approves, books its visit, and that permit issues
 *         → all five approved → BPLO signs → the business permit is released
 *
 * This is the screen the whole reordering exists for, and three things on it
 * are consequences of the ordering rather than decoration.
 *
 *   THE LOCK. Before the first payment this stage is visible but shut. Visible
 *   matters: an applicant who cannot see the clearances cannot plan for them,
 *   and "where do I get my sanitary permit" is the question this page answers
 *   even when it cannot yet be acted on. The reason it is shut comes from the
 *   API (`meta.locked_reason`) and is printed VERBATIM — see the render below
 *   for why a sentence written here would be wrong.
 *
 *   THE BALANCE. Fees no longer accrue here, and the copy on this screen had
 *   not caught up. For a while applying re-assessed the filing and moved a
 *   running total, so the number lived on this screen and the buttons named it.
 *   One bill is raised at submission now and it covers all five clearances:
 *   applying adds nothing. Every sentence that said otherwise — "its fee has
 *   been added to your balance due", "the permit is not released until the
 *   balance reaches zero" — was describing a charge that does not happen and a
 *   gate that is not the gate. The gate is five approvals.
 *
 *   THE PRICE ON THE CARD. `fee_preview` is what that clearance costs, and it
 *   stays on the card — an applicant is entitled to know what each office
 *   charges even when it was all billed together. What it must NOT say is that
 *   pressing Apply will add it to anything.
 *
 * Two further properties are load-bearing and predate the reordering. They
 * survived it unchanged and must keep surviving:
 *
 *   1. Apply always opens that office's form. Submit always opens the upload
 *      box. NEITHER TOGGLES. Both were toggles in the wizard and both were
 *      fixed in aabbf21 — "sometimes it will just highlight the apply button,
 *      sometimes it will actually redirect to the form" — because a button
 *      whose meaning depends on state the applicant cannot see is not a button.
 *      Un-applying and removing an uploaded copy are different intentions and
 *      have their own labelled controls, well away from the two that create.
 *
 *   2. The two buttons have very different consequences — though no longer
 *      monetary ones. Apply sends the clearance to its office, which routes the
 *      work and starts that office's clock; Submit hands in a copy of one the
 *      applicant already holds. Neither moves money now. The difference that
 *      matters is who ends up with work to do.
 *
 * ── Assumptions built in here, taken rather than asked ────────────────────
 *
 * The client's instruction was to assume the best fit and not ask. Three
 * assumptions are load-bearing on this screen; all three are listed in
 * docs/clearances-after-payment.md and none is confirmed by BPLO.
 *
 *   A. THE STAGE UNLOCKS ON THE FIRST PAYMENT CLEARING, not on submission.
 *      This screen does not decide that — `meta.unlocked` does — but every
 *      piece of copy on it is written as though payment is the gate, so if the
 *      server ever unlocks on submission instead, the wording here is wrong
 *      before the behaviour is.
 *
 *   B. A REJECTED CLEARANCE DOES NOT KILL THE BUSINESS PERMIT. It stands as
 *      its own failed item — which is why `state === 'rejected'` renders a
 *      panel on ONE card and nothing anywhere near the filing as a whole. Same
 *      open question as checklist item 80 (`AssignmentStatus` has no
 *      `Rejected` case) and it should be answered once for both.
 *
 *   C. APPLYING AFTER THE PERMIT IS RELEASED is allowed by the data model but
 *      is NOT surfaced here. A business that adds a food line in June needs a
 *      sanitary permit it did not need in January, and nothing in the schema
 *      forbids it — but no control on this screen offers it, and the stage
 *      relocks behind whatever `locked_reason` the server gives. Building the
 *      route in without a decision from BPLO would be guessing at what it
 *      costs and what it renews.
 */

/*
 * The status chip is gone, and with it the STATE_META table that dressed it.
 *
 * Its history is worth two lines, because both corrections still bind. It first
 * rendered a chip on every card including untouched ones, reading "Not
 * requested" — *"tf does 'not requested' even mean. way too confusing."* That
 * was fixed by showing a chip only once something had happened. What killed the
 * chip outright was the card it left behind: "Applied for" in a pill three
 * inches above a button reading "Applied ✓", plus a fee, plus a tinted panel
 * explaining the button, plus a second button to undo it.
 *
 * The two rules that survive: a default state needs no badge, and the state
 * belongs on the control that changed it. Refusal is the exception and still
 * gets its own panel — that one is not a status, it is news.
 */

/**
 * Who a permit is for, where the answer is not "every business".
 *
 * EMPTY, and kept as the extension point rather than deleted. Its one entry was
 * MARKET — "only if you trade from a stall in a public or private market" — and
 * the Market Clearance was removed from the system on 6 September 2026, so
 * every permit on this stage is now required of every applicant and none of
 * them needs a note saying who it is addressed to.
 *
 * Deliberately sparse when it does have entries: a permit with no entry is one
 * any business may need, which is the honest default and the way an LGU's
 * newly seeded permit behaves — it renders, with no note, rather than
 * inheriting a claim nobody made about it.
 *
 * Deleted along with it: `MARKET_CATEGORIES` and `marketClearanceApplies()`,
 * which derived from the applicant's declared revenue-code category whether the
 * card belonged on their screen. That derivation was tried and reversed before
 * the permit itself went, and the reason is worth keeping even though the code
 * is not: those categories name the operator who RUNS a market, not the trader
 * who rents a stall inside it, so it showed the card to the landlord and hid it
 * from the tenant — exactly inverted from the population it was meant for. If a
 * conditional permit is ever added here, do not derive its audience from fee
 * categories.
 */
const APPLICABILITY: Record<string, string> = {}

/**
 * Has the applicant applied for this clearance?
 *
 * One predicate, because the answer used to be spelled out at five call sites
 * as `state === 'applied'` (and once as `'applied' || 'issued'`) — and every one
 * of them silently stopped being true. `state` is `application_permit_types
 * .status` verbatim now, and `applied` and `issued` are not among its values, so
 * those five branches were dead: the "switch to a copy" prompt never appeared,
 * the withdraw link never drew, and the dialog never warned that submitting a
 * copy would withdraw a live application.
 *
 * Applied means the pivot has moved off `not_started`. That deliberately
 * includes `rejected` and `returned` — the office has acted on it, which is the
 * strongest possible form of having applied — and excludes `available`, which
 * means no pivot row exists at all.
 */
function hasApplied(state: Clearance['state']): boolean {
  return state !== 'not_started' && state !== 'available'
}
/**
 * What this clearance costs. The number, and as little around it as possible.
 *
 * On the card again, and now unavoidable. It came off when one Tax Order of
 * Payment covered everything: the amount could be deferred to Review & Submit,
 * which was the screen where money was actually agreed to. With the clearances
 * after payment there is no later screen — pressing Apply re-assesses the
 * filing and moves the balance printed above these cards — so the amount has to
 * be legible at the moment of the press. A button that spends an unstated
 * amount is the defect this prevents.
 *
 * *"There's an absurd amount of text here."* This used to return a full
 * sentence, in six variants — one for each combination of priced/free/unpriced
 * and applied/not — and it was printed on every card. Six cards each carrying
 * two sentences of identical fee rules is the wall the client was looking at.
 * The RULE (Apply costs, Submit does not) is stated once above the grid, where
 * it belongs, because it is the same on all six. What is left here is the one
 * thing that actually differs between the cards, which is the amount.
 *
 * The variants collapsed too. The sentences distinguished "what applying would
 * add" from "what this is costing you" because `fee_preview` flips meaning once
 * the clearance is applied for — the server compares against the filing without
 * it either way. A bare amount is true under both readings: ₱735.00 is what
 * this clearance costs, before or after the button.
 *
 * Two traps survive the shortening, and both are about money.
 *
 * `fee_preview` arrives ALREADY FORMATTED — "₱735.00" — because
 * `PermitFees::peso` puts the sign on server-side. It is passed through, never
 * given to formatMoney(): Number("₱735.00") is NaN, and formatMoney answers
 * "₱0.00" for that. A card quoting a free sanitary clearance next to a button
 * that charges ₱660 for one is the worst thing this screen could do.
 *
 * And null is not zero. Null is the office setting its fee case by case; zero
 * is `PermitFees::peso(max(0, $delta))` finding no revenue-code rule to price
 * the clearance with. "₱0.00" would read as a promise that it is free, which is
 * a promise nobody made — so it is not printed as a number at all.
 *
 * "No fee assessed" and not "No fee to pay" for exactly that reason, and the
 * distinction is worth the extra syllable: the first says this assessment
 * carries no line for the clearance, which is what we know. The second tells
 * the applicant the clearance is free, which we do not know and which the
 * Market Clearance's officer-set stall rental is a standing example of being
 * wrong about.
 */
export function feeAmount(preview: string | null): string {
  if (preview === null) return 'Fee set by this office'
  if (Number(preview.replace(/[^0-9.]/g, '')) === 0) return 'No fee assessed'
  return `Fee ${preview}`
}

/**
 * Two props, down from four, because there is one caller now.
 *
 * `onOpenOfficeForm` and `onRowsChange` are gone. Both existed for the wizard:
 * the first sent Apply to a sheet the WIZARD owned as a step of its own rather
 * than letting this component swap what it was drawing, and the second pushed
 * the six rows back up so the wizard could work out which sheets were steps and
 * whether its clearance step passed. The wizard has no clearance step, so
 * neither has a caller.
 *
 * Losing them makes the sheet handling unconditional, which is the real win:
 * `formCode` is now the only way a sheet opens, so there is exactly one path
 * through Apply instead of two that had to be kept in step.
 */
interface ClearanceStageProps {
  applicationId: number
  /** The business as every office sheet carries it, for the sheets opened here. */
  business: CarriedOverBusiness
}

/*
 * `anyClearanceDecided` has been deleted, and the rule with it.
 *
 * It answered "has at least one of the six been decided", and the wizard's LGU
 * Clearances step would not let the applicant past until it was true — item
 * 76's other half, on the argument that a file reaching BPLO with no clearance
 * named is one the counter sends back.
 *
 * Nothing can ask that question any more and get a meaningful answer. The
 * clearances are decided after the business permit has been submitted AND paid
 * for, so at every moment when the old rule used to run, the honest answer is
 * "none, and none could be". There is no step left for it to gate.
 *
 * If a requirement genuinely exists that particular clearances must be held
 * before a permit issues, it belongs on the release gate next to the
 * balance-due check — both are conditions on the permit coming OUT, not on the
 * application going in. It is also A1 in docs/questions-for-malabon.md
 * (does the applicant choose the six, or does BPLO determine them from the line
 * of business and location?), and it must not be answered by quietly
 * reintroducing a checklist here.
 */

/**
 * The cards, the ledger, the lock, and every write behind them.
 *
 * Rendered from exactly one place — the route below. It was two (the wizard's
 * LGU Clearances step was the other) and was kept as one definition so the
 * Apply/Submit semantics could not drift into two subtly different copies.
 * That pressure is gone, but it stays a separate component: the route around
 * it is a header and a business lookup, and the day this needs mounting
 * somewhere else the seam should already exist rather than be cut out of a
 * page under deadline.
 */
export function ClearanceStage({ applicationId, business }: ClearanceStageProps) {
  /* The six rows. Reloaded whole after every mutation — see the note on
   * `clearances` in resources.ts for why a single row is not enough. */
  const [rows, setRows] = useState<Clearance[] | null>(null)
  const [meta, setMeta] = useState<ClearanceMeta | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<unknown>(null)

  /* Which card is mid-request; only its own controls go quiet. */
  const [busyCode, setBusyCode] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)

  /*
   * What just happened, announced rather than only drawn.
   *
   * Applying commits the applicant to a fee. A card that changes silently is
   * invisible to a screen reader: they hear the button, then nothing, and have
   * no way to know they have just added ₱735 to a bill they cannot see.
   */
  const [note, setNote] = useState('')

  /* The card whose SUBMISSION dialog is open. Submit always opens this. */
  const [heldPrompt, setHeldPrompt] = useState<Clearance | null>(null)
  const [heldPromptFile, setHeldPromptFile] = useState<File | null>(null)
  const [heldPromptError, setHeldPromptError] = useState<string | null>(null)

  /*
   * The card whose Apply is about to DELETE the certificate already uploaded
   * to it — CLR-3.
   *
   * Apply used to do this silently: `onApply` called `removeHeld` whenever a
   * held copy existed, which resolves to HeldPermits::forget and takes the
   * stored file off disk as well as the row. No prompt, no undo, on a button
   * whose name says nothing about deletion — and directly against the rule
   * written twice further down this file: *"Removing must stay its own named
   * control"*, *"destroying something must never be the alternate meaning of
   * the button that created it"*.
   *
   * The mutual exclusion itself is right and stays (ClearanceController::apply
   * refuses while a copy is on file). What changes is who agrees to the
   * deletion: the applicant, in a dialog that names the file and whose confirm
   * button says "Delete", before anything leaves the disk.
   */
  const [applyPrompt, setApplyPrompt] = useState<Clearance | null>(null)

  /*
   * The applicant said, by hand, that they trade from a market stall.
   *
   * Item 98's escape hatch. The derivation reads a category that describes
   * market operators, and the client's stall holder is the operator's TENANT —
   * so the one group named in the complaint is the group most likely to be
   * missed. This is the click that fixes it, and it is deliberately one-way:
   * nothing hides the card again, because the only thing that could ask for it
   * back is a second press of a control that has by then done its job.
   */

  /* The office form sheet on screen, when this component owns the sheets. */
  const [formCode, setFormCode] = useState<OfficeFormCode | null>(null)
  const [officeData, setOfficeData] = useState<Record<string, OfficeFormData>>({})
  const [formSaving, setFormSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  /*
   * `publish` is gone: it was `setRows` plus a call up to the wizard's
   * `onRowsChange`, and with no parent listening it was setState wearing a hat.
   * Every write below now calls setRows directly.
   */
  const load = useCallback(async () => {
    setLoading(true)
    setLoadError(null)
    try {
      const result = await clearances.list(applicationId)
      setRows(result.data)
      setMeta(result.meta)
    } catch (err) {
      setLoadError(err)
    } finally {
      setLoading(false)
    }
  }, [applicationId])

  useEffect(() => {
    void load()
  }, [load])

  /*
   * Saved office-form payloads. Unconditional now — this component always
   * renders the sheets, because the wizard that used to own them as steps of
   * its own no longer has a clearance step to hang them off.
   */
  useEffect(() => {
    let active = true
    officeForms
      .list(applicationId)
      .then((forms) => {
        if (!active || forms.length === 0) return
        setOfficeData((prev) => {
          const next = { ...prev }
          for (const f of forms) {
            // Never clobber an edit made in this session that has not saved yet.
            if (!(f.permit_type_code in next) && hasOfficeForm(f.permit_type_code)) {
              next[f.permit_type_code] = f.form_data
            }
          }
          return next
        })
      })
      .catch(() => {
        /* Non-fatal: the sheets are optional free-form JSON, so an unreadable
         * one opens blank rather than taking the whole stage down with it. */
      })
    return () => {
      active = false
    }
  }, [applicationId])

  const unlocked = meta?.unlocked ?? false

  /** Run one mutation, refresh everything, and say what it did. */
  async function runAction(
    code: string,
    what: string,
    action: () => Promise<{ data: Clearance[]; meta: ClearanceMeta }>,
  ): Promise<boolean> {
    setBusyCode(code)
    setActionError(null)
    try {
      const result = await action()
      setRows(result.data)
      // The ledger moves on every mutation, not just the row that was pressed:
      // applying re-assesses the whole filing. Setting rows without meta is how
      // a fee gets charged above a balance that has not budged.
      setMeta(result.meta)
      setNote(what)
      return true
    } catch (err) {
      setActionError(toApiError(err).message)
      return false
    } finally {
      setBusyCode((c) => (c === code ? null : c))
    }
  }

  /**
   * APPLY — always opens that office's form. Never un-applies.
   *
   * Carried over verbatim from the wizard fix in aabbf21. Apply used to run
   * `if (!selected && hasOfficeForm) jump(...)`, so the first click applied and
   * opened the form and the second silently un-applied and opened nothing. Two
   * clicks apart, visually near-identical, no way to tell which you were about
   * to get. Withdrawing has its own control on the card.
   *
   * The POST is skipped when the clearance is already applied for: re-posting
   * would ask the server to attach what is already attached, and on a screen
   * that commits the applicant's money "probably idempotent" is not good
   * enough. Reopening the form is the whole of what a second Apply means.
   */
  function onApply(row: Clearance) {
    if (!unlocked) return

    /*
     * CLR-3 — the one branch that destroys something asks first.
     *
     * This used to call removeHeld inline and carry on. The applicant pressed a
     * button named "Apply" and their uploaded certificate was gone from disk
     * before the click finished, announced only after the fact in a live
     * region. Deleting a file is a separate decision from applying, so it is
     * asked as one; `applyNow` runs from the dialog's confirm.
     */
    if (row.held_document) {
      setApplyPrompt(row)
      return
    }
    void applyNow(row)
  }

  /**
   * The apply itself, once whatever it costs has been agreed to.
   *
   * Split out of `onApply` so the destructive path and the ordinary one end up
   * at exactly the same code: a second copy of "attach it, then open the sheet"
   * behind the confirmation dialog is how the two would drift.
   */
  async function applyNow(row: Clearance) {
    const code = row.permit_type.code
    if (!unlocked) return

    // Applying for it and already holding it are opposites (same as the wizard,
    // and the same as ClearanceController::apply, which refuses the overlap).
    const removingCopy = row.held_document !== null
    if (removingCopy) {
      const ok = await runAction(code, '', () => clearances.removeHeld(applicationId, code))
      if (!ok) return
    }
    /*
     * "Not started yet", which is two values now — and getting this wrong made
     * Apply do nothing at all.
     *
     * This read `state === 'available' || state === 'submitted'`. Both were
     * states of the OLD inference, where `state()` guessed from what existed:
     * nothing attached meant `available`, a held copy meant `submitted`.
     * `state()` now returns `application_permit_types.status` verbatim, and
     * `submitted` is not one of its values at all.
     *
     * `available` survives, but only for a permit that is not attached — and
     * every one of the five is attached at submission, as `not_started`. So the
     * condition was false on every required clearance: the POST never fired,
     * the office was never routed, and the press fell through to opening the
     * office sheet. Silently, because nothing here reports a skipped branch.
     * A filing in that state can never be approved — no office is ever given
     * the work.
     *
     * Written against the enum rather than a list of strings, so a new
     * ClearanceStatus cannot quietly rejoin this branch.
     */
    const notStartedYet = row.state === 'not_started' || row.state === 'available'
    if (notStartedYet) {
      const ok = await runAction(
        code,
        /*
         * One sentence for both halves of the act, not two announcements where
         * the second overwrites the first. A live region only ever holds the
         * last thing written to it, so a deletion announced and then replaced
         * 200ms later by "Applied for your …" is a deletion nobody was told
         * about.
         *
         * No longer says a fee was added: there is no accrual. One bill is
         * assessed at submission and covers all five, so "its fee has been
         * added to your balance due" described a charge that does not happen.
         */
        removingCopy
          ? `Applied for your ${row.permit_type.name}, and deleted the copy you had uploaded.`
          : `Applied for your ${row.permit_type.name}. It has gone to its office.`,
        () => clearances.apply(applicationId, code),
      )
      if (!ok) return
    }
    /*
     * One path to the sheet, not two. This used to branch: hand the code up to
     * the wizard if it had asked to own the sheet, otherwise render it here.
     * The wizard's half is gone, so the sheet always opens over these cards —
     * and Apply's promise ("always opens that office's form") is now kept by a
     * single line that cannot get out of step with a second implementation.
     */
    if (hasOfficeForm(code)) {
      setFormError(null)
      setFormCode(code)
    }
  }

  /*
   * `onUnapply` is gone, and the reason is worth keeping.
   *
   * Withdrawing a clearance was free and reversible, which is why it needed
   * no confirmation while Apply-over-a-copy did. It is now impossible:
   * `unapply` refuses a required clearance and all five are required, so the
   * call could only ever return 422. Every control that reached it has been
   * removed rather than left to fail politely.
   *
   * Two things it recorded that outlive it. Un-applying never deleted an
   * office sheet's saved answers (ClearanceService::unapply says why), so if
   * an optional clearance ever makes withdrawal meaningful again, re-applying
   * still costs one click and loses nothing. And the open question it carried
   * is still open: whether a clearance fee already PAID is refundable on
   * withdrawal. That one needs BPLO rather than a guess, and it is recorded in
   * docs/questions-for-malabon.md rather than in a function nobody can call.
   */

  /** Take the uploaded copy back off. Its own labelled control — never Submit. */
  async function onRemoveHeld(row: Clearance) {
    await runAction(
      row.permit_type.code,
      `Removed the ${row.permit_type.name} copy you had uploaded.`,
      () => clearances.removeHeld(applicationId, row.permit_type.code),
    )
  }

  /**
   * Send the copy chosen in the SUBMISSION dialog. Costs nothing.
   *
   * ── CLR-1, the other direction ─────────────────────────────────────────────
   *
   * `onApply` has always resolved this conflict for held → applied. This is its
   * counterpart, and its absence is the reported bug: the server refuses
   * `storeHeld` while the permit type is attached (ClearanceController:138) and
   * the applicant had nothing to press that would satisfy it.
   *
   * The withdrawal goes through `clearances.unapply` — the real
   * DELETE /clearances/{code}/apply endpoint — and NOT through a new
   * server-side auto-withdraw inside `storeHeld`. Three reasons, all of them
   * about not weakening a rule that is currently intact:
   *
   *   1. It routes through unapply's three guards as written, rather than
   *      through a second copy of them: the permit type is really attached, no
   *      Permit has been issued for it, and `officeHasActed` is false. That
   *      last one is called defence-in-depth by its own test because nothing
   *      could reach it; a switch is a new way in, and it reaches the guard
   *      itself rather than a paraphrase.
   *   2. The invariant survives by construction. Two sequential requests, each
   *      one guarded, and the state between them ("neither applied for nor
   *      held") is a legal one the server already models. `storeHeld` never
   *      has to be taught to write both records, so it can never do it wrong.
   *   3. `unapply` requires a live business record (assertPriceable) and
   *      `storeHeld` deliberately does not — 139 filings in the register point
   *      at a soft-deleted business. Folding the withdraw into `storeHeld`
   *      would inherit that requirement and start refusing held copies that are
   *      accepted today. Here it is inherited only by the switch, which is the
   *      only path that actually re-prices anything.
   *
   * Order matters and this order is the recoverable one. Withdraw, then upload:
   * if the upload then fails, the applicant is left with neither record, which
   * is one free click from where they started. The other order cannot happen at
   * all — the server refuses it — and would be the state the whole rule exists
   * to prevent.
   */
  async function onSubmitHeld(row: Clearance, file: File) {
    setHeldPrompt(null)
    const code = row.permit_type.code
    setBusyCode(code)
    setActionError(null)
    try {
      /*
       * No withdrawal first, and this is a correction of a correction.
       *
       * This un-applied before uploading, so applying and holding a copy could
       * not both be true. That was right while a clearance could be withdrawn.
       * All five are required now and `unapply` refuses every one of them —
       * 422, "required on every application and cannot be withdrawn" — so the
       * call could only throw, and it threw BEFORE the upload, meaning the file
       * the applicant had chosen was never sent.
       *
       * `submitHeld` settles the overlap server-side, which is where a rule
       * about what may coexist on a filing belongs.
       */
      const result = await clearances.submitHeld(applicationId, code, file)
      setRows(result.data)
      // The ledger is re-read on every mutation rather than assumed unchanged:
      // setting rows without meta is how the two drift apart.
      setMeta(result.meta)
      setNote(`Your ${row.permit_type.name} copy is on file.`)
    } catch (err) {
      // Upload failures arrive without a usable message twice over; translate.
      //
      // The "your application was withdrawn first" branch is gone with the
      // withdrawal it described. Nothing on the card changes before the upload
      // now, so a failure here means only that the file did not go — which is
      // what the plain message says, and there is no state behind the dialog
      // for the applicant to have to account for.
      setActionError(uploadErrorMessage(err))
    } finally {
      setBusyCode((c) => (c === code ? null : c))
    }
  }

  /** Save the open office sheet and go back to the cards. */
  async function saveForm() {
    if (!formCode) return
    setFormSaving(true)
    setFormError(null)
    try {
      await officeForms.save(applicationId, formCode, officeData[formCode] ?? {})
      setFormCode(null)
      // The sheet being complete is part of the row, so re-read it.
      await load()
    } catch (err) {
      setFormError(toApiError(err).message)
    } finally {
      setFormSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-28 w-full rounded-xl" />
        <Skeleton className="h-64 w-full rounded-xl" />
      </div>
    )
  }
  if (loadError || !rows || !meta) {
    return <ErrorState error={loadError ?? new Error('Not found')} onRetry={() => void load()} />
  }

  const formMissing = formCode ? officeFormMissing(formCode, officeData[formCode] ?? {}) : []

  /*
   * Every permit is on the grid, and every one of them is required.
   *
   * This has been answered three different ways and the history is the reason
   * the binding is named rather than `rows` being inlined into the map. It was
   * six cards for everyone; then five, with the Market Clearance derived from
   * the applicant's declared revenue-code category; then six again, because
   * that derivation showed the card to the market OPERATOR and hid it from the
   * stall TENANT it was written for. It is five now, permanently: Market
   * Clearance was removed from the system on 6 September 2026 and the remaining
   * five are mandatory on every application
   * (docs/application-flow-2026-09.md rule 1).
   *
   * So nothing filters this list, which is what `visibleRows = rows` says. If a
   * conditional permit is ever added, filter here rather than adding a second
   * render branch — the card, its buttons and its whole state machine are one
   * definition, and a second copy for the conditional one is how the two drift.
   */
  const visibleRows = rows

  /* The sheet, when this component owns it — it replaces the cards rather than
   * sitting under them, so the applicant is on one thing at a time. */
  if (formCode) {
    return (
      <div>
        {formError && (
          <div className="mb-4">
            <Alert variant="error" title="This form was not saved">
              {formError}
            </Alert>
          </div>
        )}
        <OfficeFormSheet
          code={formCode}
          data={officeData[formCode] ?? {}}
          business={business}
          onChange={(data) => setOfficeData((d) => ({ ...d, [formCode]: data }))}
        />
        <div className="mt-8 flex flex-col gap-2">
          <div className="flex items-center gap-4">
            <PillButton
              onClick={() => void saveForm()}
              disabled={formSaving || formMissing.length > 0}
              className="min-w-28"
            >
              {formSaving ? 'Saving…' : 'Save & back to clearances'}
            </PillButton>
            <button
              type="button"
              onClick={() => {
                setFormCode(null)
                setFormError(null)
              }}
              className="text-sm font-semibold text-ink-secondary underline underline-offset-2 hover:text-ink"
            >
              Back without saving
            </button>
          </div>
          {formMissing.length > 0 && (
            <p className="max-w-md text-xs text-ink-muted">
              Still needed on this form: {formMissing.join(', ')}
            </p>
          )}
        </div>
      </div>
    )
  }

  /*
   * Has everything assessed on this filing been paid?
   *
   * The one place a formatted peso string has to become a number. `balance_due`
   * arrives as display text ("₱1,650.00") from `PermitFees::peso`, so this is
   * `pesoToNumber` and never `Number()` — Number("₱1,650.00") is NaN, and NaN
   * compared against zero is false, which would silently report every filing as
   * still owing. See the note on ClearanceMeta.
   *
   * `> 0` rather than `!== 0`, because an overpayment is a negative balance and
   * nothing is being withheld for it. NaN — an unreadable figure — falls
   * through to false as well, which is the right way round: the screen says
   * nothing about a release gate it cannot evaluate rather than telling an
   * applicant they owe money we failed to parse.
   */
  const balance = pesoToNumber(meta.balance_due)
  const owesMoney = Number.isFinite(balance) && balance > 0

  return (
    <div>
      {/*
        The lock, in the API's own words and ONLY the API's own words.

        `locked_reason` is printed verbatim, with no heading over it. There used
        to be one — "These can no longer be changed" — and it was safe only while
        this stage had a single reason to be shut. It has two now, and they are
        opposites: before the first payment clears the stage has not opened YET,
        and after the permit is released it is closed for good. A fixed heading
        is guaranteed to be wrong about one of them, and "these can no longer be
        changed" told an applicant who had not paid that they had missed their
        chance.

        The server knows which case it is in, because it knows what has been
        submitted, assessed and paid. So it supplies the sentence and the screen
        supplies nothing. The fallback below is deliberately contentless: it
        names no cause, because a locked stage with no reason given is a server
        bug and inventing a cause would hide it.
      */}
      {!unlocked && (
        <div
          role="status"
          className="mb-6 flex gap-2.5 rounded-md border border-blue-200 bg-blue-50 px-3.5 py-3 text-sm text-blue-800"
        >
          <InfoCircleIcon size={20} className="mt-px shrink-0" />
          {/*
            One copy of the sentence, and it is the one the buttons point at
            through aria-describedby. An earlier version repeated it under the
            cards as the describedby target, which put the same paragraph on
            screen twice — a sighted reader saw it duplicated and a
            screen-reader user heard it once as a banner and again on every
            button.
          */}
          <p id="clearances-locked" className="min-w-0">
            {meta.locked_reason ?? 'This stage is not open.'}
          </p>
        </div>
      )}

      {/*
        ── The ledger ────────────────────────────────────────────────────────

        Money accrues on this screen, so the running total belongs on it. This
        block did not exist when every clearance was billed on one Tax Order of
        Payment assessed at submit — there was nothing to accrue and the figures
        would have read zero — and it is back because that is no longer true:
        each Apply re-assesses the filing and moves `balance_due`.

        Shown while LOCKED as well as unlocked, which is the less obvious half.
        A locked stage means the first payment has not cleared, and the balance
        is then exactly what the applicant must pay to open it — the single most
        actionable number on the page. Hiding it until it stops mattering would
        be precisely backwards.

        The three figures are printed as the server formatted them and are never
        re-formatted here. One formatter, server-side (`PermitFees::peso`), is
        how the peso sign, the separators and the two decimal places stay in
        agreement across the screens that show them.

        `tnum` on the amounts: lining figures so the three of them stack into a
        column the eye can subtract down, rather than three strings of different
        widths.
      */}
      <div className="mb-6 rounded-xl bg-white px-5 py-4 shadow-card">
        <dl className="flex flex-wrap items-baseline gap-x-8 gap-y-2">
          <div>
            <dt className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
              Assessed
            </dt>
            <dd className="tnum mt-0.5 text-base font-semibold text-ink">{meta.total_assessed}</dd>
          </div>
          <div>
            <dt className="text-xs font-semibold uppercase tracking-wide text-ink-muted">Paid</dt>
            <dd className="tnum mt-0.5 text-base font-semibold text-ink">{meta.total_paid}</dd>
          </div>
          <div>
            {/*
              The balance is the one that decides something, so it is the one
              that is bigger and coloured. The other two are here to make it
              checkable — a balance with no assessed and paid beside it is a
              number the applicant has to trust rather than one they can verify.
            */}
            <dt className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
              Balance due
            </dt>
            <dd
              className={`tnum mt-0.5 text-xl font-bold ${owesMoney ? 'text-royal' : 'text-s-green'}`}
            >
              {meta.balance_due}
            </dd>
          </div>
        </dl>
        {/*
          What the balance means, stated wherever it is shown.

          ── The accrual it used to describe is gone ─────────────────────────

          Both branches promised that pressing Apply below would add a fee here
          — "each clearance you apply for adds its fee" — and that the permit
          was withheld until the running total was settled. Neither is true
          after 6 September 2026. `WorkflowService::submit()` attaches every
          required permit and assesses the fees in that order, so the single
          Tax Order of Payment raised at submission already prices all five;
          the client confirmed the intent ("the bill will charge all regardless
          if the applicant selects upload or apply"), and
          `ClearanceService::reassess()`, which was what actually re-priced on
          Apply, no longer exists.

          So the ordinary reading of this block is now a settled zero, and what
          holds the permit is no longer money — it is the five approvals. The
          non-zero branch is kept because a balance can still appear: an officer
          may raise the assessment. It says what is owed and stops promising
          what applying will cost.
        */}
        <p className="mt-3 text-sm text-ink-secondary">
          {owesMoney ? (
            <>
              Your Business Permit is <span className="font-semibold text-ink">not released</span>{' '}
              while this balance is unpaid. Applying for the clearances below adds nothing to it —
              your Tax Order of Payment already covered them.
            </>
          ) : (
            <>
              Nothing is outstanding — your Tax Order of Payment covered every permit below, so
              applying for them costs nothing further. Your Business Permit is released once all
              of them are approved.
            </>
          )}
        </p>
      </div>

      {actionError && (
        <div className="mb-6">
          {/* role="alert" — the failure has to reach a screen reader without
              the applicant going looking for it. */}
          <Alert variant="error">{actionError}</Alert>
        </div>
      )}

      {/*
        The whole rule, once, above the grid.
        *"There's an absurd amount of text here."*

        Three paragraphs stood here and two more sentences were repeated on
        every card, which is what made this screen a wall: the Apply/Submit rule
        is identical for all six clearances, so printing it six times added no
        information and buried the one thing that does differ — the amount.

        What is left is the asymmetry, and only the asymmetry. Apply spends
        money and Submit does not; it is the only thing on this screen a person
        can get materially wrong, so it is the one thing said in full.

        It used to say Apply adds the fee "to your Tax Order of Payment", which
        was true when one assessment at submit covered everything. It is not any
        more: the applicant has already paid a Tax Order of Payment to get here,
        and what Apply moves is the balance in the block above. Naming the thing
        that visibly changes is also what makes the press checkable — the
        applicant can watch the number they were quoted appear.
      */}
      <p className="mb-5 max-w-3xl text-sm text-ink-secondary">
        All five are required.{' '}
        <span className="font-semibold text-ink">Apply</span> sends that clearance to its office;{' '}
        <span className="font-semibold text-ink">Submit</span> hands in a copy of one you already
        hold. Your payment covered all five, so neither adds to your bill.
      </p>

      {/*
        role="status" so the consequence of the last press is announced, not
        only drawn. Empty until something happens, which is why it is not
        wrapped in a conditional — a live region added to the page at the moment
        it gains text is a live region most screen readers never announce.
      */}
      <p role="status" className="mb-4 min-h-5 text-sm font-medium text-royal">
        {note}
      </p>

      <ul id="clearance-cards" className="grid list-none gap-5 p-0 sm:grid-cols-2 xl:grid-cols-3">
        {visibleRows.map((row) => {
          const code = row.permit_type.code
          const busy = busyCode === code
          const applied = hasApplied(row.state)
          const held = row.held_document
          const appliesTo = APPLICABILITY[code]
          const appliesToId = `clearance-applies-${code}`

          /*
           * What Apply and Submit are described by, in the order a screen
           * reader should hear it: why the button cannot be used, then who the
           * clearance is for. Both are things you need BEFORE pressing, and a
           * note that only a sighted reader gets is not a note that stops
           * anyone applying for a market stall they do not have.
           */
          const buttonDescribedBy =
            [unlocked ? null : 'clearances-locked', appliesTo ? appliesToId : null]
              .filter(Boolean)
              .join(' ') || undefined

          return (
            <li key={code} className="flex flex-col rounded-2xl bg-white px-5 py-5 shadow-card">
              {/*
                No status badge beside the name. It said "Applied for" three
                inches above a button that already reads "Applied ✓", so the
                card asserted the same fact twice in two different vocabularies.
                The button is the honest place for it: it is the control that
                changed the state, and it is what the applicant pressed.
              */}
              <p className="text-lg font-bold leading-snug text-ink">{row.permit_type.name}</p>
              <p className="display-serif mt-2 text-sm italic text-ink-secondary">
                {/* The department relation is nullable server-side. */}
                {row.permit_type.department?.name ?? 'Issuing office'}
              </p>

              {/*
                Who the clearance is for. One line, in the same voice as the
                rest of the card.

                It was a bordered, tinted panel with a bold "Who this is for:"
                label and three sentences inside it — the heaviest thing on the
                grid, sitting on the card that is meant to be the least
                prominent. The reasoning behind it survives; the treatment does
                not. It is also seen by far fewer people now: item 98 keeps this
                card off the grid unless the filing says it belongs there, so
                whoever reads this line went looking for it.

                Still not styled as a warning. Nothing is wrong and nothing is
                blocked — a stall holder reads it and applies.
              */}
              {appliesTo && (
                <p id={appliesToId} className="mt-2 text-xs text-ink-muted">
                  {appliesTo}
                </p>
              )}

              {/*
                What this one costs — see feeAmount() for the two traps in it.

                The amount came off these cards once, on the client's
                instruction: the grid had grown a status chip, a fee, a tinted
                panel and three controls per card, and had stopped being
                scannable. The argument for removing it was that nothing was
                lost, because every clearance's fee landed on the one Tax Order
                of Payment at Review & Submit — a later screen where the money
                was actually agreed to.

                That later screen no longer exists. The Tax Order of Payment has
                been raised and paid before this stage opens, so Apply IS the
                moment of commitment and there is nowhere downstream to read the
                price. It is back as one quiet line, in the same weight as the
                form note beside it, not as the panel that was thrown out.
              */}
              <p className="tnum mt-2 text-xs font-semibold text-ink-secondary">
                {feeAmount(row.fee_preview)}
              </p>

              {/*
                One line, and only for the cards it is true of: pressing Apply
                opens that office's own form. That is the single thing about
                this card a person cannot work out by looking at it.

                It read "Adds its own application form section above" while the
                sheets were steps of the wizard sitting behind the cards. There
                is no "above" now — the sheet opens over this grid — so it says
                what actually happens.
              */}
              {row.has_office_form && (
                <p className="mt-1 text-xs text-ink-muted">
                  Applying opens this office&rsquo;s own form.
                </p>
              )}

              {/*
                ITEM 112 — this panel used to open with its own bold heading,
                "Applying for this clearance" / "Issued by this office", above
                the line about the form. That heading said nothing the card was
                not already saying: the status chip beside the clearance's name
                reads "Applied for" or "Issued", two inches above and in the
                same colour. One line of the panel is now the whole panel, and
                it is the line that tells the applicant what is left to do.
              */}
              {/*
                REMOVED on the client's instruction, and still removed: the
                tinted panel that stood here once a clearance was applied for.

                It held a sentence explaining what the Apply button does ("Its
                form still needs finishing — Apply opens it.") and, inside it, a
                secondary button named "Don't apply for the <full clearance
                name>" that wrapped onto two lines on every card. Stacked with
                the badge and the amount, each card carried a status chip, a
                number, a bordered panel, a sentence about a button and three
                controls — six of those on one grid. The client's words on
                seeing it: "WHAT THE FUCK IS THIS THE OLD ONE IS GOOD ENOUGH."

                The PANEL is what was wrong, and it is what stays gone. Removing
                the undo with it was the mistake (CLR-1): applying commits money
                and spawns a mandatory form section, so it has to be
                reversible — and for four days it was not, on this screen or any
                other. The undo is now one quiet word on its own line below,
                shaped exactly like the "Remove" control the client kept.

                What has not changed: the undo must NOT be a second press of
                Apply. One button meaning two opposite things is the original
                bug (aabbf21) where a second click silently un-applied and
                opened nothing. Do not re-solve this by making Apply a toggle.
              */}

              {row.state === 'rejected' && (
                <div className="mt-3 rounded-md border border-s-red/40 bg-s-red/10 px-3 py-2">
                  <p className="text-xs font-bold text-s-red">This office refused it</p>
                  <p className="mt-1 text-xs text-ink-secondary">
                    {row.assignment?.remarks ?? 'No reason was recorded.'}
                  </p>
                </div>
              )}

              {/*
                The uploaded copy, as one line rather than the green panel that
                used to sit here with a heading, a filename and a red Remove
                link inside it.

                Kept — unlike the badge and the fee — because it is not a
                restatement of anything: it is the only place the applicant can
                see WHICH file they attached, and the only way to take it back.
                Removing must stay its own named control. Clicking "Submitted ✓"
                used to delete the file that had just been uploaded, with no
                confirmation and no undo, and destroying something must never be
                the alternate meaning of the button that created it.
              */}
              {held && (
                /*
                  Row, not one wrapped line. A long filename must not be allowed
                  to push Remove off the end of the card: as one truncating
                  paragraph, a 40-character upload name swallowed the only
                  control that can take the file back. The name is the part that
                  truncates (it has a title attribute and the applicant chose
                  it); the control never shrinks.
                */
                <p className="mt-2 flex items-baseline gap-1.5 text-xs text-ink-muted">
                  <CheckIcon size={12} className="shrink-0 self-center text-s-green" />
                  <span className="truncate" title={`${held.name} · ${formatBytes(held.size)}`}>
                    {held.name}
                  </span>
                  {unlocked && (
                    <button
                      type="button"
                      onClick={() => void onRemoveHeld(row)}
                      disabled={busy}
                      /*
                        Reads "Remove" but is NAMED for its clearance. Six cards
                        share this grid, so a bare "Remove" is six identical
                        controls to anyone moving through them by name — while
                        printing the full clearance name in the button is what
                        made the old card unreadable. The label carries the
                        distinction; the card stays quiet.
                      */
                      aria-label={`Remove the ${row.permit_type.name} copy`}
                      className="ml-auto shrink-0 font-semibold text-ink-secondary underline underline-offset-2 hover:text-ink disabled:opacity-60"
                    >
                      {busy ? 'Removing…' : 'Remove'}
                    </button>
                  )}
                </p>
              )}

              {/*
                CLR-1’s Withdraw control is GONE, and its absence is the rule.
              
                It offered a way back out of Apply, which was right while a clearance
                could be withdrawn. All five are required now and `unapply` refuses
                every one of them — 422, "required on every application and cannot
                be withdrawn" — so the control could only ever produce an error.
                Offering a control the server will refuse is what CLR-4 forbids.
              
                It came back briefly when the dead `state === 'applied'` checks on
                this screen were repaired: those comparisons had been false for
                months, so fixing them turned a dead branch into a live one leading
                straight to a 422. Worth remembering — repairing a broken predicate
                resurrects whatever it was hiding, and not all of it should return.
              
                If an optional clearance is ever added, withdrawal is meaningful for
                that one and this is where it goes — gated on the permit being
                optional, not merely on having been applied for.
              */}

              <div className="mt-5 flex flex-1 items-end gap-2.5">
                {/*
                  Both buttons stay in the tab order when the stage is shut.
                  `disabled` drops a control out of the tab order and most
                  screen readers pass over it, so an applicant using one would
                  never learn the button exists or why it does nothing.
                  aria-disabled says so instead, and the locked reason above is
                  what it points at.
                */}
                <button
                  type="button"
                  disabled={busy}
                  aria-disabled={!unlocked}
                  aria-describedby={buttonDescribedBy}
                  /*
                   * Named for its clearance, shown as one word. Six cards share
                   * this grid and the visible labels are identical across all
                   * of them, so without this a screen-reader user tabbing the
                   * grid hears "Submit" six times with nothing to tell them
                   * apart. Long-standing checklist item; the fix belongs in the
                   * accessible name, not on the face of the card.
                   */
                  aria-label={
                    held
                      ? `Replace the ${row.permit_type.name} copy you submitted`
                      : `Submit a copy of the ${row.permit_type.name}`
                  }
                  /*
                   * SUBMIT always opens the upload box. It used to toggle: a
                   * second click on "Submitted ✓" deleted the copy just
                   * uploaded. Removing is the labelled control above.
                   */
                  onClick={() => {
                    if (!unlocked) return
                    setHeldPromptFile(null)
                    setHeldPromptError(null)
                    setHeldPrompt(row)
                  }}
                  className={`flex-1 rounded-sm px-3 py-2 text-sm font-semibold underline underline-offset-2 transition-colors disabled:opacity-60 ${
                    unlocked
                      ? held
                        ? 'border-2 border-royal bg-white text-royal'
                        : 'border-2 border-royal-deep bg-royal-deep text-white hover:bg-royal'
                      : 'cursor-not-allowed border-2 border-input-border bg-input text-ink-muted'
                  }`}
                >
                  {/*
                    The state lives on the button now that the status chip is
                    gone. "Submitted ✓" is a label, not a second action — the
                    press still opens the upload box, and removing is the named
                    control above.
                  */}
                  {held ? 'Submitted ✓' : 'Submit'}
                </button>
                <button
                  type="button"
                  disabled={busy}
                  aria-disabled={!unlocked}
                  aria-describedby={buttonDescribedBy}
                  /* Named for its clearance — see the Submit button above. */
                  aria-label={
                    applied
                      ? `Applied for the ${row.permit_type.name} — open its form`
                      : `Apply for the ${row.permit_type.name}`
                  }
                  /*
                   * APPLY always opens this office's form. It used to toggle,
                   * so the second click quietly un-applied and opened nothing —
                   * which is why the button sometimes "just highlighted" and
                   * sometimes went to the form. Withdrawing is the labelled
                   * control above.
                   */
                  onClick={() => onApply(row)}
                  className={`flex-1 rounded-sm px-3 py-2 text-sm font-semibold underline underline-offset-2 transition-colors disabled:opacity-60 ${
                    unlocked
                      ? applied
                        ? 'border-2 border-royal bg-white text-royal'
                        : 'border-2 border-royal bg-royal text-white hover:bg-royal-hover'
                      : 'cursor-not-allowed border-2 border-input-border bg-input text-ink-muted'
                  }`}
                >
                  {busy ? 'Working…' : applied ? 'Applied ✓' : 'Apply'}
                </button>
              </div>
            </li>
          )
        })}
      </ul>

      {/*
        ITEM 98 left a "show me the Market Clearance" reveal control here, for
        the stall holders a category-based derivation hid the card from. Both
        the derivation and the control were removed before the permit was, and
        the Market Clearance itself went on 6 September 2026. Every permit on
        this stage is now required of every applicant, so there is nothing left
        to reveal.
      */}
      {/* ── SUBMISSION · a clearance already held ─────────────────────────── */}
      {heldPrompt && (
        <ProtoModal
          title="SUBMISSION"
          cancelLabel="Cancel"
          /*
            Always "Submit", where this once read "Withdraw & submit".

            CLR-1 said a control must name everything it does: on an applied
            clearance this confirm used to withdraw the application before
            filing the copy, and "Submit" alone would have hidden that second
            act. The rule stands; the second act is gone. `unapply` refuses a
            required clearance and all five are required, so nothing is
            withdrawn here and a label promising it would name something that
            does not happen — the same defect in the opposite direction.
          */
          confirmLabel="Submit"
          confirmDisabled={!heldPromptFile}
          onCancel={() => {
            setHeldPrompt(null)
            setHeldPromptFile(null)
            setHeldPromptError(null)
          }}
          onConfirm={() => {
            if (heldPromptFile) void onSubmitHeld(heldPrompt, heldPromptFile)
            setHeldPromptFile(null)
            setHeldPromptError(null)
          }}
        >
          <p className="text-xl font-bold text-ink">{heldPrompt.permit_type.name}</p>
          <p className="display-serif mt-1 text-sm italic text-ink-secondary">
            file type: png, jpg, pdf only
          </p>

          {/*
            The "your application is withdrawn" panel is gone with the withdrawal.
          
            It explained that submitting a copy would withdraw the application
            first — true when a clearance could be withdrawn, and false since all
            five became required. `unapply` refuses them, so nothing is withdrawn
            and the panel described a consequence that does not occur. Telling an
            applicant their application has been withdrawn when it has not is
            worse than saying nothing.
          */}
          {/* A real <label> wrapping the input: the file control is visually
              replaced but never loses its name or its keyboard reachability. */}
          <label className="mt-5 flex cursor-pointer items-center gap-3 rounded-lg border-2 border-dashed border-input-border bg-input/50 px-5 py-3.5 transition-colors hover:bg-input">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-input-border bg-white text-royal">
              <UploadIcon size={18} />
            </span>
            <span className="min-w-0 flex-1">
              <span className="block truncate text-sm font-semibold text-ink">
                {heldPromptFile ? heldPromptFile.name : 'Choose your certificate'}
              </span>
              <span className="block text-xs text-ink-secondary">
                {heldPromptFile
                  ? formatBytes(heldPromptFile.size)
                  : 'The copy you already hold, up to 10 MB.'}
              </span>
            </span>
            <input
              type="file"
              accept={ACCEPT_ATTR}
              className="sr-only"
              onChange={(e) => {
                const picked = e.target.files?.[0] ?? null
                e.target.value = ''
                // Refuse here, not after the upload: naming the actual defect
                // before it is sent is the only version that says what to do.
                const rejection = picked ? fileRejection(picked) : null
                setHeldPromptError(rejection)
                setHeldPromptFile(rejection ? null : picked)
              }}
            />
          </label>
          {heldPromptError && (
            <p role="alert" className="mt-2 text-xs font-medium text-s-red">
              {heldPromptError}
            </p>
          )}
          <p className="mt-4 text-xs leading-relaxed text-ink-secondary">
            Submitting a certificate you already hold is not an application: you skip this office’s
            form, <span className="font-semibold text-ink">nothing is added to your fees</span>, and
            your copy goes to the reviewers with the rest of your file.
          </p>
        </ProtoModal>
      )}

      {/* ── CLR-3 · Apply over a copy already uploaded ────────────────────── */}
      {applyPrompt?.held_document && (
        /*
          The confirmation Apply never had.

          Pressing Apply on a card that already carries an uploaded certificate
          deletes that certificate — the row AND the file on disk, through
          HeldPermits::forget. It did so silently, with the only signal a
          sentence in a live region after the fact, on a button whose name says
          "Apply". The card three inches away has carried the rule since the day
          it was written: *"destroying something must never be the alternate
          meaning of the button that created it."*

          Red, and the confirm says Delete. The applicant is not being asked
          whether to apply — they pressed Apply and meant it. They are being
          asked whether that is worth their file, which is a different question
          and is the one with no undo behind it.
        */
        <ProtoModal
          title="WARNING"
          tone="red"
          cancelLabel="Keep my copy"
          confirmLabel="Delete & apply"
          onCancel={() => setApplyPrompt(null)}
          onConfirm={() => {
            const row = applyPrompt
            setApplyPrompt(null)
            void applyNow(row)
          }}
        >
          <p className="text-center text-base text-ink">
            Applying for the{' '}
            <span className="font-bold">{applyPrompt.permit_type.name}</span> deletes the copy you
            submitted.
          </p>
          {/* The filename, because "your copy" is not what the applicant is
              about to lose — a specific file they chose and can see on the card
              is, and naming it is what makes this a decision rather than a
              prompt to click through. */}
          <p className="mt-3 text-center text-sm text-ink-secondary">
            <span className="font-semibold text-ink">{applyPrompt.held_document.name}</span> is
            removed from this application and from our storage. You would need the file again to
            put it back.
          </p>
          <p className="mt-3 text-center text-sm text-ink-secondary">
            A clearance is either one you already hold or one you are asking this office to issue,
            never both.
          </p>
        </ProtoModal>
      )}
    </div>
  )
}

/**
 * The route, `/applications/:id/clearances`.
 *
 * Not "the standalone route" any more — this is THE place the six are chosen.
 * It was a secondary view of a decision made inside the wizard; the wizard has
 * no clearance step now, so every applicant arrives here, and they arrive after
 * paying for their business permit.
 *
 * It stays reachable BEFORE that payment, deliberately, rendering locked. The
 * alternative was a 404 or a redirect, and both answer "where do I get my
 * sanitary permit?" with silence. Locked-and-visible answers it: here, this is
 * what it costs, and this (in the server's words) is what has to happen first.
 * Whether the controls actually work is `meta.unlocked`'s business, not this
 * route's.
 */
export function ClearanceStagePage() {
  const { id = '' } = useParams()
  const appId = Number(id)

  const app = useAsync<Application>(() => applications.get(appId), [appId])

  if (app.loading) {
    return (
      <div className="mx-auto max-w-5xl space-y-4">
        <Skeleton className="h-8 w-72" />
        <Skeleton className="h-28 w-full rounded-xl" />
        <Skeleton className="h-64 w-full rounded-xl" />
      </div>
    )
  }
  if (app.error || !app.data) {
    return <ErrorState error={app.error ?? new Error('Not found')} onRetry={app.reload} />
  }

  const application = app.data
  /*
   * The business as every office sheet carries it. All four sheets open by
   * asking for the same name, address and trade, and the applicant answered all
   * three in the wizard — so the sheet shows what it already knows first.
   *
   * `business` can be null: Business soft-deletes and its filings stay, so a
   * filing can outlive its register row. businessName() names that rather than
   * dereferencing into a crash.
   */
  const b = application.business ?? null
  const line = b?.lines?.[0]
  const carriedOver: CarriedOverBusiness = {
    name: businessName(b),
    tradeName: b?.trade_name ?? '',
    address:
      [b?.address?.line1, b?.address?.line2, b?.address?.barangay?.name]
        .filter(Boolean)
        .join(', ') || '—',
    lineOfBusiness: line?.line_of_business?.trim() || line?.psic_code?.title || '—',
  }

  return (
    <div className="mx-auto max-w-5xl pb-4">
      <div className="mb-6 border-b-2 border-ink/50 pb-2">
        <h1 className="text-2xl font-bold text-ink">{businessName(application.business)}</h1>
        <p className="tnum mt-1 text-sm text-ink-secondary">{application.tracking_id}</p>
      </div>

      <h2 className="display-serif mb-1 text-3xl text-ink">LGU Clearances</h2>
      <div className="mb-6 h-px bg-ink/40" />

      {/*
        The business comes off the SAVED application, and there is no longer a
        second caller holding a fresher copy. That used to be the wizard, which
        passed the profile the applicant was still typing because a category
        entered thirty seconds ago and not yet autosaved was exactly the case
        that mattered.

        Nothing is unsaved by the time anyone gets here: this stage opens after
        the filing has been submitted and paid for, so the record IS the
        freshest copy. One source, and it is the authoritative one.
      */}
      <ClearanceStage applicationId={application.id} business={carriedOver} />
    </div>
  )
}
