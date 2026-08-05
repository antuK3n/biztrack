import { useCallback, useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { CheckIcon, InfoCircleIcon, UploadIcon } from '../../components/icons'
import { Alert } from '../../components/ui/Alert'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { PillButton, ProtoModal } from '../../components/ui/Proto'
import { businessName, formatBytes } from '../../lib/format'
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
 * ── LGU Clearances · the last thing decided before Review & Submit ─────────
 *
 * Settled with the client on 4 August 2026; the reasoning is in
 * docs/clearances-before-payment.md and this screen is the half the applicant
 * sees. Checklist item 76 asked for the six "at the last part before submitting
 * the application", and that is now literally where they are: the wizard's step
 * 6 of 7, with each chosen clearance's office sheet slotted in behind it.
 *
 * This lived for one day as a stage that opened AFTER the first payment, with a
 * running balance, a second payment and a gate holding the permit until it
 * cleared. All of that is deleted. Two things about it are worth remembering
 * rather than rediscovering:
 *
 *   The balance block that used to sit above these cards is gone because
 *   nothing accrues. Every clearance chosen here is billed on the one Tax
 *   Order of Payment assessed at submit, so a ledger on this screen would only
 *   ever read zero — and a zero that really means "not assessed yet" reads as
 *   "these are free".
 *
 *   `fee_preview` stays, and matters more than the ledger ever did. It is what
 *   this clearance will ADD to that Tax Order of Payment, quoted before the
 *   button is pressed.
 *
 * Two properties of this screen are load-bearing rather than stylistic:
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
 *      office's fee to what the applicant will be charged; Submit costs
 *      nothing, because nothing is being issued. A card that showed them as a
 *      matched pair without saying so would be hiding the only difference that
 *      matters.
 *
 *   3. Six cards side by side read as six things you are supposed to do. They
 *      are not: the step's rule is that ONE of them is decided, and no card is
 *      individually required. Where a clearance is only for a particular kind
 *      of premises, the card has to say so on its face — see APPLICABILITY
 *      below and checklist item 98.
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
 * Who a clearance is for, where the answer is not "every business".
 *
 * Checklist item 98: *"Market clearance should not be required. It is only
 * required for stall holders."*
 *
 * Taken literally, nothing required it, and that was checked before writing
 * anything. The step passes on `anyClearanceDecided` — ANY ONE of the six, and
 * never a named one — and no other path singles the Market Clearance out:
 * `ClearanceService::clearanceTypes` returns every non-BUSINESS permit type
 * with no per-code rule, submission validates none of them individually, and
 * the one MARKET-conditional question in the wizard (the stall count on the
 * Business & Tax Profile step) is gated on a permit-code list every call site
 * fills with BUSINESS alone, so it never fires. An applicant can reach Review &
 * Submit having never touched this card.
 *
 * So the complaint is about the screen, not the rule. Six cards laid out
 * identically, each with the same two buttons and the same fee sentence, read
 * as six obligations — and the Market Clearance is the one of the six that most
 * businesses genuinely have no business applying for. The card said nothing
 * about that, and its fee sentence ("Applying adds nothing to your assessment")
 * made it look like the cheap, harmless one to tick.
 *
 * The fix is to say who it is for, not to hide it. Hiding it would decide
 * `docs/questions-for-malabon.md` A1 — whether the applicant chooses the six or
 * BPLO determines them from the line of business and the location — by guessing
 * that the system determines them, and we would be guessing it from data we do
 * not collect: nothing in the wizard asks whether the premises is a market
 * stall. An LGU that seeds this permit type wants its office on the screen; a
 * greengrocer with a shopfront wants to know the card is not addressed to them.
 * One sentence on the card does both.
 *
 * Keyed by code and deliberately sparse. A clearance with no entry is one that
 * any business may need, which is the honest default and the same way an LGU's
 * seventh seeded clearance behaves — it renders, with no note, rather than
 * inheriting a claim nobody made about it.
 */
const APPLICABILITY: Record<string, string> = {
  /*
   * One line, down from three sentences. The second and third ("If your
   * premises is not a market stall, leave this one — nothing in this
   * application asks for it") were telling the applicant how to read the
   * screen, which the screen now does for itself: the card is not on the grid
   * unless the filing says it belongs there, so anyone reading this went
   * looking for it and does not need talking out of it.
   */
  MARKET:
    'Optional — only if you trade from a stall in a public or private market. Skip it if you do not.',
}

/**
 * The revenue-code categories that say, in the applicant's own declaration,
 * that this filing is about a market.
 *
 * Not a list invented for this screen: every one of them is a `conditions.
 * business_category` value on a seeded FeeRule whose `basis` is `stall_count`
 * — garbage Schedule J's public-market rows, the privately-owned market
 * bracket, and the fish broker market bracket. So the same declaration that
 * decides whether the applicant is charged per stall decides whether they are
 * shown the stall clearance. Two screens disagreeing about who is a market
 * business would be worse than either answer.
 *
 * `fish_broker_market` is here and was not in the original three. It belongs
 * for the same reason the other three do — `permit.fish_broker_market_by_stalls`
 * is priced per stall off the same basis — and leaving it out would hide the
 * card from an operator the fee engine is already billing by the stall.
 */
export const MARKET_CATEGORIES = [
  'public_market_100_plus_stalls',
  'public_market_under_100_stalls',
  'private_market',
  'fish_broker_market',
]

/**
 * Whether the filing's own answers say the Market Clearance card belongs on
 * this applicant's screen — checklist item 98.
 *
 * *"Market clearance should not be required. It is only required for stall
 * holders."* An earlier pass answered that with a sentence on the card saying
 * who it was for, which was true and was not enough: the card was still one of
 * six laid out identically for every applicant in the city, and the great
 * majority of them have nothing to do with a public market. The client has
 * since asked for it to be derived rather than shown to everyone, and rather
 * than asked of everyone as a yes/no.
 *
 * The signal is available in time. The category and the stall count are both
 * declared on Business & Tax Profile, step 5, and the clearances are step 6.
 *
 * ── The reason this reveals and never restricts ────────────────────────────
 *
 * These categories describe a market OPERATOR, not a market STALL HOLDER. A
 * fishmonger renting one stall inside the Malabon Central Market is not a
 * "privately-owned public market" and will not have declared themselves one;
 * the operator of that market is. So a pure derivation shows the card to the
 * landlord and hides it from the tenant — which is precisely inverted from the
 * population the client named.
 *
 * That is why this function only ever ADDS the card, and why the stage keeps a
 * plain control for revealing it by hand. A derivation that is wrong about a
 * minority and can be overridden in one click costs that minority one click. A
 * derivation that is wrong and cannot be overridden costs them the clearance.
 *
 * Whether "stall holder" is even the right population — whether the operator
 * needs it too, or instead — is a question for BPLO and the City Market
 * Administrator, not one to settle by choosing a filter. See
 * docs/questions-for-malabon.md A13.
 */
export function marketClearanceApplies(
  categories: readonly string[],
  stallCount: number | string | null | undefined,
): boolean {
  if (categories.some((c) => MARKET_CATEGORIES.includes(c.trim()))) return true
  // A stall count typed at all is a claim about stalls. Number('') is 0, not
  // NaN, so the empty string falls through to false rather than to a truthy
  // "they answered something".
  const stalls = typeof stallCount === 'string' ? Number(stallCount.trim()) : stallCount
  return typeof stalls === 'number' && Number.isFinite(stalls) && stalls > 0
}

/**
 * What this clearance costs. The number, and as little around it as possible.
 *
 * *"There's an absurd amount of text here."* This used to return a full
 * sentence, in six variants — one for each combination of priced/free/unpriced
 * and applied/not — and it was printed on every card. Six cards each carrying
 * two sentences of identical fee rules is the wall the client was looking at.
 * The RULE (Apply costs, Submit does not) is now stated once above the grid,
 * where it belongs, because it is the same on all six. What is left here is the
 * one thing that actually differs between the cards, which is the amount.
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

interface ClearanceStageProps {
  applicationId: number
  /** The business as every office sheet carries it, for the sheets opened here. */
  business: CarriedOverBusiness
  /**
   * Where Apply sends the applicant when that office has a form sheet.
   *
   * Omitted on the standalone route, which renders the sheet inline over the
   * cards. Supplied by the wizard, where each sheet is a step of its own
   * slotted in behind this one — so Apply moves the wizard rather than swapping
   * what this component is drawing.
   */
  onOpenOfficeForm?: (code: OfficeFormCode) => void
  /**
   * The six rows, every time they change. The wizard needs them: which office
   * sheets exist as steps, and whether the step passes at all, are both read
   * off which clearances have been decided.
   */
  onRowsChange?: (rows: Clearance[]) => void
  /**
   * Whether the filing's own answers say this applicant is a market business —
   * `marketClearanceApplies()` above, item 98.
   *
   * Passed in rather than fetched because the two callers hold the declaration
   * in different shapes and at different freshnesses: the standalone route has
   * the SAVED `fee_profile` off the application, while the wizard has the
   * profile the applicant is still typing, one step back. Reading the saved
   * copy inside the wizard would miss a category entered thirty seconds ago and
   * not yet autosaved, which is exactly when it matters.
   *
   * Defaults to false — hidden — because that is the answer for almost every
   * business in the city, and a card shown by default is a card the applicant
   * has to work out is not addressed to them.
   */
}

