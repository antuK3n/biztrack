import { Fragment, useEffect, useId, useMemo, useRef, useState } from 'react'
import { CheckIcon, ChevronDownIcon } from '../../components/icons'
import { PasswordInput } from '../../components/ui/PasswordInput'
import { FieldLabel, inputCls } from '../../components/ui/Proto'

/**
 * The red line under a field that the server, or this form, has objected to.
 *
 * Lived inside UsersPage until these fields moved out of it. Kept here rather
 * than in Proto because it is paired with FieldLabel's asterisk and the two
 * belong to the same small convention.
 */
export function FieldError({ message }: { message?: string }) {
  return message ? <p className="mt-1 text-xs font-medium text-s-red">{message}</p> : null
}
import type { AdminRole } from '../../lib/types'

/*
 * ── The three fields the officer forms share ───────────────────────────────
 *
 * Add Officer and Edit Officer ask for the same office, the same role, the
 * same mobile number and the same password, and they had drifted into asking
 * differently: one offered a role the other did not, one validated a number
 * the other let through. They ask through these now, so a change to what a
 * password has to be is a change in one place.
 */

/* ── Mobile number ───────────────────────────────────────────────────────── */

/**
 * The one shape the column stores: eleven digits, starting 09.
 *
 * The same rule the API enforces (`StaffCredentials::mobileRules`). Written
 * here as well so the reader learns before they submit rather than after —
 * but the server is the one that decides, and this is a courtesy, not a gate.
 */
const MOBILE = /^09\d{9}$/

/**
 * Tidy what was typed into the shape the rule expects.
 *
 * People type their own number the way they say it — `+63 917 123 4567`,
 * `0917-123-4567`. All of those are the same number and the form accepts them,
 * exactly as the API does; a number of the wrong LENGTH is a wrong number and
 * is still refused. Mirrors `StaffCredentials::normaliseMobile`.
 */
export function normaliseMobile(value: string): string {
  const digits = value.trim().replace(/[\s\-().]/g, '')
  const international = /^\+?63(9\d{9})$/.exec(digits)
  return international ? `0${international[1]}` : digits
}

export function mobileLooksRight(value: string): boolean {
  return MOBILE.test(normaliseMobile(value))
}

/**
 * The mobile field, with the rule said before it is broken.
 *
 * ── Why it does not shout while you are typing ────────────────────────────
 *
 * A number is wrong for every keystroke but the last one. Marking the field
 * red on the third digit is telling somebody off for not having finished, and
 * it trains them to ignore the colour by the time it means something — so the
 * requirement sits under the field as plain help from the start, and only
 * turns into an error once they have left the field with something that
 * cannot be a number (WCAG 3.3.1: identify the error; 3.3.3: suggest the fix).
 */
export function MobileField({
  value,
  onChange,
  error,
  required = true,
}: {
  value: string
  onChange: (value: string) => void
  error?: string
  required?: boolean
}) {
  const id = useId()
  const [touched, setTouched] = useState(false)

  const typed = value.trim() !== ''
  const wrong = touched && typed && !mobileLooksRight(value)
  const message = error ?? (wrong ? 'This is not 11 digits starting 09.' : undefined)

  return (
    <label className="block">
      <FieldLabel required={required}>Mobile number</FieldLabel>
      <input
        id={id}
        className={inputCls}
        inputMode="tel"
        autoComplete="tel"
        placeholder="09171234567"
        aria-invalid={message ? true : undefined}
        aria-describedby={`${id}-help`}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        /*
         * Tidied on the way out, not on every keystroke: rewriting the field
         * under the cursor moves the caret and makes the box feel like it is
         * fighting back. On blur the reader has finished the thought.
         */
        onBlur={() => {
          setTouched(true)
          const tidy = normaliseMobile(value)
          if (tidy !== value) onChange(tidy)
        }}
      />
      <FieldError message={message} />
      <p id={`${id}-help`} className="mt-1 text-xs text-ink-muted">
        11 digits starting 09, as in 09171234567. <span className="font-mono">+63</span>, spaces and
        dashes are tidied up for you.
      </p>
    </label>
  )
}

/* ── Password ────────────────────────────────────────────────────────────── */

/**
 * What a staff password has to be, as the reader has to satisfy it.
 *
 * Every line mirrors one clause of `StaffCredentials::passwordRules`. Keep
 * them in step: a checklist that goes green on something the server then
 * refuses is worse than no checklist, because it moves the refusal to the one
 * moment the reader has stopped looking for it.
 */
