import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { CheckIcon, InfoCircleIcon, UploadIcon } from '../../components/icons'
import { Alert } from '../../components/ui/Alert'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { PillButton, ProtoModal } from '../../components/ui/Proto'
import { businessName, formatBytes, formatMoney } from '../../lib/format'
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
 * ── LGU Clearances · the stage after the first payment ─────────────────────
 *
 * Decided with the client on 3 August 2026; the reasoning is written up in
 * docs/clearances-after-payment.md and this screen is the half of it the
 * applicant sees.
 *
 * The six clearances used to be step 4 of 8 inside the apply wizard, and the
 * two steps after them were computed from the answer — the required documents
 * were the union of the document types on the selected permit types, and the
 * tax profile's questions varied by permit code. That data dependency is why
 * they could not simply be moved later.
 *
 * The dependency only existed because the clearances were being treated as part
 * of the same filing. They are not. Each is a separate transaction with a
 * separate office, a separate fee and a separate outcome. Once they are their
 * own stage the dependency dissolves: the wizard's documents and fees describe
 * the business permit alone, and each clearance carries its own.
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
 *   2. The two buttons have very different financial consequences. Apply adds
 *      that office's fee to a balance the applicant must clear before their
 *      permit is released; Submit costs nothing, because nothing is being
 *      issued. A card that showed them as a matched pair without saying so
 *      would be hiding the only difference that matters.
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
function feeSentence(preview: string | null, applied: boolean): string {
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
   * officer-set — and "Applying adds ₱0.00 to your balance" would read as a
   * promise that it is free. It is not a promise anyone made; it is the
   * assessment having no rule to price it with.
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
    ? `${preview} of your balance is this clearance.`
    : `Applying adds ${preview} to your balance.`
}