/**
 * True when at least one clearance has been decided — checklist item 76's
 * other half, and the rule that makes this step a decision rather than a
 * screen to walk past.
 *
 * Either half of the card satisfies it. Apply means "issue me one"; Submit
 * means "I already hold this, here is the copy". They are opposites in what
 * they ask the office to do and identical in what they tell us — that this
 * clearance has been dealt with — so requiring one or the other, rather than
 * Apply specifically, is what stops the rule from forcing an applicant to
 * apply for a certificate already in their hand.
 *
 * `.some` and not a set of codes, and that is the whole of item 98's answer.
 * The rule has never named a clearance and must not learn to: a filing satisfies
 * it with Zoning alone, and the Market Clearance is only ever one of six ways to
 * satisfy it, never one of the six things needed. If this ever has to become
 * "these specific clearances, for this kind of business", that is BPLO deciding
 * the six rather than the applicant choosing them — A1 in
 * docs/questions-for-malabon.md, and not a thing to arrive at by tightening this
 * line.
 */
export function anyClearanceDecided(rows: Clearance[]): boolean {
  return rows.some((r) => r.state !== 'available' || r.held_document !== null)
}

/**
 * The cards, the lock, and every write behind them.
 *
 * Rendered in two places from one definition: the wizard's LGU Clearances step
 * and the standalone `/applications/:id/clearances` route below. They must not
 * drift — the Apply/Submit semantics are the kind of thing that grows a second,
 * subtly different copy the moment there are two of them.
 */
