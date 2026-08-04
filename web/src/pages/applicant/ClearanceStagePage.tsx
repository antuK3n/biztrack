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
import type { Application, Clearance, ClearanceMeta, ClearanceState } from '../../lib/types'

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

/** The five states the API reports, and how each is worn on the card. */
const STATE_META: Record<ClearanceState, { label: string; className: string }> = {
  available: { label: 'Not requested', className: 'border-input-border bg-white text-ink-muted' },
  applied: { label: 'Applied for', className: 'border-royal/40 bg-royal-tint text-royal' },
  submitted: { label: 'Copy on file', className: 'border-s-green/40 bg-s-green/10 text-s-green' },
  issued: { label: 'Issued', className: 'border-s-green/40 bg-s-green/10 text-s-green' },
  rejected: { label: 'Refused', className: 'border-s-red/40 bg-s-red/10 text-s-red' },
}

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
  MARKET:
    'Only for a business trading from a stall in a public or private market. If your premises is not a market stall, leave this one — nothing in this application asks for it.',
}

/**
 * What this clearance costs, in a sentence.
 *
 * Two traps here, both about money.
 *
 * `fee_preview` arrives ALREADY FORMATTED — "₱735.00" — because
 * `PermitFees::peso` puts the sign on server-side. It is interpolated, never
 * passed through formatMoney(): Number("₱735.00") is NaN, and formatMoney
 * answers "₱0.00" for that. A card quoting a free sanitary clearance next to a
 * button that charges ₱660 for one is the worst thing this screen could do.
 *
 * And its meaning flips with the state. The server compares against the filing
 * WITHOUT the clearance once it has been applied for, so the same field is
 * "what applying would add" beforehand and "what this is costing you"
 * afterwards. One sentence for both would be wrong half the time.
 *
 * Null is not zero. It is the market stall rental, which the office sets case
 * by case, or a filing whose business record is gone and so cannot be priced.
 */
