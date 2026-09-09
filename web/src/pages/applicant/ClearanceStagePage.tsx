import { useCallback, useEffect, useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { CheckIcon, InfoCircleIcon, UploadIcon } from '../../components/icons'
import { Alert } from '../../components/ui/Alert'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { PillButton, ProtoModal } from '../../components/ui/Proto'
import { businessName, formatBytes, pesoToNumber } from '../../lib/format'
import { toApiError } from '../../lib/api'
import { applications, clearances, officeForms } from '../../lib/resources'
import { clearanceStarted } from '../../lib/status'
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
import type {
  Application,
  Clearance,
  ClearanceMeta,
  OfficeFormRequirement,
} from '../../lib/types'

/*
 * ── LGU Clearances · the stage that opens once the first payment clears ────
 *
 * Decided with the client on 28 August 2026: payment first, clearances after.
 * The reasoning is docs/clearances-after-payment.md and this screen is the half
 * the applicant sees. The flow around it:
 *
 *     wizard (business permit only) → submit → Tax Order of Payment #1 → PAID
 *         → THIS STAGE unlocks
 *         → Apply adds that office's fee to a running balance
 *         → the permit is released when the balance reaches zero
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
 *   THE BALANCE. Fees accrue here, which they did not when everything was
 *   priced at submit. Applying moves a number on this very screen, so that
 *   number has to be on this screen. A stage that charged the applicant and
 *   showed them nothing would be taking money in the dark.
 *
 *   THE PRICE ON THE CARD. `fee_preview` is what applying WOULD add, quoted
 *   before the button is pressed. It was taken off the cards when one Tax Order
 *   of Payment covered everything and the amount could honestly be deferred to
 *   Review & Submit. There is no later screen to defer to now — pressing Apply
 *   IS the moment of commitment — so the amount is back, in the lightest
 *   treatment the card has.
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
 *   2. The two buttons have very different consequences. Apply adds that
 *      office's fee to the balance; Submit costs nothing, because nothing is
 *      being issued. A card that showed them as a matched pair without saying
 *      so would be hiding the only difference that matters.
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
/**
 * How long after the last keystroke an office sheet is written.
 *
 * The same 1200ms `ApplyWizard` uses, deliberately duplicated rather than
 * imported: importing it would drag the wizard's whole module into this route
 * for one number. If one of them is ever retuned, retune both — two screens in
 * one product that save at visibly different speeds feel like two products.
 */
const AUTOSAVE_DELAY_MS = 1200

const APPLICABILITY: Record<string, string> = {}

/**
 * The stored value → the words the paper prints.
 *
 * MCG-CENRO-FO-001 boxes these as TYPE OF BUSINESS and BPLO stores the same
 * four choices as `registration_type`, so the CEC sheet reads BPLO's answer
 * rather than asking again. Mapped rather than de-underscored generically: the
 * paper's wording is the LGU's, and "Sole Proprietorship" is not what
 * `sole_proprietorship` humanises to on its own.
 *
 * An unrecognised value falls through to blank, not to a guess — a business
 * whose registration type predates this list is one CENRO should be handed an
 * empty box for, not a plausible wrong one.
 */
const REGISTRATION_TYPE_LABELS: Record<string, string> = {
  sole_proprietorship: 'Sole Proprietorship',
  partnership: 'Partnership',
  corporation: 'Corporation',
  cooperative: 'Cooperative',
}

/** The paper's SEX box, from the owner's stored gender. */
const SEX_LABELS: Record<string, string> = {
  male: 'Male',
  female: 'Female',
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
  /**
   * The sheet awaiting a "yes, send it" — the client's asked-for last look.
   *
   * Submitting is one-way now: the office has the form and the applicant cannot
   * change it. That is a bigger press than any other on this screen, and it was
   * the same size as saving a draft. So it is confirmed, on the same pattern as
   * the two destructive dialogs already here.
   */
  const [submitPrompt, setSubmitPrompt] = useState<OfficeFormCode | null>(null)
  /**
   * What the server already holds for each sheet, as JSON.
   *
   * The autosave below compares against this rather than tracking a `dirty`
   * flag, for the reason the wizard's autosave gives: a flag has to be cleared
   * by hand in every path that saves, and the one path somebody forgets is a
   * form that says "saved" over answers that are not. A snapshot cannot lie —
   * either what is on screen matches what was written or it does not.
   *
   * Seeded when a sheet loads from the API, so opening a saved form and
   * changing nothing writes nothing.
   */
  const savedSheets = useRef<Record<string, string>>({})
  /** True between an autosave firing and the server answering. */
  const [autosaving, setAutosaving] = useState(false)
  const [officeData, setOfficeData] = useState<Record<string, OfficeFormData>>({})
  const [formSaving, setFormSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  /*
   * The zoning sheet's checklist of requirements, kept beside the answers
   * rather than inside them.
   *
   * `officeData` is opaque JSON the applicant types and autosave writes back
   * verbatim; the checklist is server-owned — which rows apply comes from
   * `is_rented`, and whether each is satisfied comes from what is on disk. Put
   * in the same bag, an autosave would post the checklist back as if it were an
   * answer, and the next `derive` would have to strip it out again.
   *
   * Keyed by permit code even though only ZONING has one, so that the day CHO
   * or BFP sends its checklist there is nothing to restructure.
   */
  const [requirements, setRequirements] = useState<
    Record<string, OfficeFormRequirement[] | undefined>
  >({})
  /** The checklist slot with an upload in flight, so one row can say "Uploading". */
  const [reqBusy, setReqBusy] = useState<string | null>(null)
  const [reqError, setReqError] = useState<string | null>(null)

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
        setRequirements((prev) => {
          const next = { ...prev }
          for (const f of forms) {
            // `null` on the four sheets with no checklist; only a real list is
            // recorded, so an absent key stays "this office has no checklist".
            if (f.requirements) next[f.permit_type_code] = f.requirements
          }
          return next
        })
        setOfficeData((prev) => {
          const next = { ...prev }
          for (const f of forms) {
            // Never clobber an edit made in this session that has not saved yet.
            if (!(f.permit_type_code in next) && hasOfficeForm(f.permit_type_code)) {
              next[f.permit_type_code] = f.form_data
              /*
               * Seed the autosave's baseline with what the server just gave us.
               * Without this, opening a saved sheet and touching nothing would
               * look like an edit to the effect below and write the same answers
               * straight back — a pointless round trip on every sheet opened,
               * and one that would mark a submitted form dirty.
               */
              savedSheets.current[f.permit_type_code] = JSON.stringify(f.form_data)
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
     * ── THE BUG THIS GUARD CAUSED, because it is worth not repeating ─────────
     *
     * It read `row.state === 'available' || row.state === 'submitted'`, and the
     * server stopped sending either name. `submit()` attaches all five required
     * clearances at `not_started`, so every un-applied clearance arrives here as
     * `not_started`, matched nothing, and the POST was skipped — while the code
     * below went on to open the office form regardless.
     *
     * The applicant therefore got the CEC sheet, filled it in, pressed Save and
     * was told "This form can no longer be edited", which was true and
     * unhelpful: `OfficeFormController::ownerMayEdit` looks for an assignment on
     * the issuing office, and applying is what creates one. Four of the five
     * clearances were unreachable this way. (SANITARY on the register's filing 5
     * had been applied for before the attach-at-submit change, which is why one
     * card worked and the rest did not.)
     *
     * `clearanceStarted` is the predicate, so the question asked here is the one
     * that matters — has this clearance been started — rather than a list of
     * status names that can go stale again.
     *
     * `removingCopy` is the other way in: swapping a held copy back to an
     * application. The old union spelled that `submitted`; it is a held document
     * on the row, which the line above already read.
     */
    if (!clearanceStarted(row.state) || removingCopy) {
      const ok = await runAction(
        code,
        /*
         * One sentence for both halves of the act, not two announcements where
         * the second overwrites the first. A live region only ever holds the
         * last thing written to it, so a deletion announced and then replaced
         * 200ms later by "Applied for your …" is a deletion nobody was told
         * about.
         */
        removingCopy
          /*
           * Both sentences used to end "...has been added to your balance
           * due", which `apply()` has not done since the bill moved to
           * submission. What actually happens next is the form opening, so
           * that is what the live region announces.
           */
          ? `Applied for your ${row.permit_type.name}, and deleted the copy you had uploaded. Its form is open below — fill it in and press Save. Your fees do not change.`
          : `Applied for your ${row.permit_type.name}. Its form is open below — fill it in and press Save. Your fees do not change.`,
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

  /**
   * WITHDRAW — take back an application for a clearance. CLR-1.
   *
   * This control was deleted in 9e30b44 along with the panel it sat in, and
   * `storeHeld` has been telling applicants to use it ever since. The client's
   * objection was to the SHAPE, not to the undo: what stood here was a
   * secondary button reading "Don't apply for the ‹full clearance name›",
   * wrapping onto two lines inside a bordered, tinted panel, on all six cards —
   * *"WHAT THE FUCK IS THIS THE OLD ONE IS GOOD ENOUGH."* The reasoning under
   * it was never refuted, and the audit of 2026-08-06 measured what its absence
   * cost: 15 real drafts that could not withdraw a clearance, 5 of which could
   * not be submitted at all, and one route out — destroy the whole filing.
   *
   * So it comes back as the control the client already accepted on this card:
   * the quiet inline link that takes an uploaded copy back off, one word,
   * pushed to the end of its own line, named for its clearance only in its
   * accessible name. Same weight, same place, opposite half of the card. It is
   * NOT a second meaning of Apply — that was the original bug (aabbf21) and
   * making this a toggle again would restore it.
   *
   * Nothing is destroyed here. The permit type is detached, the filing is
   * re-assessed without it, and the office sheet's saved answers stay exactly
   * where they are (ClearanceService::unapply says why), so re-applying costs
   * one click and loses nothing. That is the whole reason this needs no
   * confirmation while Apply-over-a-copy does.
   *
   * It used to say the fee came off "an assessment that has not been written
   * yet", which was true while everything was priced at submit. It is not now:
   * the balance above these cards is live, and withdrawing takes the fee back
   * off it. Still free and still reversible — but only up to the point the
   * office acts, which is what unapply's `officeHasActed` guard is for.
   *
   * NOT MODELLED, and this is the place it would show up: whether a clearance
   * fee already PAID is refundable when the applicant withdraws. Right now the
   * balance simply falls, and if it falls below what has been paid the filing
   * is in credit with nothing on any screen offering it back. Listed as an open
   * question in docs/clearances-after-payment.md; it needs BPLO, not a guess.
   *
   * ── The handler is GONE, and the reasoning above is kept on purpose ────────
   *
   * `onUnapply` called `clearances.unapply`, which `ClearanceService::unapply`
   * now refuses for any permit with `isRequiredClearance()` — and every
   * clearance on this grid is required since 6 September 2026. There is no
   * caller left (see the note where the Withdraw control used to render), so
   * the function went with the button rather than sitting here waiting for one.
   *
   * Everything above still describes what withdrawing MEANS, and it is the
   * spec to build against if an optional clearance is ever added back. The
   * unmodelled refund question is unanswered either way.
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
    /*
     * ── The withdraw-first dance is gone, and had to go ──────────────────────
     *
     * This called `clearances.unapply` before uploading, because a clearance
     * used to be "held" precisely by NOT being in `application_permit_types` —
     * the two records were contradictory and one had to be removed.
     *
     * That inverted on 6 September 2026. Every required clearance is on the
     * pivot from submission whichever way it will be satisfied, and the pivot's
     * `mode` is what tells apply from upload, so `storeHeld` swaps the mode in
     * place. It refuses only on TIMING — once the office has moved the permit
     * past `for_approval` you cannot change the evidence underneath it.
     *
     * Worse than redundant: `ClearanceService::unapply` now throws for any
     * required clearance, and all five are required. So the moment the state
     * check above was corrected, this line would have turned every
     * apply-to-upload swap into a 422 — and the applicant would have been told
     * their clearance "is required and cannot be withdrawn" while trying to
     * hand in the very certificate that satisfies it.
     */
    const switching = clearanceStarted(row.state) && row.held_document === null
    setBusyCode(code)
    setActionError(null)
    try {
      const result = await clearances.submitHeld(applicationId, code, file)
      setRows(result.data)
      // The ledger moves on every mutation, not just the row that was pressed:
      // applying re-assesses the whole filing. Setting rows without meta is how
      // a fee gets charged above a balance that has not budged.
      setMeta(result.meta)
      setNote(
        switching
          ? `Filed your own ${row.permit_type.name} instead of the application you had started. Nothing was added to your fees.`
          : `Your ${row.permit_type.name} copy is on file. Nothing was added to your fees.`,
      )
    } catch (err) {
      /*
       * Upload failures arrive without a usable message twice over; translate.
       *
       * There is nothing left to explain about a half-finished withdrawal:
       * `storeHeld` swaps the mode inside one transaction, so a failure leaves
       * the filing exactly as it was and the file is the only thing that went
       * wrong. The longer sentence that used to be printed here described a
       * two-step this no longer performs.
       */
      setActionError(uploadErrorMessage(err))
    } finally {
      setBusyCode((c) => (c === code ? null : c))
    }
  }

  /** Hand the open sheet to its office. Only ever called on a complete one. */
  async function saveForm() {
    if (!formCode) return
    setFormSaving(true)
    setFormError(null)
    try {
      /*
       * `submit: true`, unconditionally.
       *
       * This used to decide between saving and submitting by re-reading
       * `formMissing`, because one button did both jobs. Autosave took the
       * saving job away: every keystroke is written a beat later, so by the
       * time anybody presses this the answers are already on the server and the
       * only thing left to do is hand them over. The button is shut while
       * anything is missing, so a call reaching here is a complete sheet.
       */
      await officeForms.save(applicationId, formCode, officeData[formCode] ?? {}, true)
      savedSheets.current[formCode] = JSON.stringify(officeData[formCode] ?? {})
      setFormCode(null)
      // The sheet being complete is part of the row, so re-read it.
      await load()
    } catch (err) {
      setFormError(toApiError(err).message)
    } finally {
      setFormSaving(false)
    }
  }

  /**
   * Put a file into one slot of the open sheet's checklist, or take it back off.
   *
   * Both directions through one function because both do the same three things
   * — call, replace the whole checklist with what came back, report the failure
   * on the row — and the server answers both with the full list precisely so the
   * screen never has to merge a row into its own copy.
   *
   * The browser-side file check runs first (`fileRejection`), for the reason
   * `uploads.ts` gives: the API's refusal of an empty PDF is "Upload a PDF, JPG,
   * or PNG file", which is true of the file and useless to the person holding it.
   */
  async function changeRequirement(code: string, documentCode: string, file: File | null) {
    setReqBusy(documentCode)
    setReqError(null)
    try {
      if (file !== null) {
        const rejection = fileRejection(file)
        if (rejection) {
          setReqError(rejection)
          return
        }
      }
      const result =
        file !== null
          ? await officeForms.uploadRequirement(applicationId, code, documentCode, file)
          : await officeForms.removeRequirement(applicationId, code, documentCode)
      setRequirements((prev) => ({ ...prev, [code]: result.requirements }))
    } catch (err) {
      setReqError(file !== null ? uploadErrorMessage(err) : toApiError(err).message)
    } finally {
      setReqBusy((c) => (c === documentCode ? null : c))
    }
  }

  /**
   * Fetch Section X of the CPDD paper, blank, for the applicant to notarise.
   *
   * The failure lands on the checklist rather than on the sheet's own error
   * line: it is that panel's button, and a "this form was not saved" banner
   * appearing because a PDF would not download would send the applicant
   * looking for typing they had not lost.
   */
  async function downloadDeclaration(code: string) {
    setReqError(null)
    try {
      await officeForms.declaration(
        applicationId,
        code,
        'locational-clearance-declaration.pdf',
      )
    } catch (err) {
      setReqError(toApiError(err).message)
    }
  }

  /*
   * ── Autosave, so nothing is lost by leaving the page ──────────────────────
   *
   * The client asked for the same behaviour the BPLO wizard has, and the reason
   * is the same: this sheet held the applicant's typing in the tab and nowhere
   * else, so a reload, a stray back-button or a closed lid took it. The wizard
   * solved that years-of-drafts ago and this screen never inherited it.
   *
   * `submit: false` on every write, and that is what makes autosaving safe at
   * all. Saving used to BE submitting; if it still were, a debounce would hand a
   * half-typed form to an office a second after the applicant paused. Since the
   * two acts were split, an autosave is exactly what it sounds like.
   *
   * ── Why it sits ABOVE the loading guards ─────────────────────────────────
   *
   * Hooks must run in the same order on every render, and the early returns for
   * `loading` and `loadError` are below. Put after them, this effect is skipped
   * on the first render and React tears the hook order apart on the second.
   * So the guards it needs are INSIDE it — `rows` may be null, and a locked
   * sheet must not be written because `ownerMayEdit` refuses it and the
   * applicant would meet a save error on a screen they cannot type into.
   *
   * The same 1200ms the wizard uses. Not tuned separately: two screens in one
   * product that save at visibly different speeds feel like two products.
   */
  useEffect(() => {
    if (!formCode) return
    const row = rows?.find((r) => r.permit_type.code === formCode)
    const locked =
      row !== undefined && row.state !== 'not_started' && row.state !== 'returned'
    if (locked) return

    const payload = JSON.stringify(officeData[formCode] ?? {})
    if (savedSheets.current[formCode] === payload) return

    const timer = setTimeout(() => {
      setAutosaving(true)
      setFormError(null)
      officeForms
        .save(applicationId, formCode, officeData[formCode] ?? {}, false)
        .then(() => {
          savedSheets.current[formCode] = payload
        })
        .catch((err) => setFormError(toApiError(err).message))
        .finally(() => setAutosaving(false))
    }, AUTOSAVE_DELAY_MS)

    return () => clearTimeout(timer)
  }, [applicationId, formCode, officeData, rows])

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
   * Is the sheet on screen a record rather than a form?
   *
   * Once a clearance is submitted the applicant may not change it — the client's
   * rule, and `OfficeFormController::ownerMayEdit` enforces it, refusing any
   * write past NotStarted or Returned. This is the screen agreeing with the
   * server rather than deciding for it: a sheet that took edits and then lost
   * them to a 422 would be the worst of both.
   *
   * `returned` is deliberately editable. An office sending a sheet back is
   * asking for exactly that.
   *
   * Declared above the autosave rather than beside the render it also feeds,
   * because the effect below must not fire on a locked sheet and a `const` is
   * in the temporal dead zone until its own line.
   */
  const openRow = formCode ? rows?.find((r) => r.permit_type.code === formCode) : undefined
  const formLocked =
    openRow !== undefined && openRow.state !== 'not_started' && openRow.state !== 'returned'

  /** True while anything the applicant typed is not yet on the server. */
  const formDirty =
    formCode !== null &&
    savedSheets.current[formCode] !== JSON.stringify(officeData[formCode] ?? {})

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
          readOnly={formLocked}
          requirements={requirements[formCode]}
          requirementBusy={reqBusy}
          requirementError={reqError}
          onRequirementChange={(documentCode, file) =>
            void changeRequirement(formCode, documentCode, file)
          }
          onDeclarationTemplate={() => void downloadDeclaration(formCode)}
        />
        <div className="mt-8 flex flex-col gap-2">
          {formLocked ? (
            /*
             * A submitted sheet has one control and it is the way out. No Save,
             * because there is nothing to save; no "Back without saving",
             * because nothing has been typed.
             */
            <div>
              <PillButton onClick={() => setFormCode(null)} className="min-w-28">
                Back to clearances
              </PillButton>
            </div>
          ) : (
            <>
              <div className="flex items-center gap-4">
                {/*
                  ── One button, and it only ever submits ────────────────────
                  *
                  * It used to be two jobs wearing one label: "Save and come back
                  * later" while anything was missing, "Submit to this office"
                  * when nothing was. The client's question was the right one —
                  * "Where is the submit button?" — because on an incomplete
                  * sheet there wasn't one, and the applicant had no way to see
                  * what they were working towards.
                  *
                  * Autosave took the saving job away, so this does the one thing
                  * its label says. Shut while anything is missing, with the list
                  * printed underneath, and shut while an autosave is in flight —
                  * submitting a sheet whose last keystroke has not landed would
                  * hand the office a form one field out of date.
                  *
                  * `aria-disabled`, not `disabled`: a control removed from the
                  * tab order takes the sentence explaining itself with it, which
                  * is the pattern the officer's Approve already follows. The
                  * press is guarded instead.
                  */}
                <PillButton
                  onClick={() => {
                    if (formSaving || autosaving || formMissing.length > 0) return
                    setSubmitPrompt(formCode)
                  }}
                  aria-disabled={formSaving || autosaving || formMissing.length > 0}
                  aria-describedby="office-form-state"
                  className={`min-w-28 ${
                    formSaving || autosaving || formMissing.length > 0
                      ? 'cursor-not-allowed opacity-60'
                      : ''
                  }`}
                >
                  {formSaving ? 'Submitting…' : 'Submit to this office'}
                </PillButton>
                {/*
                  "Back without saving" is gone — the client called it
                  unnecessary and autosave made it untrue, since leaving now
                  loses nothing. What replaced it is navigation rather than a
                  decision about saving. It could not simply be deleted: this
                  sheet REPLACES the clearance cards, and the only other mention
                  of the way out is a line of prose at the top, so an applicant
                  on an incomplete sheet with a shut Submit would have no
                  control to leave by.
                */}
                <button
                  type="button"
                  onClick={() => {
                    setFormCode(null)
                    setFormError(null)
                  }}
                  className="text-sm font-semibold text-ink-secondary underline underline-offset-2 hover:text-ink"
                >
                  Back to clearances
                </button>
              </div>
              <p id="office-form-state" className="max-w-md text-xs text-ink-muted">
                {/*
                  The saved indicator, in the same three states the wizard's
                  header uses. It is what makes autosave trustworthy: an
                  applicant who is never told their typing was kept has no
                  reason to believe it, and will keep looking for a Save button.
                */}
                {autosaving
                  ? 'Saving your answers…'
                  : formDirty
                    ? 'Your answers are being saved automatically.'
                    : 'Your answers are saved automatically. You can leave and come back.'}
                {formMissing.length > 0 && (
                  <>
                    {' '}
                    This office will not receive the form until you fill in:{' '}
                    <span className="font-semibold text-ink">{formMissing.join(', ')}</span>.
                  </>
                )}
              </p>
            </>
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
      {/*
        The sentence said "Apply adds that office's fee to your balance due",
        which stopped being true when the bill moved to submission. Both routes
        cost nothing now — the difference between them is what the office reads,
        a form you fill in or a certificate you already hold — so that is what
        the line says.
      */}
      <p className="mb-5 max-w-3xl text-sm text-ink-secondary">
        Every one of these is required, and all five were covered by the payment you have already
        made. <span className="font-semibold text-ink">Apply</span> opens that office&rsquo;s own
        form for you to fill in;{' '}
        <span className="font-semibold text-ink">Upload an existing copy</span> hands them a
        certificate you already hold. Neither costs anything further.
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
          /*
           * "Applied ✓" on the button, from the clearance's own status.
           *
           * This read `state === 'applied' || state === 'issued'`, two names the
           * server no longer sends, so the button said "Apply" forever — on a
           * card whose clearance was already with its office, next to an Apply
           * that would re-open the form and do nothing else.
           */
          /*
           * "Applied" means the applicant chose this route, not that the office
           * has it.
           *
           * It read `clearanceStarted(row.state)`, which worked while Apply
           * moved the permit straight to For Approval. Submitting is its own act
           * now, so a clearance applied for and not yet filled in stays
           * `not_started` — and keying off the state would have put the button
           * back to "Apply" on a card the applicant had already opened, hiding
           * the one thing they still have to do.
           *
           * `mode` is the honest signal: it is set the moment Apply is pressed
           * and says which of the two routes was taken.
           */
          const applied = row.mode === 'apply' || clearanceStarted(row.state)
          /*
           * Has this clearance gone to its office?
           *
           * The line the client drew: "We do not promote any editing of forms
           * once submitted." Past `not_started` the office has it — except
           * `returned`, which is the office handing it back and asking for
           * changes, so that one is the applicant's again.
           *
           * Two controls turn off here, and the second is the one that was
           * actually broken. Apply becomes a read-only View. And SUBMIT — the
           * upload-a-copy route — stops being offered at all: it was gated only
           * on the stage being unlocked, so on a permit the office had already
           * accepted it stayed live, took a file, and was refused by the server
           * afterwards ("the office has already started on your …"). Offering a
           * control the server will refuse is CLR-4, by name, on this screen.
           */
          const handedIn = clearanceStarted(row.state) && row.state !== 'returned'
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
                ── The price came off these cards, for the second time ─────────

                It was here because Apply used to be the moment of commitment:
                `ClearanceService::apply` re-ran `FeeCalculator::assess`, each
                press moved `balance_due`, and there was nowhere downstream to
                read the price before agreeing to it.

                None of that is true under the client's verified flow.
                `assessFees()` runs ONCE, at submission, over the business
                permit and all five clearances; `apply()` no longer touches the
                assessment at all. The register's filing 5 says it plainly —
                total assessed ₱9,373.25, total paid ₱9,373.25, balance ₱0.00 —
                so the number on the card was quoting a charge that is never
                coming.

                Worse than redundant, it read as a threat: an applicant who has
                already paid in full, looking at "Fee ₱735.00" above an Apply
                button, has every reason to believe pressing it costs them
                another ₱735. The client: "Would displaying the fee still matter
                because we have already paid it for that beforehand, right? If
                not, kindly remove it."

                The breakdown itself is not lost — the Tax Order of Payment is
                where a per-permit figure belongs, and it is reachable from the
                filing. What is gone is the claim that this button spends money.
              */}

              {/*
                One line, and only for the cards it is true of: pressing Apply
                opens that office's own form. That is the single thing about
                this card a person cannot work out by looking at it.

                It read "Adds its own application form section above" while the
                sheets were steps of the wizard sitting behind the cards. There
                is no "above" now — the sheet opens over this grid — so it says
                what actually happens.
              */}
              {/*
                ── The outstanding form, said out loud ─────────────────────────
                *
                * `office_form_complete` has been on this payload since the
                * stage was built and nothing on the screen read it. What that
                * cost, reported 9 September 2026: an applicant pressed Apply,
                * the sheet opened, they left without saving, and the card went
                * on reading "Applied ✓" — a finished-looking state over an
                * unfinished one. Their CENRO officer opened the filing and
                * found the clearance applied for with no answers on it, which
                * is the state this card was quietly manufacturing.
                *
                * Two of the five clearances on the register's filing 5 were in
                * exactly that state (ZONING and CEC) against one that was
                * complete (SANITARY), so it is the common case rather than an
                * edge.
                *
                * Only ever shown on a clearance the applicant has STARTED —
                * before that, "your form is not filled in" would be telling
                * somebody off for not having done something they have not been
                * asked to do yet.
                */}
              {row.has_office_form && applied && !row.office_form_complete && (
                <p className="mt-1 text-xs font-semibold text-s-orange-ink">
                  Your form is not filled in yet. Open it and press Save.
                </p>
              )}
              {row.has_office_form && (!applied || row.office_form_complete) && (
                <p className="mt-1 text-xs text-ink-muted">
                  {row.office_form_complete
                    ? 'Your form is saved. Press below to read or change it.'
                    : 'Applying opens this office’s own form.'}
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
                ── CLR-1's Withdraw control, and why it is no longer drawn ──────

                It sat here, gated on `row.state === 'applied'`, as the way back
                out of Apply — the one control the client left standing when
                everything else came off this card.

                There is nothing left for it to do. `ClearanceService::unapply`
                throws for any permit with `isRequiredClearance()`, and since
                6 September 2026 all five clearances on this grid are required:
                the application cannot be approved without them, and detaching
                one would leave a paid filing that can never complete. Market
                Clearance was the only optional one and it was removed with the
                same change.

                So the control could only ever produce "‹clearance› is required
                on every application and cannot be withdrawn" — offering a
                control the server will refuse, which is CLR-4 by name. It had
                stopped rendering by accident already (`applied` is not a state
                the server sends any more), and rendering it again while
                correcting that would have turned a silent absence into a live
                dead end.

                If a genuinely optional clearance is ever added, bring this back
                gated on that permit's own optionality — not on its state.
              */}

              {/*
                ── `mt-auto`, not `flex-1`, and the difference is the whole bug ─

                The row carried BOTH `flex-1` and `items-stretch`. `flex-1` made
                it absorb whatever vertical space the card had left over, and
                `items-stretch` then made the buttons fill that — so a card with
                less text above it (Fire Safety, Occupancy: one short line)
                handed its buttons far more height than a card with three lines
                of warning above them. Five cards, five button heights, which is
                what the client saw.

                `mt-auto` pushes the row to the bottom of the card WITHOUT
                growing it, so every card's buttons sit on the same baseline and
                are sized by their own content. `items-stretch` stays, and now
                does only the job it was added for: making the two buttons in one
                row match EACH OTHER when one label wraps.
              */}
              <div className="mt-auto flex items-stretch gap-2.5 pt-5">
                {/*
                  Both buttons stay in the tab order when the stage is shut.
                  `disabled` drops a control out of the tab order and most
                  screen readers pass over it, so an applicant using one would
                  never learn the button exists or why it does nothing.
                  aria-disabled says so instead, and the locked reason above is
                  what it points at.
                */}
                {/*
                  ── Not offered once the office has it ──────────────────────
                  *
                  * This button was gated on `unlocked` alone — nothing about
                  * whether the clearance had been handed in. So on a permit the
                  * office had already accepted it stayed live, opened the
                  * upload box, took the applicant's file, and only THEN was
                  * refused by `storeHeld`: "the office has already started on
                  * your …, so it can't be swapped."
                  *
                  * Removed rather than disabled. There is nothing conditional
                  * about it — a submitted clearance can never take a swap — and
                  * a permanently dead button in the layout is furniture, which
                  * is precisely what this card has twice been stripped of.
                  * `View form` beside it is the control that still means
                  * something here.
                  */}
                {!handedIn && (
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
                      ? `Replace the ${row.permit_type.name} copy you uploaded`
                      : `Upload an existing copy of the ${row.permit_type.name}`
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
                  {/*
                    "Submit" said nothing about what it did, and sat beside a
                    sheet whose own button also says Submit — one meaning "hand
                    the office my answers", this one meaning "hand them a
                    certificate I already hold". The client: "instead of
                    'Submit' why not 'Upload an existing copy' to avoid
                    confusion?"

                    Shortened to two words after that. "Upload an existing copy"
                    wrapped to two lines in a half-card button and left the pair
                    uneven — these two sit side by side and have to read as one
                    choice, which they cannot do at different heights. "Upload"
                    and "copy" are the two words carrying the meaning; the full
                    phrase survives in the accessible name below, where length
                    costs nothing.
                  */}
                  {held ? 'Copy uploaded' : 'Upload a copy'}
                </button>
                )}
                <button
                  type="button"
                  disabled={busy}
                  aria-disabled={!unlocked}
                  aria-describedby={buttonDescribedBy}
                  /* Named for its clearance — see the Submit button above. */
                  aria-label={
                    handedIn
                      ? `View the ${row.permit_type.name} form you submitted`
                      : applied
                        ? `Finish the ${row.permit_type.name} form — you applied but have not submitted it`
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
                  {/*
                   * "Applied ✓" is a label for a finished thing, and on a
                   * clearance whose form has never been saved it was the wrong
                   * one: the applicant HAS applied, but the office has nothing
                   * to read. The tick claimed the opposite.
                   *
                   * So the label follows the work rather than the transaction.
                   * The button's behaviour is unchanged — it has always
                   * reopened the sheet, and its `aria-label` has always said so
                   * — this is the visible half catching up with it.
                   */}
                  {/*
                   * Three states, and the third is the client's asked-for
                   * "button which will allow them to see what they have
                   * submitted". "Applied ✓" was a label where a control was
                   * needed: it read as a finished status on a card whose form
                   * might be empty, and said nothing about being pressable.
                   */}
                  {busy
                    ? 'Working…'
                    : handedIn
                      ? 'View form'
                      : applied
                        ? 'Finish form'
                        : 'Apply'}
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
      {/* ── UPLOAD AN EXISTING COPY · a clearance already held ───────────── */}
      {heldPrompt && (
        <ProtoModal
          title="UPLOAD AN EXISTING COPY"
          cancelLabel="Cancel"
          /*
            Named for the act, like the button that opens it. The title read
            "SUBMISSION" and the confirm read "Submit", which on this screen is
            now the word for handing an office your ANSWERS — the sheet's own
            button. Two different acts cannot share a verb on one page.
          */
          confirmLabel="Upload this copy"
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
            CLR-1 — what changing your mind actually does, before it is done.

            The client's report was *"I cannot remove my application on the
            Zoning/Locational Clearance once I changed my mind to Submit instead
            of Apply."* This is where they changed their mind, so this is where
            the switch is offered and stated: the two halves of the card are
            alternatives, and until now only one direction resolved itself.

            Stated plainly rather than as a warning. Nothing is destroyed —
            withdrawing detaches a permit type, the filing is re-assessed
            without it, and the office sheet's answers are kept — so a red panel
            here would make a free, reversible change look like the deletion
            happening in the OTHER dialog, which really is one.
          */}
          {clearanceStarted(heldPrompt.state) && heldPrompt.held_document === null && (
            <div className="mt-4 rounded-md border border-blue-200 bg-blue-50 px-3.5 py-3">
              <p className="text-sm font-semibold text-blue-900">
                You applied for this one. Submitting your own copy replaces that.
              </p>
              {/*
                Rewritten with the swap. The old copy promised a withdrawal —
                the permit type coming off the filing and its fee off the
                balance — which is what the two-step used to do and what
                `unapply` now refuses outright for a required clearance. The
                permit stays on the filing; `storeHeld` changes its `mode` from
                apply to upload, and the one bill raised at submission covered
                it either way.
              */}
              <p className="mt-1 text-xs leading-relaxed text-blue-800">
                {heldPrompt.permit_type.department?.name ?? 'The issuing office'} will read your
                copy instead of the application you started
                {heldPrompt.has_office_form ? ', so its form section closes' : ''}. Nothing you have
                typed into that form is deleted — press Apply again and it is still there. Your fees
                do not change.
              </p>
            </div>
          )}
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
            never both. Your fees do not change either way — they were settled when you paid.
          </p>
        </ProtoModal>
      )}

      {submitPrompt && (
        /*
          ── The last look before a one-way press ─────────────────────────────

          The client asked for it by name: "before submitting each form, please
          create a modal that will ask them if they are already finished
          reviewing before submitting."

          It earns its place on the same test the two dialogs above pass —
          something happens here that cannot be undone from this screen. Once
          submitted the sheet is the office's and the applicant cannot change
          it; getting it back means messaging the office and asking them to
          return it. Until today that press was the same size as saving a draft.

          Blue, not red. Nothing is destroyed and nothing is wrong — this is the
          applicant doing the thing they came to do, and dressing it as a
          warning would say otherwise. What the dialog adds is the one fact the
          button cannot: that this is the last moment to change anything.
        */
        <ProtoModal
          title="SUBMIT THIS FORM"
          cancelLabel="Keep checking"
          confirmLabel="Yes, submit it"
          onCancel={() => setSubmitPrompt(null)}
          onConfirm={() => {
            setSubmitPrompt(null)
            void saveForm()
          }}
        >
          <p className="text-center text-base text-ink">
            Have you finished reviewing your{' '}
            <span className="font-bold">
              {rows?.find((r) => r.permit_type.code === submitPrompt)?.permit_type.name ??
                'application form'}
            </span>
            ?
          </p>
          <p className="mt-3 text-center text-sm text-ink-secondary">
            Once you submit it,{' '}
            <span className="font-semibold text-ink">
              {rows?.find((r) => r.permit_type.code === submitPrompt)?.permit_type.department
                ?.name ?? 'the issuing office'}
            </span>{' '}
            receives it and you will not be able to change your answers. You can still read them
            back at any time.
          </p>
          <p className="mt-3 text-center text-sm text-ink-secondary">
            If you spot a mistake after submitting, message the office from this clearance&rsquo;s
            card and they can send the form back to you.
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
  const profile = application.fee_profile ?? null
  const owner = b?.owner ?? null
  /*
   * The paper prints one box per question; the register can hold several lines
   * of business on one filing. Joined rather than truncated to the first, so a
   * business declaring three trades hands CENRO all three — losing two of them
   * silently is how a sheet comes back for correction.
   */
  const joinLines = (pick: (l: NonNullable<typeof line>) => string | null | undefined): string =>
    (b?.lines ?? [])
      .map((l) => (pick(l) ?? '').trim())
      .filter(Boolean)
      .join('; ')

  const carriedOver: CarriedOverBusiness = {
    name: businessName(b),
    tradeName: b?.trade_name ?? '',
    address:
      [b?.address?.line1, b?.address?.line2, b?.address?.barangay?.name]
        .filter(Boolean)
        .join(', ') || '—',
    lineOfBusiness: line?.line_of_business?.trim() || line?.psic_code?.title || '—',
    // MCG-CENRO-FO-001's Ownership and Documentation block. See the type.
    registrationType: REGISTRATION_TYPE_LABELS[b?.registration_type ?? ''] ?? '',
    ownerName:
      [owner?.surname, owner?.given_name, owner?.middle_name, owner?.suffix]
        .map((part) => (part ?? '').trim())
        .filter(Boolean)
        .join(', ') || '',
    ownerSex: SEX_LABELS[owner?.gender ?? ''] ?? '',
    productsServices: joinLines((l) => l.products_services),
    landline: b?.address?.telephone ?? '',
    mobile: b?.address?.mobile_number ?? '',
    businessAreaSqm: profile?.floor_area_sqm != null ? String(profile.floor_area_sqm) : '',
    maleEmployees: profile?.male_employees != null ? String(profile.male_employees) : '',
    femaleEmployees: profile?.female_employees != null ? String(profile.female_employees) : '',
    // MCG-CPDD-FO-003's numbered items. See the type.
    proprietorName:
      (b?.president_officer_name ?? '').trim() ||
      [owner?.given_name, owner?.middle_name, owner?.surname, owner?.suffix]
        .map((part) => (part ?? '').trim())
        .filter(Boolean)
        .join(' '),
    proprietorContact: b?.address?.mobile_number ?? b?.address?.telephone ?? '',
    proprietorEmail: b?.address?.email ?? '',
    /*
     * The paper's items VIII.C and VIII.D — the lessor's name and address — are
     * NOT built here. They are derived server-side into the sheet's `form_data`
     * (`OfficeFormAnswers::derive`), because the officer's review screen renders
     * `form_data` and nothing else: a lessor that exists only on the applicant's
     * side is a lease contract CPDD cannot check the sheet against.
     */
    /*
     * Item V, "Activity (please specify)". The PSIC title says what CATEGORY
     * the trade is; the products say what it actually does. CPDD is judging a
     * USE, so both together are the answer, and joining beats picking.
     */
    activity:
      [line?.line_of_business?.trim() || line?.psic_code?.title, joinLines((l) => l.products_services)]
        .filter(Boolean)
        .join(' — '),
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
