import { useId, useState, type ReactNode } from 'react'
import { AlertTriangleIcon, CheckCircleIcon, InfoCircleIcon } from './icons'
import { saveOfficerZoningFacts } from '../lib/zoningCheck'
import type {
  ZoningCheckResult,
  ZoningFact,
  ZoningFactValue,
  ZoningFactValues,
  ZoningFinding,
  ZoningGroup,
  ZoningStatus,
} from '../lib/zoningCheck'

/*
 * City Ordinance No. 24-2018, rule by rule, as one list two people read.
 *
 * ── The applicant reads it as an early warning ─────────────────────────────
 *
 * On Location & Zoning and again on Review. Each rule says met, not met, or
 * that CPDO checks it, with the article, section and page it comes from, and a
 * rule waiting on an answer asks its question right there — so the form never
 * grows a block of sixty zoning questions, only the few the filing's rules
 * actually need.
 *
 * ── The zoning officer reads it as the checklist they decide against ───────
 *
 * The same findings, plus the ones that are CPDO's alone (which zone the lot is
 * in, the 4 m buffer, the height limits), and the officer may record any
 * answer — the lot's zone above all, which resolves every "applies if your lot
 * is in …" below it.
 *
 * ── What it must never look like ────────────────────────────────────────────
 *
 * A refusal. "Not met" is amber with a warning triangle, never #bd0000: the
 * filing is not blocked and nothing has gone wrong on the screen (DESIGN.md,
 * Red Means Stop). Every status is a word and an icon as well as a tint (Never
 * Color Alone). The opening line says CPDO reviews and nothing here refuses.
 */

const GROUPS: { key: ZoningGroup; applicant: string; officer: string }[] = [
  { key: 'where', applicant: 'Where your business is', officer: 'Zone of the lot' },
  { key: 'uses', applicant: 'Your type of business', officer: 'Allowed uses' },
  { key: 'home', applicant: 'Running it from a home', officer: 'Home occupation and home industry' },
  { key: 'conditions', applicant: 'Conditions on your type of business', officer: 'Conditions on the use' },
  { key: 'special', applicant: 'Special use permit', officer: 'Special use' },
  { key: 'overlay', applicant: 'Flood, heritage and eco-tourism areas', officer: 'Overlay zones' },
  { key: 'site', applicant: 'The site', officer: 'Site and general regulations' },
  { key: 'signs', applicant: 'Business sign', officer: 'Signs' },
  { key: 'performance', applicant: 'Noise, smoke, water and waste', officer: 'Performance standards' },
  { key: 'nonconforming', applicant: 'A business already operating here', officer: 'Non-conforming use' },
  { key: 'procedure', applicant: 'The clearance itself', officer: 'Procedure' },
  { key: 'conflict', applicant: 'Where the rules disagree', officer: 'Where the ordinance contradicts itself' },
]

const STATUS: Record<ZoningStatus, { word: string; cls: string; icon: ReactNode }> = {
  met: {
    word: 'Met',
    cls: 'border-[#12724a]/40 bg-s-green-tint text-[#12724a]',
    icon: <CheckCircleIcon size={15} aria-hidden="true" />,
  },
  not_met: {
    word: 'Not met',
    cls: 'border-s-orange bg-s-orange-tint text-s-orange-ink',
    icon: <AlertTriangleIcon size={15} aria-hidden="true" />,
  },
  review: {
    word: 'CPDO checks',
    cls: 'border-royal/30 bg-royal-tint text-royal-deep',
    icon: <InfoCircleIcon size={15} aria-hidden="true" />,
  },
  info: {
    word: 'Note',
    cls: 'border-line bg-white text-ink-secondary',
    icon: <InfoCircleIcon size={15} aria-hidden="true" />,
  },
}

