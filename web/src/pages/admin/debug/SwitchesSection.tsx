import { useId, useState } from 'react'
import type { FormEvent } from 'react'
import { debugSwitches } from './api'
import { SYSTEM_NOTICES_CHANGED } from '../../../lib/resources'
import type { OnOffSwitch, SystemSwitchName, SystemSwitchesState } from './api'
import { Choice, SubCard } from './parts'
import { useAsync } from '../../../lib/useAsync'
import { toApiError } from '../../../lib/api'
import { formatCalendarDate } from '../../../lib/format'
import { Alert } from '../../../components/ui/Alert'
import { ErrorState, SkeletonList } from '../../../components/ui/primitives'
import { inputCls } from '../../../components/ui/Proto'

/*
 * System switches — the Debug page's third section (App\Support\SystemSwitches).
 *
 *   E-mail sign-in codes  on / off
 *   Captcha               on / off
 *   Office-hours notice   by the clock / always open / always closed
 *   Pretend date          the date renewal deadlines and late penalties are
 *                         judged against, or none
 *
 * Each overrides what the server's env decides, from the next request on, and
 * each change is audited on the server. "Use the server's setting" hands a
 * switch back to the env. A switch that cannot go on (no mailer, no captcha
 * secret) says why and stays focusable rather than disabled.
 */

export function SwitchesSection() {
  const state = useAsync(() => debugSwitches.state(), [])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [said, setSaid] = useState<string | null>(null)

  async function set(name: SystemSwitchName, value: string | null, sentence: string) {
    if (busy) return
    setBusy(true)
    setError(null)
    setSaid(null)
    try {
      state.setData(await debugSwitches.set(name, value))
      setSaid(sentence)
      if (name === 'pretend_date') window.dispatchEvent(new Event(SYSTEM_NOTICES_CHANGED))
    } catch (err) {
      const apiError = toApiError(err)
      setError(apiError.errors.value?.[0] ?? apiError.message)
    } finally {
      setBusy(false)
    }
  }

  if (state.loading && !state.data) return <SkeletonList rows={3} />
  if (state.error && !state.data) return <ErrorState error={state.error} onRetry={state.reload} />
  const s = state.data
  if (!s) return null

  return (
    <div className="space-y-5">
      {error && <Alert variant="error">{error}</Alert>}
      <p role="status" className="text-sm font-semibold text-ink empty:hidden">
        {said}
      </p>

      <OnOff
        title="E-mail sign-in codes"
        what="After the password, a 6-digit code is e-mailed and must be typed to finish signing in."
        state={s.sign_in_codes}
        busy={busy}
        onSet={(value, sentence) => set('sign_in_codes', value, sentence)}
        said={{
          on: 'Sign-in codes are on.',
          off: 'Sign-in codes are off. A password alone signs in.',
          default: "Sign-in codes follow the server's setting again.",
        }}
      />

      <OnOff
        title="Captcha on the sign-in form"
        what="The Cloudflare check every sign-in must pass before the password is looked at."
        state={s.captcha}
        busy={busy}
        onSet={(value, sentence) => set('captcha', value, sentence)}
        said={{
          on: 'The captcha is on.',
          off: 'The captcha is off. The sign-in form no longer shows it.',
          default: "The captcha follows the server's setting again.",
        }}
      />

      <SubCard title="Office-hours notice">
        <p className="max-w-[70ch] text-sm text-ink-secondary">
          The &ldquo;City offices are closed&rdquo; notice at the top of every screen. By the clock,
          City Hall is {s.office_hours.open_by_the_clock ? 'open' : 'closed'} right now. Forcing the
          notice does not change the audit log, which still records sign-ins outside real office
          hours.
        </p>
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          <Choice
            label="By the clock"
            description="Shown only outside office hours."
            on={s.office_hours.mode === 'auto'}
            busy={busy}
            onChoose={() => set('office_hours', 'auto', 'The office-hours notice follows the clock again.')}
          />
          <Choice
            label="Always open"
            description="The closed notice never shows."
            on={s.office_hours.mode === 'open'}
            busy={busy}
            onChoose={() => set('office_hours', 'open', 'The office-hours notice now says open, whatever the clock says.')}
          />
          <Choice
            label="Always closed"
            description="The closed notice always shows."
            on={s.office_hours.mode === 'closed'}
            busy={busy}
            onChoose={() => set('office_hours', 'closed', 'The office-hours notice now says closed, whatever the clock says.')}
          />
        </div>
      </SubCard>

      <PretendDate state={s} busy={busy} onSet={(value, sentence) => set('pretend_date', value, sentence)} />
    </div>
  )
}