export function feeSentence(preview: string | null, applied: boolean): string {
  if (preview === null) {
    return applied
      ? 'This office sets its fee case by case. It appears on your Tax Order of Payment once assessed.'
      : 'Applying adds a fee this office sets case by case. It is quoted on your Tax Order of Payment before you pay it.'
  }
  /*
   * A zero preview is not the same claim as a priced one, and it must not be
   * dressed as one.
   *
   * `feePreview` returns `PermitFees::peso(max(0, $delta))`, so a clearance
   * with no matching revenue-code rule comes back as the STRING "₱0.00" rather
   * than the null the contract reserves for "the office sets it". The Market
   * Clearance is exactly that case — the spec says its stall rental is
   * officer-set — and "Applying adds ₱0.00" would read as a promise that it is
   * free. It is not a promise anyone made; it is the assessment having no rule
   * to price it with.
   *
   * So the sentence says what is actually known: the assessment adds nothing.
   * It does not say the clearance is free, and it does not invent a fee.
   */
  if (Number(preview.replace(/[^0-9.]/g, '')) === 0) {
    return applied
      ? 'The assessment carries no fee for this clearance.'
      : 'Applying adds nothing to your assessment. If this office charges, it sets that separately.'
  }
  return applied
    ? `${preview} of your Tax Order of Payment is this clearance.`
    : `Applying adds ${preview} to your Tax Order of Payment.`
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
        `Applied for your ${row.permit_type.name}. Its fee is on the Tax Order of Payment you will be given when you submit.`,
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

  /** Withdraw a request. Its own labelled control — never a second Apply. */
  async function onUnapply(row: Clearance) {
    await runAction(
      row.permit_type.code,
      `Withdrew your ${row.permit_type.name} application. Nothing for it will be charged.`,
      () => clearances.unapply(applicationId, row.permit_type.code),
    )
  }

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

      <p className="mb-2 max-w-3xl text-sm text-ink-secondary">
        <span className="font-semibold text-ink">Apply</span> asks that office to issue the
        clearance, and its fee joins your Tax Order of Payment. Already hold a valid one?{' '}
        <span className="font-semibold text-ink">Submit</span> a copy instead — nothing is charged,
        because nothing is being issued.
      </p>
      <p className="mb-5 max-w-3xl text-sm text-ink-secondary">
        You are billed once, after you submit, for the business permit and everything chosen here.
      </p>
      {/*
        Item 98, said once at the top and again on the card it is about.

        Six identical cards are read as six obligations, and the tester read
        them that way — the Market Clearance is for stall holders and was
        sitting there as an equal of the other five. Nothing forces it (see
        APPLICABILITY), so what was missing was the sentence saying so.
      */}
      <p className="mb-5 max-w-3xl text-sm text-ink-secondary">
        Choose the ones your business needs — you do not need all six. Where a clearance is only for
        a particular kind of premises, its card says who it is for.
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

      <ul className="grid list-none gap-5 p-0 sm:grid-cols-2 xl:grid-cols-3">
        {rows.map((row) => {
          const code = row.permit_type.code
          const busy = busyCode === code
          const state = STATE_META[row.state] ?? STATE_META.available
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
              <div className="flex items-start justify-between gap-3">
                <p className="text-lg font-bold leading-snug text-ink">{row.permit_type.name}</p>
                <span
                  className={`shrink-0 rounded-full border px-2.5 py-0.5 text-[11px] font-semibold ${state.className}`}
                >
                  {state.label}
                </span>
              </div>
              <p className="display-serif mt-2 text-sm italic text-ink-secondary">
                {/* The department relation is nullable server-side. */}
                {row.permit_type.department?.name ?? 'Issuing office'}
              </p>

              {/*
                Who the clearance is for, above the fee and well above the
                buttons — it is the question that decides whether the rest of
                the card is addressed to this applicant at all.

                Neutral styling, not a warning. Nothing is wrong and nothing is
                blocked: a stall holder reads it and applies. Dressed in the red
                the refusal panel uses, it would say the applicant had already
                made a mistake by looking at the card.
              */}
              {appliesTo && (
                <p
                  id={appliesToId}
                  className="mt-3 rounded-md border border-input-border bg-input/60 px-3 py-2 text-xs text-ink-secondary"
                >
                  <span className="font-semibold text-ink">Who this is for: </span>
                  {appliesTo}
                </p>
              )}

              {/*
                What each button costs, on the card, before either is pressed.
                Apply and Submit sit side by side and look alike; one of them
                spends money and the other does not, and that is not something a
                card may leave the applicant to find out afterwards on their Tax
                Order of Payment.
              */}
              <p className="mt-3 text-xs text-ink-secondary">
                {feeSentence(row.fee_preview, applied)}{' '}
                <span className="text-ink-muted">
                  Submitting a copy you already hold costs nothing.
                </span>
              </p>

              {applied && (
                <div className="mt-3 rounded-md border border-royal/30 bg-royal-tint px-3 py-2">
                  <p className="text-xs font-bold text-royal">
                    {row.state === 'issued' ? 'Issued by this office' : 'Applying for this clearance'}
                  </p>
                  <p className="mt-1 text-xs text-ink-secondary">
                    {row.has_office_form
                      ? row.office_form_complete
                        ? 'Its form is filled in. Apply reopens it whenever you need it.'
                        : 'Its form still needs finishing — Apply opens it.'
                      : 'No extra form — this office works from your application.'}
                  </p>
                  {/*
                    Withdrawing used to be a second click on Apply, which made
                    one button mean two opposite things. Stated plainly instead,
                    and kept away from Apply so neither is hit by accident. Not
                    offered once the office has issued it: there is nothing left
                    to withdraw. Hidden once the stage is shut, because there is
                    no longer anything this control can do.
                  */}
                  {row.state === 'applied' && unlocked && (
                    <button
                      type="button"
                      onClick={() => void onUnapply(row)}
                      disabled={busy}
                      className="mt-1 text-xs font-semibold text-s-red underline underline-offset-2 disabled:opacity-60"
                    >
                      {busy ? 'Working…' : `Don’t apply for the ${row.permit_type.name}`}
                    </button>
                  )}
                </div>
              )}

              {row.state === 'rejected' && (
                <div className="mt-3 rounded-md border border-s-red/40 bg-s-red/10 px-3 py-2">
                  <p className="text-xs font-bold text-s-red">This office refused it</p>
                  <p className="mt-1 text-xs text-ink-secondary">
                    {row.assignment?.remarks ?? 'No reason was recorded.'}
                  </p>
                </div>
              )}

              {held && (
                <div className="mt-3 rounded-md border border-s-green/40 bg-s-green/10 px-3 py-2">
                  <p className="flex items-center gap-1.5 text-xs font-bold text-s-green">
                    <CheckIcon size={13} /> On file, not applied for
                  </p>
                  <p className="mt-1 truncate text-xs text-ink-secondary" title={held.name}>
                    {held.name} · {formatBytes(held.size)}
                  </p>
                  {/*
                    Removing has its own control, as it did in the wizard.
                    Clicking "Submitted ✓" used to delete the file that had just
                    been uploaded, with no confirmation and no undo — destroying
                    something must never be the alternate meaning of the button
                    that created it.
                  */}
                  {unlocked && (
                    <button
                      type="button"
                      onClick={() => void onRemoveHeld(row)}
                      disabled={busy}
                      className="mt-1 text-xs font-semibold text-s-red underline underline-offset-2 disabled:opacity-60"
                    >
                      {busy ? 'Removing…' : `Remove the ${row.permit_type.name} copy`}
                    </button>
                  )}
                </div>
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
                  Submit a copy
                </button>
                <button
                  type="button"
                  disabled={busy}
                  aria-disabled={!unlocked}
                  aria-describedby={buttonDescribedBy}
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
                  {busy ? 'Working…' : 'Apply'}
                </button>
              </div>
            </li>
          )
        })}
      </ul>

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

      <ClearanceStage applicationId={application.id} business={carriedOver} />
    </div>
  )
}