export function ZoningRuleChecklist({
  result,
  loading = false,
  variant,
  values,
  onAnswer,
  onOfficerSave,
  readOnly = false,
}: {
  result: ZoningCheckResult | null
  loading?: boolean
  variant: 'applicant' | 'officer'
  /** The applicant's answers as held on screen (applicant variant). */
  values?: ZoningFactValues
  onAnswer?: (key: string, value: ZoningFactValue | null) => void
  /** Save the officer's answers (officer variant). */
  onOfficerSave?: (facts: ZoningFactValues) => Promise<void>
  readOnly?: boolean
}) {
  const headingId = useId()
  const [draft, setDraft] = useState<ZoningFactValues>({})
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)

  if (result === null || !result.ready) {
    return loading ? (
      <p className="text-sm text-ink-muted" aria-live="polite">
        Checking the zoning rules&hellip;
      </p>
    ) : null
  }

  const findings = result.findings.filter((f) => variant === 'officer' || f.audience !== 'officer')
  const facts = new Map(result.facts.map((f) => [f.key, f]))
  const asked = new Set<string>()
  const needsAnswer = (f: ZoningFinding) =>
    variant === 'applicant' && f.missing.some((k) => facts.get(k)?.who === 'applicant')
  const counts = {
    met: findings.filter((f) => f.status === 'met').length,
    not_met: findings.filter((f) => f.status === 'not_met').length,
    review: findings.filter((f) => f.status === 'review' && !needsAnswer(f)).length,
    answer: findings.filter(needsAnswer).length,
  }

  /*
   * The value an input shows: the officer's unsaved edit, else what the
   * check read (applicant's or officer's), else the applicant's screen value.
   */
  const current = (fact: ZoningFact): ZoningFactValue | null => {
    if (variant === 'officer' && fact.key in draft) return draft[fact.key] ?? null
    if (variant === 'applicant' && values && fact.key in values) return values[fact.key] ?? null
    return fact.value
  }
  const answer = (key: string, value: ZoningFactValue | null) => {
    if (variant === 'officer') setDraft((d) => ({ ...d, [key]: value }))
    else onAnswer?.(key, value)
  }

  /** The questions under one finding: each fact asked once, at its first finding. */
  const questionsFor = (f: ZoningFinding) => {
    const keys = [...f.asks, ...(variant === 'officer' ? f.officer_asks : [])].filter((k) => {
      const fact = facts.get(k)
      if (!fact || asked.has(k)) return false
      if (variant === 'applicant' && fact.who !== 'applicant') return false
      return true
    })
    keys.forEach((k) => asked.add(k))
    return keys.map((k) => facts.get(k)!).filter(Boolean)
  }

  return (
    <section
      aria-labelledby={headingId}
      data-testid={`zoning-rules-${variant}`}
      className="rounded-xl border border-line bg-white p-4 text-left sm:p-5"
    >
      <h3 id={headingId} className="text-[15px] font-bold text-ink">
        {variant === 'applicant'
          ? 'What the zoning rules say about this filing'
          : 'City Ordinance No. 24-2018 — the rules this filing meets'}
      </h3>
      <p className="mt-1 text-sm text-ink-secondary">
        {variant === 'applicant'
          ? result.principle.text
          : 'Every land use is a right, subject to the ordinance’s review. Each rule below is met, not met, or for CPDO to check; the decision is CPDO’s.'}{' '}
        <span className="whitespace-nowrap text-xs text-ink-muted">({result.principle.citation})</span>
      </p>

      {/* A count in words, not a stat card: four numbers that name themselves. */}
      <p className="mt-3 text-sm text-ink" aria-live="polite" data-testid="zoning-rules-summary">
        <span className="font-semibold">{counts.met}</span> met ·{' '}
        <span className="font-semibold">{counts.not_met}</span> not met ·{' '}
        <span className="font-semibold">{counts.review}</span> for CPDO to check
        {variant === 'applicant' && (
          <>
            {' '}
            · <span className="font-semibold">{counts.answer}</span> waiting on your answer
          </>
        )}
        {loading && <span className="ml-2 text-xs text-ink-muted">Updating&hellip;</span>}
      </p>

      <div className="mt-4 space-y-5">
        {GROUPS.map((group) => {
          const rows = findings.filter((f) => f.group === group.key)
          if (rows.length === 0) return null
          return (
            <div key={group.key}>
              <h4 className="text-[11px] font-bold uppercase tracking-wide text-ink-secondary">
                {variant === 'applicant' ? group.applicant : group.officer}
              </h4>
              <ul className="mt-2 space-y-2">
                {rows.map((f, i) => (
                  <FindingRow
                    key={`${f.rule}-${i}`}
                    finding={f}
                    variant={variant}
                    waiting={needsAnswer(f)}
                    questions={questionsFor(f)}
                    current={current}
                    answer={answer}
                    readOnly={readOnly}
                  />
                ))}
              </ul>
            </div>
          )
        })}
      </div>

      {variant === 'officer' && onOfficerSave && !readOnly && (
        <div className="mt-5 flex flex-wrap items-center gap-3 border-t border-line pt-4">
          <button
            type="button"
            onClick={async () => {
              setSaving(true)
              setSaveError(null)
              try {
                await onOfficerSave(draft)
                setDraft({})
              } catch {
                setSaveError('The answers were not saved. Try again.')
              } finally {
                setSaving(false)
              }
            }}
            aria-disabled={saving || Object.keys(draft).length === 0}
            className="rounded-md bg-royal px-4 py-2 text-sm font-semibold text-white hover:bg-royal-hover aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
          >
            {saving ? 'Saving…' : 'Save CPDO’s answers'}
          </button>
          <span className="text-xs text-ink-muted">
            Your answers override the applicant’s and are kept with the filing.
          </span>
          {saveError && (
            <p role="alert" className="w-full text-sm font-medium text-s-red">
              {saveError}
            </p>
          )}
        </div>
      )}
    </section>
  )
}