export function ClearanceStage({
  applicationId,
  business,
  onOpenOfficeForm,
  onRowsChange,
}: ClearanceStageProps) {
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

  const publish = useCallback(
    (next: Clearance[]) => {
      setRows(next)
      onRowsChange?.(next)
    },
    [onRowsChange],
  )

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError(null)
    try {
      const result = await clearances.list(applicationId)
      publish(result.data)
      setMeta(result.meta)
    } catch (err) {
      setLoadError(err)
    } finally {
      setLoading(false)
    }
  }, [applicationId, publish])

  useEffect(() => {
    void load()
  }, [load])

  /*
   * Saved office-form payloads, only when this component renders the sheets
   * itself. The wizard fetches its own, because there the sheets are steps it
   * owns and their answers ride on its autosave.
   */
  useEffect(() => {
    if (onOpenOfficeForm) return
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
  }, [applicationId, onOpenOfficeForm])

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
      publish(result.data)
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
  async function onApply(row: Clearance) {
    const code = row.permit_type.code
    if (!unlocked) return

    // Applying for it and already holding it are opposites (same as the wizard).
    if (row.held_document) {
      const ok = await runAction(
        code,
        `The copy of your ${row.permit_type.name} was removed, because you are applying for one instead.`,
        () => clearances.removeHeld(applicationId, code),
      )
      if (!ok) return
    }
    if (row.state === 'available' || row.state === 'submitted') {
      const ok = await runAction(
        code,
        `Applied for your ${row.permit_type.name}. Its fee joins your Tax Order of Payment.`,
        () => clearances.apply(applicationId, code),
      )
      if (!ok) return
    }
    if (hasOfficeForm(code)) {
      setFormError(null)
      if (onOpenOfficeForm) onOpenOfficeForm(code)
      else setFormCode(code)
    }
  }

  /*
   * The withdraw handler that stood here is gone with the control that called
   * it. `clearances.unapply` in lib/resources.ts is deliberately NOT deleted:
   * the endpoint is real, it works, and applying still commits money, so an
   * undo has to exist somewhere. It just does not belong on this card — see
   * docs/HANDOFF.md §15.2, and do not re-solve it by making Apply a toggle.
   */

  /** Take the uploaded copy back off. Its own labelled control — never Submit. */
  async function onRemoveHeld(row: Clearance) {
    await runAction(
      row.permit_type.code,
      `Removed the ${row.permit_type.name} copy you had uploaded.`,
      () => clearances.removeHeld(applicationId, row.permit_type.code),
    )
  }

  /** Send the copy chosen in the SUBMISSION dialog. Costs nothing. */
  async function onSubmitHeld(row: Clearance, file: File) {
    setHeldPrompt(null)
    setBusyCode(row.permit_type.code)
    setActionError(null)
    try {
      const result = await clearances.submitHeld(applicationId, row.permit_type.code, file)
      publish(result.data)
      setMeta(result.meta)
      setNote(`Your ${row.permit_type.name} copy is on file. Nothing was added to your fees.`)
    } catch (err) {
      // Upload failures arrive without a usable message twice over; translate.
      setActionError(uploadErrorMessage(err))
    } finally {
      setBusyCode((c) => (c === row.permit_type.code ? null : c))
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
   * ITEM 98 — which cards this applicant actually sees.
   *
   * Five of the six are for any business and are always here. The Market
   * Clearance is not: it is for a stall in a public or private market, and
   * showing it to the greengrocer with a shopfront is what made six cards read
   * as six obligations in the first place.
   *
   * Three things put it back on screen, and the third is the one that must not
   * be forgotten. A clearance ALREADY DECIDED stays visible whatever the
   * derivation says — a reopened draft whose category was since edited, or
   * whose reveal was clicked in a previous session, would otherwise lose a
   * choice the applicant had already made, silently, with the fee still on the
   * assessment. Hiding a decision is not the same as not offering it.
   *
   * `.filter` and not a separate render branch: the card, its buttons and its
   * whole state machine are one definition, and a second copy for the one
   * clearance that is conditional is how the two would drift apart.
   */
  /*
   * Every clearance is on the grid, Market included.
   *
   * It was hidden unless the declared revenue-code category or a stall count
   * implied market trade. Wrong instrument: the three categories it keyed on —
   * public_market_100_plus_stalls, public_market_under_100_stalls,
   * private_market — describe the operator who RUNS a market, not the trader
   * who rents one stall inside it. So the people the card exists for were
   * exactly the people it was hidden from, and they had no way to learn it
   * existed. A clearance nobody can find is worse than one they can see and
   * skip.
   *
   * Shown, labelled with who it is for (APPLICABILITY, tied to the buttons via
   * aria-describedby so it is heard before either is pressed), and optional —
   * which is what the step's rule already was: no single card is required.
   *
   * `marketShown` still computes above. It no longer gates the grid, but it
   * answers "does this look like market trade", and its reveal control stays
   * as the fallback for anyone the derivation misses.
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

  return (
    <div>
      {/*
        The lock, in the API's own words.

        `locked_reason` is shown verbatim. The condition that closes this stage
        is the server's to state — it knows what has been submitted and what has
        not — and a sentence written here would be a second, quieter version of
        the rule that drifted out of step with the real one the first time it
        moved.
      */}
      {!unlocked && (
        <div
          role="status"
          className="mb-6 flex gap-2.5 rounded-md border border-blue-200 bg-blue-50 px-3.5 py-3 text-sm text-blue-800"
        >
          <InfoCircleIcon size={20} className="mt-px shrink-0" />
          <div className="min-w-0">
            <p className="font-semibold">These can no longer be changed</p>
            {/*
              One copy of the sentence, and it is the one the buttons point at.
              An earlier version repeated it under the cards as the
              aria-describedby target, which put the same paragraph on screen
              twice — a sighted reader saw it duplicated and a screen-reader
              user heard it once as a banner and again on every button.
            */}
            <p id="clearances-locked" className="mt-0.5">
              {meta.locked_reason ?? 'This stage is no longer open.'}
            </p>
          </div>
        </div>
      )}

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
        can get materially wrong, so it is the one thing said in full. The
        sentences that went: "you are billed once after you submit" (true, and
        already the subject of the Review & Submit step that follows), and
        "where a clearance is only for a particular kind of premises, its card
        says who it is for" (which was an instruction for reading the screen
        rather than anything about the applicant's business — and is moot now
        that item 98 keeps the one such card off the grid entirely).
      */}
      <p className="mb-5 max-w-3xl text-sm text-ink-secondary">
        Choose the ones your business needs.{' '}
        <span className="font-semibold text-ink">Apply</span> adds that office&rsquo;s fee to your
        Tax Order of Payment; <span className="font-semibold text-ink">Submit</span> a copy of one
        you already hold costs nothing.
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
          const applied = row.state === 'applied' || row.state === 'issued'
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
                One line, and only for the cards it is true of: pressing Apply
                adds a section to the form above rather than finishing anything
                here. That is the single thing about this card a person cannot
                work out by looking at it.

                The fee used to print here. It went with the rest of the card's
                furniture on the client's instruction — the grid had grown a
                status chip, an amount, a tinted panel and three controls per
                card, and had stopped being scannable. The amount is not lost:
                every clearance's fee lands on the one Tax Order of Payment at
                Review & Submit, which is the screen where money is actually
                being agreed to.
              */}
              {row.has_office_form && (
                <p className="mt-2 text-xs text-ink-muted">
                  Adds its own application form section above.
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
                REMOVED on the client's instruction: the tinted panel that stood
                here once a clearance was applied for, and the withdraw button
                inside it.

                It held one sentence explaining what the Apply button does
                ("Its form still needs finishing — Apply opens it.") and a
                secondary button named "Don't apply for the <full clearance
                name>", which wrapped onto two lines on every card. Stacked with
                the badge and the amount, each card was carrying a status chip, a
                number, a bordered panel, a sentence about a button, and three
                controls — six of those on one grid. The client's words on seeing
                it: "WHAT THE FUCK IS THIS THE OLD ONE IS GOOD ENOUGH."

                The reasoning that put the withdraw control here has not been
                refuted and is worth keeping on the record: applying commits
                money, so it needs an undo, and that undo must not be a second
                press of Apply — one button meaning two opposite things is what
                caused the original bug where a second click silently un-applied
                and opened nothing. That constraint still holds. What changed is
                that this screen is no longer where the undo lives.

                So: Apply is now one-way FROM THIS CARD, and it never toggles.
                If an applicant needs to drop a clearance they have applied for,
                that has to exist somewhere — it is listed as open work in
                docs/HANDOFF.md §15.2. Do not solve it by making Apply a toggle
                again.
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
                  onClick={() => void onApply(row)}
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
        ITEM 98 — the way back to a card the derivation did not offer.

        The declaration this stage filters on names market OPERATORS, and the
        client's "stall holders" are their tenants. So the applicant this
        control exists for is not an edge case rounded off the end of the
        derivation — it is, quite possibly, most of the people who need this
        clearance. It has to be findable.

        It also has to stay quiet. The client's instruction was to derive rather
        than ask everyone a yes/no, and a prominent question above the cards
        would be exactly the yes/no they ruled out, asked of every applicant in
        the city. So it is one line of ordinary text below the grid, phrased as
        the question a stall holder is already asking when they get here and
        cannot find their office.

        The reveal is announced through the same live region as Apply and
        Submit. A card appearing silently at the end of a list is a change a
        screen reader has no reason to look for, and the applicant pressed a
        button precisely because they could not find that card.
      */}
      {/* ── SUBMISSION · a clearance already held ─────────────────────────── */}
      {heldPrompt && (
        <ProtoModal
          title="SUBMISSION"
          cancelLabel="Cancel"
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
    </div>
  )
}

/**
 * The standalone route, `/applications/:id/clearances`.
 *
 * The clearances are a wizard step now, so this is not where they are normally
 * chosen — it is how a filing that has left the applicant's hands shows what
 * was chosen, and says in the server's own words why it can no longer change.
 * It stays writable for a draft, because a draft opened at this address is
 * genuinely still open and refusing it would be a lie the API does not tell.
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
        Item 98's derivation, from the SAVED profile — which is the right one
        here. This route is how a filing that has left the applicant's hands
        shows what was chosen, so the declaration on record is the declaration
        that matters. The wizard passes the one being typed instead.
      */}
      <ClearanceStage applicationId={application.id} business={carriedOver} />
    </div>
  )
}