/* ── An on/off switch with the server's default behind it ───────────────── */

function OnOff({
  title,
  what,
  said,
  state,
  busy,
  onSet,
}: {
  title: string
  what: string
  /** What the page announces after each change, in this switch's own grammar. */
  said: Record<'on' | 'off' | 'default', string>
  state: OnOffSwitch
  busy: boolean
  onSet: (value: 'on' | 'off' | 'default', sentence: string) => void
}) {
  const whyNotId = useId()

  return (
    <SubCard
      title={title}
      action={
        state.overridden ? (
          <button
            type="button"
            onClick={() => onSet('default', said.default)}
            aria-disabled={busy || undefined}
            className="text-sm font-semibold text-royal underline underline-offset-2 hover:no-underline aria-disabled:opacity-60"
          >
            Use the server&apos;s setting
          </button>
        ) : null
      }
    >
      <p className="max-w-[70ch] text-sm text-ink-secondary">
        {what} {state.overridden ? 'Set here, overriding the server.' : 'As the server is set up.'}
      </p>
      {!state.can_turn_on && state.why_not && (
        <p id={whyNotId} className="mt-2 max-w-[70ch] text-sm text-ink-secondary">
          Cannot be turned on: {state.why_not}
        </p>
      )}
      <div className="mt-4 grid gap-3 sm:grid-cols-2">
        <Choice
          label="On"
          name={`${title}: On`}
          description="Required on every sign-in."
          on={state.on}
          busy={busy}
          blockedBy={state.can_turn_on ? undefined : whyNotId}
          onChoose={() => onSet('on', said.on)}
        />
        <Choice
          label="Off"
          name={`${title}: Off`}
          description="Not asked for."
          on={!state.on}
          busy={busy}
          onChoose={() => onSet('off', said.off)}
        />
      </div>
    </SubCard>
  )
}

/* ── Pretend date ───────────────────────────────────────────────────────── */

function PretendDate({
  state: s,
  busy,
  onSet,
}: {
  state: SystemSwitchesState
  busy: boolean
  onSet: (value: string | null, sentence: string) => void
}) {
  const inputId = useId()
  const [value, setValue] = useState(s.pretend_date.date ?? '')
  const current = s.pretend_date.date

  function submit(event: FormEvent) {
    event.preventDefault()
    if (!value) return
    onSet(value, `Renewal dates are now simulated as ${formatCalendarDate(value)}.`)
  }

  return (
    <SubCard
      title="Pretend date for renewals"
      action={
        current ? (
          <button
            type="button"
            onClick={() => {
              setValue('')
              onSet(null, 'Renewal dates are real again.')
            }}
            aria-disabled={busy || undefined}
            className="text-sm font-semibold text-royal underline underline-offset-2 hover:no-underline aria-disabled:opacity-60"
          >
            Use the real date
          </button>
        ) : null
      }
    >
      <p className="max-w-[70ch] text-sm text-ink-secondary">
        Renewal deadlines, late surcharges and the &ldquo;days left&rdquo; on permits are judged as
        if today were this date. Everything else keeps the real date: the time on every record, the
        audit log, payments, and the nightly expiry and reminders. While it is set, every signed-in
        screen says so.
      </p>
      <p className="mt-3 text-sm text-ink">
        {current ? (
          <>
            <span className="font-semibold">Simulated as {formatCalendarDate(current)}.</span>{' '}
            <span className="text-ink-muted">
              The server&apos;s real date is {formatCalendarDate(s.pretend_date.real_today)}.
            </span>
          </>
        ) : (
          <span className="font-semibold">
            Real: the server&apos;s date, {formatCalendarDate(s.pretend_date.real_today)}.
          </span>
        )}
      </p>
      <form onSubmit={submit} className="mt-3 flex flex-wrap items-end gap-3">
        <div>
          <label htmlFor={inputId} className="mb-1 block text-xs font-semibold text-ink">
            Pretend today is
          </label>
          <input
            id={inputId}
            type="date"
            value={value}
            onChange={(e) => setValue(e.target.value)}
            className={`${inputCls} w-auto`}
          />
        </div>
        <button
          type="submit"
          aria-disabled={busy || !value || undefined}
          className="rounded-full bg-royal px-5 py-2 text-sm font-semibold text-white hover:bg-royal-hover aria-disabled:cursor-not-allowed aria-disabled:opacity-60"
        >
          Use this date
        </button>
      </form>
    </SubCard>
  )
}