const RULES: { label: string; met: (value: string) => boolean }[] = [
  { label: 'At least 12 characters', met: (v) => v.length >= 12 },
  { label: 'A capital and a small letter', met: (v) => /[a-z]/.test(v) && /[A-Z]/.test(v) },
  { label: 'A number', met: (v) => /\d/.test(v) },
  { label: 'A symbol, such as ! - or @', met: (v) => /[^A-Za-z0-9]/.test(v) },
]

export function passwordMeetsRules(value: string): boolean {
  return RULES.every((rule) => rule.met(value))
}

/**
 * The password field and its requirements, shown as a list that fills in.
 *
 * ── Why the rules are on screen before they are broken ────────────────────
 *
 * The alternative is what this app did until now: accept anything, then
 * answer 422 with a sentence naming one clause at a time. That makes the
 * reader guess the policy by failing against it, which is slow, and it makes
 * them land on the weakest password that gets through, which is the opposite
 * of the point.
 *
 * ── Why it is a list and not a strength bar ───────────────────────────────
 *
 * A bar reading "Fair" is an opinion; the server holds a rule. "Needs a
 * symbol" can be acted on and "Fair" cannot, and a bar that says Strong about
 * a password the server will refuse is simply a lie with a colour.
 */
export function PasswordField({
  value,
  onChange,
  label,
  required,
  error,
  hint,
}: {
  value: string
  onChange: (value: string) => void
  label: string
  required?: boolean
  error?: string
  hint?: string
}) {
  const id = useId()
  const typed = value !== ''

  return (
    <div>
      <label className="block" htmlFor={id}>
        <FieldLabel required={required}>{label}</FieldLabel>
      </label>
      <PasswordInput
        id={id}
        value={value}
        onChange={onChange}
        invalid={Boolean(error)}
        describedBy={`${id}-rules`}
        required={required}
      />
      <FieldError message={error} />

      <ul id={`${id}-rules`} className="mt-2 space-y-1">
        {RULES.map((rule) => {
          const met = rule.met(value)
          return (
            <li
              key={rule.label}
              className={`flex items-center gap-2 text-xs ${
                met ? 'text-s-green' : typed ? 'text-ink-secondary' : 'text-ink-muted'
              }`}
            >
              {/*
                The tick is decorative and the state is in the text, because a
                reader who cannot see a green tick still has to know which
                clauses are done. `aria-hidden` on the mark, the word "done" in
                the label a screen reader reads.
              */}
              <span
                aria-hidden="true"
                className={`flex h-4 w-4 shrink-0 items-center justify-center rounded-full border ${
                  met ? 'border-s-green bg-s-green text-white' : 'border-line'
                }`}
              >
                {met && <CheckIcon size={10} />}
              </span>
              <span>
                {rule.label}
                <span className="sr-only">{met ? ' — done' : ' — still needed'}</span>
              </span>
            </li>
          )
        })}
      </ul>

      {hint && <p className="mt-2 text-xs text-ink-muted">{hint}</p>}
    </div>
  )
}

/* ── Role ────────────────────────────────────────────────────────────────── */

/**
 * The role, typed or picked.
 *
 * ── Why this is not the select it was ─────────────────────────────────────
 *
 * A native select is a fine control for six options and a poor one the moment
 * somebody knows the answer: there is no way to type "fire" and be done, and
 * on a long list the keyboard only jumps by first letter, so "Sanitary
 * Officer" and "Super Admin" fight each other [client, 27 September 2026:
 * *"make it pwede ring i type kung anong role di lang basta pipili"*].
 *
 * So: a combobox. Typing filters, the arrows move, Enter takes the highlighted
 * one, Escape closes without choosing. The whole list is one press away for
 * anybody who does not know the names, which is the thing a plain text box
 * would have taken from them.
 *
 * ── Why it comes after the office ─────────────────────────────────────────
 *
 * The office decides which roles are even possible — the super admin belongs
 * to no office and every other role must have one — so asking for the role
 * first meant answering a question whose options had not been settled. The
 * form now asks the office, then offers the roles that fit it [client, same
 * day: *"unahin muna ang kung anong office then doon pipili ng role"*].
 */