function FindingRow({
  finding,
  variant,
  waiting,
  questions,
  current,
  answer,
  readOnly,
}: {
  finding: ZoningFinding
  variant: 'applicant' | 'officer'
  waiting: boolean
  questions: ZoningFact[]
  current: (fact: ZoningFact) => ZoningFactValue | null
  answer: (key: string, value: ZoningFactValue | null) => void
  readOnly: boolean
}) {
  const status = STATUS[finding.status]
  return (
    <li
      className="rounded-lg border border-line px-3 py-2.5"
      data-rule={finding.rule}
      data-status={finding.status}
    >
      <div className="flex flex-wrap items-start gap-x-3 gap-y-1">
        <span
          className={`inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-semibold ${
            waiting ? STATUS.review.cls : status.cls
          }`}
        >
          {waiting ? STATUS.review.icon : status.icon}
          {waiting ? 'Answer below' : status.word}
        </span>
        <p className="min-w-0 flex-1 text-sm font-semibold text-ink">{finding.title}</p>
      </div>
      <p className="mt-1 text-sm text-ink-secondary">
        {/*
          The reasons are written to the applicant ("Say whether…"). On
          CPDO's copy an unanswered one reads as what it is.
        */}
        {variant === 'officer' && finding.missing.length > 0 && /^(Say|Give|Answer)\b/.test(finding.reason)
          ? 'Not answered yet.'
          : finding.reason}
      </p>
      {finding.scope && <p className="mt-1 text-xs italic text-ink-muted">{finding.scope}</p>}
      {questions.length > 0 && (
        <div className="mt-2 space-y-2.5">
          {questions.map((fact) => (
            <FactInput key={fact.key} fact={fact} value={current(fact)} onChange={answer} readOnly={readOnly} variant={variant} />
          ))}
        </div>
      )}
      <p className="mt-1.5 text-[11px] text-ink-muted">
        City Ordinance No. 24-2018, {finding.citations.join('; ')}
        {finding.question && (
          <>
            {' '}
            · {variant === 'officer' ? `Question ${finding.question} is with the City` : 'The City has been asked about this'}
          </>
        )}
      </p>
      {variant === 'officer' && finding.rules_detail.length > 0 && (
        <details className="mt-1">
          <summary className="cursor-pointer text-[11px] font-medium text-royal">
            {finding.rules_detail.length === 1 ? 'The rule' : `The ${finding.rules_detail.length} rules`}
          </summary>
          <ul className="mt-1 space-y-1">
            {finding.rules_detail.map((r) => (
              <li key={r.id} className="text-xs text-ink-secondary">
                <span className="font-semibold text-ink">{r.title}</span>{' '}
                <span className="text-ink-muted">({r.citation})</span> — {r.plain}
              </li>
            ))}
          </ul>
        </details>
      )}
    </li>
  )
}