export function ClearanceStagePage() {
  const { id = '' } = useParams()
  const appId = Number(id)

  const app = useAsync<Application>(() => applications.get(appId), [appId])

  /* The six rows and the money. Reloaded whole after every mutation — see the
   * note on `clearances` in resources.ts for why a single row is not enough. */
  const [rows, setRows] = useState<Clearance[] | null>(null)
  const [meta, setMeta] = useState<ClearanceMeta | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<unknown>(null)

  /* Which card is mid-request; only its own controls go quiet. */
  const [busyCode, setBusyCode] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)

  /*
   * What just happened to the money, announced rather than only drawn.
   *
   * Applying moves the balance. A number that changes silently is invisible to
   * a screen reader: the applicant hears the button, then nothing, and has no
   * way to know they have just committed to ₱735 they cannot see. The balance
   * block below is a role="status" region so the figures themselves are
   * announced, and this sentence names the change that caused them to move.
   */
  const [balanceNote, setBalanceNote] = useState('')

  /* The card whose SUBMISSION dialog is open. Submit always opens this. */
  const [heldPrompt, setHeldPrompt] = useState<Clearance | null>(null)
  const [heldPromptFile, setHeldPromptFile] = useState<File | null>(null)
  const [heldPromptError, setHeldPromptError] = useState<string | null>(null)

  /* The office form sheet on screen, if any. Apply always opens this. */
  const [formCode, setFormCode] = useState<OfficeFormCode | null>(null)
  const [officeData, setOfficeData] = useState<Record<string, OfficeFormData>>({})
  const [formSaving, setFormSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setLoadError(null)
    try {
      const result = await clearances.list(appId)
      setRows(result.data)
      setMeta(result.meta)
    } catch (err) {
      setLoadError(err)
    } finally {
      setLoading(false)
    }
  }, [appId])

  useEffect(() => {
    void load()
  }, [load])

  /*
   * Saved office-form payloads. Fetched once the stage opens rather than when a
   * sheet does, so a sheet reopened from Apply shows what was already answered
   * instead of an empty form the applicant has demonstrably filled in before.
   */
  useEffect(() => {
    let active = true
    officeForms
      .list(appId)
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
  }, [appId])

  /*
   * The business as every office sheet carries it. All four sheets open by
   * asking for the same name, address and trade, and the applicant answered all
   * three in the wizard — so the sheet shows what it already knows first.
   *
   * `business` can be null: Business soft-deletes and its filings stay, so a
   * filing can outlive its register row. businessName() names that rather than
   * dereferencing into a crash.
   */
  const carriedOver: CarriedOverBusiness = useMemo(() => {
    const b = app.data?.business ?? null
    const line = b?.lines?.[0]
    return {
      name: businessName(b),
      tradeName: b?.trade_name ?? '',
      address:
        [b?.address?.line1, b?.address?.line2, b?.address?.barangay?.name]
          .filter(Boolean)
          .join(', ') || '—',
      lineOfBusiness: line?.line_of_business?.trim() || line?.psic_code?.title || '—',
    }
  }, [app.data])

  const unlocked = meta?.unlocked ?? false

  /** Run one mutation, refresh everything, and say what it did to the balance. */
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
      setMeta(result.meta)
      setBalanceNote(`${what} Balance due is now ${formatMoney(result.meta.balance_due)}.`)
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
   * would re-run the assessment for a request that has not changed, and on a
   * screen that adds money to a balance "probably idempotent" is not good
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
        () => clearances.removeHeld(appId, code),
      )
      if (!ok) return
    }
    if (row.state === 'available' || row.state === 'submitted') {
      const ok = await runAction(code, `Applied for your ${row.permit_type.name}.`, () =>
        clearances.apply(appId, code),
      )
      if (!ok) return
    }
    if (hasOfficeForm(code)) {
      setFormError(null)
      setFormCode(code)
    }
  }

  /** Withdraw a request. Its own labelled control — never a second Apply. */
  async function onUnapply(row: Clearance) {
    await runAction(
      row.permit_type.code,
      `Withdrew your ${row.permit_type.name} application.`,
      () => clearances.unapply(appId, row.permit_type.code),
    )
  }

  /** Take the uploaded copy back off. Its own labelled control — never Submit. */
  async function onRemoveHeld(row: Clearance) {
    await runAction(
      row.permit_type.code,
      `Removed the ${row.permit_type.name} copy you had uploaded.`,
      () => clearances.removeHeld(appId, row.permit_type.code),
    )
  }

  /** Send the copy chosen in the SUBMISSION dialog. Adds nothing to the balance. */
  async function onSubmitHeld(row: Clearance, file: File) {
    setHeldPrompt(null)
    setBusyCode(row.permit_type.code)
    setActionError(null)
    try {
      const result = await clearances.submitHeld(appId, row.permit_type.code, file)
      setRows(result.data)
      setMeta(result.meta)
      setBalanceNote(
        `Your ${row.permit_type.name} copy is on file. Nothing was added — balance due is still ${formatMoney(result.meta.balance_due)}.`,
      )
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
      await officeForms.save(appId, formCode, officeData[formCode] ?? {})
      setFormCode(null)
      // The sheet being complete is part of the row, so re-read it.
      await load()
    } catch (err) {
      setFormError(toApiError(err).message)
    } finally {
      setFormSaving(false)
    }
  }

  if (app.loading || loading) {
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
  if (loadError || !rows || !meta) {
    return <ErrorState error={loadError ?? new Error('Not found')} onRetry={() => void load()} />
  }

  const application = app.data
  const formMissing = formCode ? officeFormMissing(formCode, officeData[formCode] ?? {}) : []

  return (
    <div className="mx-auto max-w-5xl pb-4">
      <div className="mb-6 border-b-2 border-ink/50 pb-2">
        <h1 className="text-2xl font-bold text-ink">{businessName(application.business)}</h1>
        <p className="tnum mt-1 text-sm text-ink-secondary">{application.tracking_id}</p>
      </div>

      <h2 className="display-serif mb-1 text-3xl text-ink">LGU Clearances</h2>
      <div className="mb-6 h-px bg-ink/40" />

      {/*
        The lock, in the API's own words.

        `locked_reason` is shown verbatim. The condition that opens this stage
        is the server's to state — it knows what has been paid and what has not
        — and a sentence written here would be a second, quieter version of the
        rule that drifted out of step with the real one the first time it moved.
      */}
      {!unlocked && (
        <div
          role="status"
          className="mb-6 flex gap-2.5 rounded-md border border-blue-200 bg-blue-50 px-3.5 py-3 text-sm text-blue-800"
        >
          <InfoCircleIcon size={20} className="mt-px shrink-0" />
          <div className="min-w-0">
            <p className="font-semibold">This stage is not open yet</p>
            {/*
              One copy of the sentence, and it is the one the buttons point at.
              An earlier version repeated it under the cards as the
              aria-describedby target, which put the same paragraph on screen
              twice — a sighted reader saw it duplicated and a screen-reader
              user heard it once as a banner and again on every button.
            */}
            <p id="clearances-locked" className="mt-0.5">
              {meta.locked_reason ?? 'This stage is not open yet.'}
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

      {/* ── The running balance ───────────────────────────────────────────
        *
        * role="status" so the figures are announced when they move, not only
        * redrawn. Applying adds a fee, and an applicant who cannot see the
        * screen would otherwise commit to money that changed in silence.
        */}
      <section
        role="status"
        aria-label="Balance"
        className="mb-8 rounded-2xl bg-white px-6 py-5 shadow-card"
      >
        <dl className="flex flex-wrap gap-x-12 gap-y-4">
          <div>
            <dt className="text-xs font-bold uppercase tracking-wide text-ink-muted">Assessed</dt>
            <dd className="tnum mt-1 text-xl font-semibold text-ink">
              {formatMoney(meta.total_assessed)}
            </dd>
          </div>
          <div>
            <dt className="text-xs font-bold uppercase tracking-wide text-ink-muted">Paid</dt>
            <dd className="tnum mt-1 text-xl font-semibold text-ink">
              {formatMoney(meta.total_paid)}
            </dd>
          </div>
          <div>
            <dt className="text-xs font-bold uppercase tracking-wide text-ink-muted">Balance due</dt>
            <dd className="tnum mt-1 text-xl font-bold text-royal">
              {formatMoney(meta.balance_due)}
            </dd>
          </div>
        </dl>
        {/*
          Said plainly, because the balance is otherwise decoration. This gate
          is what makes the accrual real: applying for a clearance is spending
          money, and the consequence of not settling it is the permit staying
          unissued.
        */}
        <p className="mt-4 text-sm text-ink-secondary">
          Your Business Permit is not released until this balance is cleared.
        </p>
        {balanceNote && <p className="mt-2 text-sm font-medium text-royal">{balanceNote}</p>}
        {Number(meta.balance_due) > 0 && (
          <Link
            to={`/applications/${application.id}/pay`}
            className="mt-3 inline-block text-sm font-semibold text-royal underline underline-offset-2 hover:text-royal-hover"
          >
            Pay the balance
          </Link>
        )}
      </section>

      {/* ── The office form sheet, when Apply opened one ─────────────────── */}
      {formCode ? (
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
            business={carriedOver}
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
      ) : (
        <>
          <p className="mb-5 max-w-3xl text-sm text-ink-secondary">
            <span className="font-semibold text-ink">Apply</span> asks that office to issue the
            clearance, and adds its fee to your balance. Already hold a valid one?{' '}
            <span className="font-semibold text-ink">Submit</span> a copy instead — nothing is
            charged, because nothing is being issued.
          </p>

          <ul className="grid list-none gap-5 p-0 sm:grid-cols-2 xl:grid-cols-3">
            {rows.map((row) => {
              const code = row.permit_type.code
              const busy = busyCode === code
              const state = STATE_META[row.state] ?? STATE_META.available
              const applied = row.state === 'applied' || row.state === 'issued'
              const held = row.held_document

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
                    What each button costs, on the card, before either is
                    pressed. Apply and Submit sit side by side and look alike;
                    one of them spends money and the other does not, and that
                    is not something a card may leave the applicant to find out
                    afterwards on their Tax Order of Payment.
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
                        Withdrawing used to be a second click on Apply, which
                        made one button mean two opposite things. Stated plainly
                        instead, and kept away from Apply so neither is hit by
                        accident. Not offered once the office has issued it:
                        there is nothing left to withdraw.
                      */}
                      {row.state === 'applied' && (
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
                        Clicking "Submitted ✓" used to delete the file that had
                        just been uploaded, with no confirmation and no undo —
                        destroying something must never be the alternate meaning
                        of the button that created it.
                      */}
                      <button
                        type="button"
                        onClick={() => void onRemoveHeld(row)}
                        disabled={busy}
                        className="mt-1 text-xs font-semibold text-s-red underline underline-offset-2 disabled:opacity-60"
                      >
                        {busy ? 'Removing…' : `Remove the ${row.permit_type.name} copy`}
                      </button>
                    </div>
                  )}

                  <div className="mt-5 flex flex-1 items-end gap-2.5">
                    {/*
                      Both buttons stay in the tab order when the stage is
                      locked. `disabled` drops a control out of the tab order
                      and most screen readers pass over it, so an applicant
                      using one would never learn the button exists or why it
                      does nothing. aria-disabled says so instead, and the
                      locked reason above is what it points at.
                    */}
                    <button
                      type="button"
                      disabled={busy}
                      aria-disabled={!unlocked}
                      aria-describedby={unlocked ? undefined : 'clearances-locked'}
                      /*
                       * SUBMIT always opens the upload box. It used to toggle:
                       * a second click on "Submitted ✓" deleted the copy just
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
                      aria-describedby={unlocked ? undefined : 'clearances-locked'}
                      /*
                       * APPLY always opens this office's form. It used to
                       * toggle, so the second click quietly un-applied and
                       * opened nothing — which is why the button sometimes
                       * "just highlighted" and sometimes went to the form.
                       * Withdrawing is the labelled control above.
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
        </>
      )}

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
            form, <span className="font-semibold text-ink">nothing is added to your balance</span>,
            and your copy goes to the reviewers with the rest of your file.
          </p>
        </ProtoModal>
      )}
    </div>
  )
}