export function RolePicker({
  roles,
  officeId,
  value,
  typed,
  onChange,
  disabled,
  disabledReason,
  currentRole,
  error,
  allowTyped = true,
}: {
  roles: AdminRole[]
  /**
   * The office chosen above, so its own roles can come first.
   *
   * Nothing ties a role to an office in the register, so this cannot filter —
   * it orders. See the note on the grouping below.
   */
  officeId?: string
  value: string
  /**
   * A job title typed in because the office has no role by that name.
   *
   * Held apart from `value` rather than folded into it, and that separation is
   * the whole safety of the feature: `value` is a role that EXISTS, `typed` is
   * one the server is being asked to create. A single field would have made
   * "pick the sanitary role" and "invent a role called sanitary" the same
   * request, distinguishable only by whether a lookup happened to miss.
   */
  typed?: string
  /** Exactly one of the two is ever set; the other comes back empty. */
  onChange: (name: string, typedTitle: string) => void
  disabled?: boolean
  /** Said in words, because a greyed box with no reason is a dead end. */
  disabledReason?: string
  /** The role this account already holds, never offered as unavailable. */
  currentRole?: string
  error?: string
  /**
   * Whether a title with no match may be used at all.
   *
   * False on the super-admin path: `admin` holds the power to mint accounts,
   * so a typed name that reached a departmentless role would be privilege
   * escalation by spelling. The server refuses it too — this only keeps the
   * offer off a screen where it could not be honoured.
   */
  allowTyped?: boolean
}) {
  const id = useId()
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [active, setActive] = useState(0)
  const box = useRef<HTMLDivElement | null>(null)

  const chosen = roles.find((r) => r.name === value)

  /*
   * The text in the box is the chosen role's label until the reader starts
   * typing, and their query while they are. Two states in one control, which
   * is what a combobox is; keeping them in one string is what makes Escape
   * able to put the label back.
   *
   * A TYPED title stands in for the label when there is one, so a reader who
   * closes the list and comes back finds what they wrote still in the box.
   */
  const [typing, setTyping] = useState(false)
  const text = typing ? query : (chosen?.label ?? typed ?? '')

  const needle = query.trim()

  const matches = useMemo(() => {
    const lower = needle.toLowerCase()
    const found =
      !typing || lower === ''
        ? roles
        : roles.filter(
            (r) =>
              r.label.toLowerCase().includes(lower) ||
              r.name.toLowerCase().includes(lower) ||
              (r.description ?? '').toLowerCase().includes(lower),
          )

    /*
     * ── This office's roles first ────────────────────────────────────────
     *
     * An admin who has just chosen CHO does not want to read six roles, five
     * of which belong to other offices [client, 27 September 2026: *"may
     * dropdown na ng kung ano ano ang role sa office na pinili na yon"*].
     *
     * ORDERED, not filtered, and the difference matters. Nothing in the
     * register ties a role to an office — `used_in_departments` is derived
     * from the accounts that happen to hold it — so filtering would invent a
     * rule the server does not enforce and hide a legitimate choice. The
     * office's own roles come first under a heading; the rest follow under
     * theirs.
     *
     * A role typed for this office today appears in the first group tomorrow,
     * as soon as the account holding it exists. That is the loop, and it
     * needs nobody to maintain it.
     */
    const office = Number(officeId)
    if (!officeId || Number.isNaN(office)) return found

    const here = found.filter((r) => r.used_in_departments?.includes(office))
    const elsewhere = found.filter((r) => !r.used_in_departments?.includes(office))
    return [...here, ...elsewhere]
  }, [roles, needle, typing, officeId])

  /** How many of `matches` are roles this office already uses. */
  const usedHere = useMemo(() => {
    const office = Number(officeId)
    if (!officeId || Number.isNaN(office)) return 0
    return matches.filter((r) => r.used_in_departments?.includes(office)).length
  }, [matches, officeId])

  /*
   * ── Offering a title the list does not hold ──────────────────────────────
   *
   * An office needs to be able to write down the job title it actually uses
   * [client, 27 September 2026: *"pede rin nila i type yung role kung wala sa
   * choices"*]. Until now typing only filtered: a title with no match left the
   * reader looking at "No role matches", with nothing to press.
   *
   * Offered only when nothing matches EXACTLY. "Sanitary Inspector" while
   * "Sanitary Officer" is on the list still shows both, because the reader is
   * probably part-way through a word — but once what they have written is a
   * role's own label, inventing a second one beside it is never what they
   * meant.
   */
  const exact = roles.some((r) => r.label.toLowerCase() === needle.toLowerCase())
  const canInvent = allowTyped && typing && needle.length >= 3 && !exact

  // Close on a click elsewhere. Escape is handled on the input itself, where
  // it can also put back the label the reader typed over.
  useEffect(() => {
    if (!open) return
    const onDown = (event: MouseEvent) => {
      if (box.current && !box.current.contains(event.target as Node)) {
        setOpen(false)
        setTyping(false)
      }
    }
    document.addEventListener('mousedown', onDown)
    return () => document.removeEventListener('mousedown', onDown)
  }, [open])

  function take(role: AdminRole) {
    if (!role.available && role.name !== currentRole) return
    onChange(role.name, '')
    setOpen(false)
    setTyping(false)
    setQuery('')
  }

  /** Use what was written as a new role, rather than an existing one. */
  function invent() {
    if (!canInvent) return
    onChange('', needle)
    setOpen(false)
    setTyping(false)
    setQuery('')
  }

  return (
    <div ref={box} className="relative">
      <label className="block" htmlFor={id}>
        <FieldLabel required>Role</FieldLabel>
      </label>

      <div className="relative">
        <input
          id={id}
          role="combobox"
          aria-expanded={open}
          aria-controls={`${id}-list`}
          aria-autocomplete="list"
          aria-activedescendant={open && matches[active] ? `${id}-opt-${active}` : undefined}
          aria-invalid={error ? true : undefined}
          aria-disabled={disabled || undefined}
          aria-describedby={disabled && disabledReason ? `${id}-why` : undefined}
          autoComplete="off"
          className={`${inputCls} pr-10 aria-disabled:cursor-not-allowed aria-disabled:bg-canvas aria-disabled:text-ink-muted`}
          placeholder={disabled ? '' : 'Type or choose a role…'}
          value={text}
          readOnly={disabled}
          onFocus={() => !disabled && setOpen(true)}
          onChange={(e) => {
            if (disabled) return
            setTyping(true)
            setQuery(e.target.value)
            setActive(0)
            setOpen(true)
          }}
          onKeyDown={(e) => {
            if (disabled) return
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
              e.preventDefault()
              setOpen(true)
              setActive((i) => {
                const step = e.key === 'ArrowDown' ? 1 : -1
                const next = i + step
                return next < 0 ? matches.length - 1 : next >= matches.length ? 0 : next
              })
            } else if (e.key === 'Enter' && open) {
              e.preventDefault()
              const pick = matches[active]
              /*
                A match wins over inventing. Enter on a highlighted row takes
                that row; Enter with nothing to highlight is how a reader
                commits the title they have just written, which is what they
                expect from a box that let them write it.
              */
              if (pick) take(pick)
              else invent()
            } else if (e.key === 'Escape' && open) {
              /*
                Stopped here, so it closes the LIST and not the dialog this
                form is sitting in. A reader who opened the role list and
                thought better of it has not asked to throw the account away.
              */
              e.preventDefault()
              e.stopPropagation()
              setOpen(false)
              setTyping(false)
              setQuery('')
            }
          }}
        />
        <ChevronDownIcon
          size={18}
          aria-hidden="true"
          className="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-ink-secondary"
        />
      </div>

      {open && !disabled && (
        <ul
          id={`${id}-list`}
          role="listbox"
          className="absolute z-[1100] mt-1 max-h-60 w-full overflow-y-auto rounded-lg border border-input-border bg-white py-1 shadow-lg"
        >
          {/*
            ── Use what was typed ────────────────────────────────────────────

            At the TOP, and only when it is the likelier answer: a reader who
            has written something the list does not hold is past browsing it.
            Below the matches it would sit under a scroll on a short list and
            be missed exactly when it is wanted.

            It is a real option, not a hint, so the arrow keys reach it and a
            screen reader announces it as a choice — which is the difference
            between offering the act and merely mentioning that it exists.
          */}
          {canInvent && (
            <li
              role="option"
              aria-selected={typed === needle}
              onMouseDown={(e) => {
                e.preventDefault()
                invent()
              }}
              className={`cursor-pointer border-b border-line px-4 py-2.5 ${
                matches.length === 0 ? 'bg-royal-tint' : ''
              }`}
            >
              <p className="text-sm font-semibold text-royal">
                Use “{needle}” as a new role
              </p>
              <p className="mt-0.5 text-xs text-ink-muted">
                {/*
                  What it will be able to do, said before it is made. A role is
                  a set of powers, and one created with none would sign its
                  holder in to a blank app — so the fact that it copies the
                  standard office set is the reassurance that matters here.
                */}
                Added to this office with the same powers every office role has. It will be on the
                list for the next account.
              </p>
            </li>
          )}

          {matches.length === 0 &&
            !canInvent &&
            /*
              Two empties, two sentences.

              An empty list read "No role matches “”. Clear the box to see all
              0." while the roles were still arriving — a definite answer given
              about data that had not turned up yet, and the reader's only
              move (clear the box) was already done. Nothing is not the same
              as nothing yet.
            */
            (roles.length === 0 ? (
              <li className="px-4 py-3 text-sm text-ink-muted">Roles are still loading…</li>
            ) : (
              <li className="px-4 py-3 text-sm text-ink-muted">
                {allowTyped && needle.length > 0 && needle.length < 3
                  ? `“${needle}” is too short for a role name — three characters at least.`
                  : `No role matches “${needle}”. Clear the box to see all ${roles.length}.`}
              </li>
            ))}
          {matches.map((role, index) => {
            const taken = !role.available && role.name !== currentRole

            /*
              Two headings, drawn between the groups rather than around them.

              A <ul role="listbox"> may only hold options, so a nested list
              with its own heading would either break the role or need a
              `group` wrapper per side — for two groups of two or three, a
              plain separator row carries the same meaning at a fraction of
              the markup. `aria-hidden`, because the reader arrowing through
              options should hear roles, not furniture.
            */
            const heading =
              usedHere === 0 ? null : index === 0 ? 'Used in this office' : index === usedHere ? 'Other office roles' : null

            return (
              <Fragment key={role.name}>
                {heading && (
                  <li
                    aria-hidden="true"
                    className="border-t border-line px-4 pb-1 pt-2.5 text-[11px] font-semibold uppercase tracking-wider text-ink-muted first:border-t-0 first:pt-1"
                  >
                    {heading}
                  </li>
                )}
              <li
                id={`${id}-opt-${index}`}
                role="option"
                aria-selected={role.name === value}
                aria-disabled={taken || undefined}
                onMouseEnter={() => setActive(index)}
                onMouseDown={(e) => {
                  // Before blur, so the click is not eaten by the list closing.
                  e.preventDefault()
                  take(role)
                }}
                className={`cursor-pointer px-4 py-2.5 ${
                  index === active ? 'bg-royal-tint' : ''
                } ${taken ? 'cursor-not-allowed opacity-60' : ''}`}
              >
                <p className="text-sm font-semibold text-ink">
                  {role.label}
                  {role.name === value && <span className="ml-2 text-xs text-royal">chosen</span>}
                  {taken && <span className="ml-2 text-xs text-ink-muted">already assigned</span>}
                </p>
                {role.description && (
                  <p className="mt-0.5 text-xs text-ink-muted">{role.description}</p>
                )}
              </li>
              </Fragment>
            )
          })}
        </ul>
      )}

      <FieldError message={error} />

      {disabled && disabledReason && (
        <p id={`${id}-why`} className="mt-1 text-xs text-ink-muted">
          {disabledReason}
        </p>
      )}
      {!disabled && chosen?.description && (
        <p className="mt-1 text-xs text-ink-muted">{chosen.description}</p>
      )}

      {/*
        The offer, said once under a closed box.

        Without it, being able to type a title is a feature only somebody who
        already tried it would find — the box looks like every other picker on
        the form. One line, and only where it can actually be taken up.
      */}
      {!disabled && !open && allowTyped && !typed && !chosen && (
        <p className="mt-1 text-xs text-ink-muted">
          Not on the list? Type the office&apos;s own job title and use it as a new role.
        </p>
      )}

      {/*
        And once it has been taken up, what is about to happen — a reader who
        typed a title and saw the box simply accept it has no way to tell that
        a role is being created rather than matched.
      */}
      {!disabled && typed && (
        <p className="mt-1 text-xs text-amber-800">
          <span className="font-semibold">“{typed}”</span> will be added as a new role for this
          office, with the same powers every office role has.
        </p>
      )}
    </div>
  )
}