function FactInput({
  fact,
  value,
  onChange,
  readOnly,
  variant,
}: {
  fact: ZoningFact
  value: ZoningFactValue | null
  onChange: (key: string, value: ZoningFactValue | null) => void
  readOnly: boolean
  variant: 'applicant' | 'officer'
}) {
  const id = useId()
  const by =
    variant === 'officer' && fact.answered_by ? (
      <span className="ml-1 text-[11px] font-normal text-ink-muted">
        ({fact.answered_by === 'officer' ? 'CPDO' : 'applicant'})
      </span>
    ) : null

  if (fact.type === 'bool') {
    return (
      <fieldset className="text-sm" data-fact={fact.key}>
        <legend className="font-medium text-ink">
          {fact.label}
          {by}
        </legend>
        {fact.help && <p className="text-xs text-ink-muted">{fact.help}</p>}
        <div className="mt-1 flex gap-4">
          {[
            { v: true, label: 'Yes' },
            { v: false, label: 'No' },
          ].map((opt) => (
            <label key={opt.label} className="inline-flex items-center gap-1.5">
              <input
                type="radio"
                name={`${id}-${fact.key}`}
                checked={value === opt.v}
                onChange={() => !readOnly && onChange(fact.key, opt.v)}
                readOnly={readOnly}
                aria-readonly={readOnly || undefined}
                className="accent-royal"
              />
              {opt.label}
            </label>
          ))}
        </div>
      </fieldset>
    )
  }

  if (fact.type === 'choice' && fact.options) {
    return (
      <label className="block text-sm" data-fact={fact.key}>
        <span className="font-medium text-ink">
          {fact.label}
          {by}
        </span>
        <select
          value={value === null ? '' : String(value)}
          onChange={(e) => !readOnly && onChange(fact.key, e.target.value === '' ? null : e.target.value)}
          aria-readonly={readOnly || undefined}
          className="mt-1 block w-full max-w-sm rounded-md border border-line bg-white px-2.5 py-1.5 text-sm"
        >
          <option value="">Choose one</option>
          {fact.options.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      </label>
    )
  }

  return (
    <label className="block text-sm" data-fact={fact.key}>
      <span className="font-medium text-ink">
        {fact.label}
        {fact.unit && <span className="font-normal text-ink-muted"> ({fact.unit})</span>}
        {by}
      </span>
      <input
        type={fact.type === 'date' ? 'date' : 'text'}
        inputMode={fact.type === 'number' ? 'decimal' : undefined}
        value={value === null ? '' : String(value)}
        onChange={(e) => {
          if (readOnly) return
          const raw = e.target.value.trim()
          if (raw === '') return onChange(fact.key, null)
          if (fact.type === 'number') {
            const n = Number(raw.replace(/,/g, ''))
            if (!Number.isNaN(n)) onChange(fact.key, n)
            return
          }
          onChange(fact.key, raw)
        }}
        readOnly={readOnly}
        className="mt-1 block w-full max-w-[12rem] rounded-md border border-line bg-white px-2.5 py-1.5 text-sm tnum"
      />
    </label>
  )
}

/**
 * The zoning officer's copy: the evaluation the review payload carried, and a
 * save that replaces it with the server's re-evaluation, so a recorded lot
 * zone resolves the "applies if your lot is in …" findings on the spot.
 */
export function OfficerZoningChecklist({
  applicationId,
  initial,
  readOnly = false,
}: {
  applicationId: number
  initial: ZoningCheckResult
  readOnly?: boolean
}) {
  const [result, setResult] = useState<ZoningCheckResult>(initial)

  return (
    <ZoningRuleChecklist
      variant="officer"
      result={result}
      readOnly={readOnly}
      onOfficerSave={async (facts) => setResult(await saveOfficerZoningFacts(applicationId, facts))}
    />
  )
}
