import { useEffect, useId, useRef, useState } from 'react'
import type { ClipboardEvent, KeyboardEvent } from 'react'
import { canonicalMobile, mobileSubscriberDigits } from '../lib/phone'

/*
 * ── Item 10 · the two numbers a business is reached on ────────────────────
 *
 * The address section asks for a landline AND a mobile, and the client asked
 * for both to be FORMATTED rather than free text:
 *
 *   · the mobile is written +63, not the eleven-digit 09XXXXXXXXX form
 *   · the landline groups its first four digits, which is its area code
 *
 * Both were single text boxes with a placeholder, and a placeholder is not a
 * format — it is a sentence somebody has to obey from memory while typing. The
 * mobile box's placeholder actively taught the wrong shape ("09XX XXX XXXX"),
 * which is the one thing this item says must not appear.
 *
 * ── Why this is built out of TinInput's parts ─────────────────────────────
 *
 * TinInput is the in-repo precedent for a segmented input and it already paid
 * for the three mistakes this kind of control makes: unnamed boxes that a
 * screen reader reads as "edit text" four times over (WCAG 3.3.2), a paste that
 * `maxLength` silently truncates to the first group, and a caret that loses a
 * race against fast typing. `SegmentedDigits` below is that behaviour with the
 * group sizes lifted out, so a fix to any of it lands in one place instead of
 * three. If this ever needs to change, change it here and check TinInput —
 * folding TinInput into this component was deliberately NOT done, because the
 * TIN's last group is variable-width (a 3-to-5 digit branch code) and pulling
 * that rule in here would make every caller carry it.
 */

/* ── The shared segmented control ──────────────────────────────────────── */

function SegmentedDigits({
  /** Digits per box, in order. The last box takes any overflow on paste. */
  sizes,
  /** One accessible name per box. Same length as `sizes` — see GROUP_LABELS. */
  labels,
  groups,
  onGroups,
  onBlur,
  invalid,
  /** Rendered between boxes, decoratively. A screen reader must not read it. */
  separator,
  /**
   * Strip a prefix off digits arriving at the FIRST box, before they are laid
   * out across the groups.
   *
   * It has to happen here rather than on the way out, and the mobile field is
   * why. Pasting "09171234567" into box one distributes eleven digits into
   * three boxes sized 3-3-4, which drops the last one and lays the rest out as
   * 091 | 712 | 3456 — a different number, one digit short, that looks like it
   * worked. Normalising first means the ten digits that matter are what gets
   * distributed. Only box one, because digits typed into a later box are the
   * middle of a number and have no prefix to lose.
   */
  normalise,
  idPrefix,
}: {
  sizes: number[]
  labels: string[]
  groups: string[]
  onGroups: (next: string[]) => void
  onBlur?: () => void
  invalid?: boolean
  separator?: string
  normalise?: (digits: string) => string
  idPrefix: string
}) {
  const boxes = useRef<(HTMLInputElement | null)[]>([])
  const last = sizes.length - 1

  /*
   * Move to another box and put the caret where the next keystroke should land.
   *
   * Synchronous, and that is load-bearing — TinInput learned it the hard way.
   * Deferring the caret to a frame loses a race against fast typing and lands
   * digits in the wrong group, which for a phone number means a number that
   * looks plausible and rings nobody.
   */
  function focusBox(index: number, caret: 'start' | 'end' | 'select') {
    const box = boxes.current[index]
    if (!box) return
    box.focus()
    // Landing on a full box with the caret at its end means the next keypress
    // does nothing at all (maxLength is reached). Selecting means it replaces.
    if (caret === 'select') {
      box.select()

      return
    }
    const at = caret === 'end' ? box.value.length : 0
    box.setSelectionRange(at, at)
  }

  /** Spread digits across the boxes from `start`, one group each. */
  function distribute(start: number, digits: string) {
    const next = [...groups]
    let rest = start === 0 && normalise ? normalise(digits) : digits
    let index = start
    while (rest.length > 0 && index < sizes.length) {
      next[index] = rest.slice(0, sizes[index])
      rest = rest.slice(sizes[index])
      index += 1
    }
    onGroups(next)

    const lastWritten = Math.min(index - 1, last)
    const spilled = next[lastWritten].length >= sizes[lastWritten] && lastWritten < last
    focusBox(spilled ? lastWritten + 1 : lastWritten, 'end')
  }

  function handleChange(index: number, raw: string) {
    // Digits only. Anything else is a bracket, dash or space somebody typed out
    // of habit for a phone number, and dropping it is kinder than an error.
    // Then the prefix, so that typing a number the long way round converges on
    // the same result as pasting it — a leading 0 in box one is a trunk code
    // and never a digit of the number.
    const cleaned = raw.replace(/\D/g, '')
    const digits = index === 0 && normalise ? normalise(cleaned) : cleaned

    // Over-typing a full box spills forward rather than being swallowed.
    if (digits.length > sizes[index] && index < last) {
      distribute(index, digits)

      return
    }

    const next = [...groups]
    next[index] = digits.slice(0, sizes[index])
    onGroups(next)

    // Auto-advance, only off a box that has just been FILLED — so backspacing
    // and retyping the last digit does not feel like the field is running away.
    if (next[index].length === sizes[index] && index < last) focusBox(index + 1, 'select')
  }

  function handleKeyDown(index: number, e: KeyboardEvent<HTMLInputElement>) {
    const box = e.currentTarget
    const atStart = box.selectionStart === 0 && box.selectionEnd === 0
    const atEnd = box.selectionStart === box.value.length && box.selectionEnd === box.value.length

    /*
     * Backspace in an empty box steps back. Auto-advance moves focus forward on
     * its own, so without this somebody who mistypes the last digit of a group
     * lands in an empty box and finds Backspace does nothing — the box they
     * want is behind them and only the mouse can reach it. It moves the caret
     * and stops there: one keypress, one visible effect.
     */
    if (e.key === 'Backspace' && box.value === '' && index > 0) {
      e.preventDefault()
      focusBox(index - 1, 'end')

      return
    }

    // Arrows cross the boundaries, so the boxes navigate like the one field
    // they represent.
    if (e.key === 'ArrowLeft' && atStart && index > 0) {
      e.preventDefault()
      focusBox(index - 1, 'end')
    }
    if (e.key === 'ArrowRight' && atEnd && index < last) {
      e.preventDefault()
      focusBox(index + 1, 'start')
    }
  }

  function handlePaste(index: number, e: ClipboardEvent<HTMLInputElement>) {
    const digits = e.clipboardData.getData('text').replace(/\D/g, '')
    if (!digits) return

    /*
     * Paste is handled here rather than left to the browser, and this is the
     * whole reason the branch exists: `maxLength` truncates a paste to fit the
     * box, so a whole number dropped into the first box keeps its first group
     * and loses the rest without a word. A phone number is copied off a card or
     * a message far more often than it is typed, so this is the common path,
     * not the edge.
     *
     * A paste long enough to be a WHOLE number fills from the first box however
     * it was aimed; anything shorter is a fragment and lands where it was put.
     */
    e.preventDefault()
    const whole = sizes.reduce((a, b) => a + b, 0)
    distribute(digits.length >= whole ? 0 : index, digits)
  }

  return (
    <div
      className="flex items-center gap-1.5"
      /*
       * One touch point for "finished with this question". Blurring a box to
       * reach the next one is not leaving the field, and treating it as such
       * would flash a format error between every group.
       */
      onBlur={(e) => {
        if (!e.currentTarget.contains(e.relatedTarget as Node | null)) onBlur?.()
      }}
    >
      {sizes.map((size, index) => (
        <div key={index} className="flex min-w-0 flex-1 items-center gap-1.5">
          {index > 0 && separator && (
            <span aria-hidden="true" className="shrink-0 text-ink-muted">
              {separator}
            </span>
          )}
          <input
            ref={(el) => {
              boxes.current[index] = el
            }}
            id={`${idPrefix}-${index}`}
            value={groups[index] ?? ''}
            onChange={(e) => handleChange(index, e.target.value)}
            onKeyDown={(e) => handleKeyDown(index, e)}
            onPaste={(e) => handlePaste(index, e)}
            // Tabbing into a full box selects it, so the next digit replaces the
            // group instead of being swallowed by maxLength. A CLICK is
            // unaffected — the browser's mouse selection lands after focus.
            onFocus={(e) => e.currentTarget.select()}
            inputMode="numeric"
            autoComplete="off"
            maxLength={size}
            aria-label={labels[index]}
            aria-invalid={Boolean(invalid)}
            className={`${boxCls} ${invalid ? 'ring-2 ring-s-red' : ''}`}
          />
        </div>
      ))}
    </div>
  )
}

/* ── The mobile number ─────────────────────────────────────────────────── */

/** Ten digits after +63, printed 917 123 4567. */
const MOBILE_SIZES = [3, 3, 4]

const MOBILE_LABELS = [
  'Mobile number after +63, first three digits',
  'Mobile number after +63, second three digits',
  'Mobile number after +63, last four digits',
]

export function MobileNumberInput({
  legend,
  value,
  onChange,
  onBlur,
  error,
  hintId,
  errorId,
}: {
  /** The visible question. Rendered as a real <legend>, not a floating label. */
  legend: string
  /** Whatever the form holds — canonical +63, or a prefilled 09 form. */
  value: string
  /** Always emits the canonical +63 form, or '' for an empty field. */
  onChange: (value: string) => void
  onBlur?: () => void
  error?: string
  hintId?: string
  errorId?: string
}) {
  const groupId = useId()
  const [groups, setGroups] = useState<string[]>(() => splitMobile(value))

  /*
   * The boxes are the truth while somebody is typing and `value` is what that
   * truth is published as — TinInput's note explains why deriving them on every
   * render empties a box under the cursor. But the FORM still owns the value,
   * so an edit from outside (a draft loading, a renewal prefilling, Clear All)
   * has to win. Compared on subscriber digits, which is exactly the information
   * the boxes carry, so our own emissions round-trip and never re-seed — and a
   * prefilled 09 number does not fight the boxes showing it as +63.
   */
  useEffect(() => {
    if (mobileSubscriberDigits(value) !== groups.join('')) setGroups(splitMobile(value))
  }, [value, groups])

  function publish(next: string[]) {
    setGroups(next)
    onChange(canonicalMobile(next.join('')))
  }

  return (
    <fieldset
      /*
       * A real fieldset, TinInput's reason: without it a screen-reader user
       * meets three unrelated numeric fields and nothing anywhere saying they
       * add up to a mobile number. The error is described on the GROUP so it is
       * heard once on entering the question rather than three times crossing it.
       */
      aria-describedby={[hintId, error ? errorId : null].filter(Boolean).join(' ') || undefined}
      className="min-w-0 border-0 p-0"
    >
      <legend className={legendCls}>{legend}</legend>
      <div className="flex items-center gap-2">
        {/*
          * `+63` is shown, not typed, and that is the item: the prefix is a
          * property of the control, so the 09 form has nowhere to be entered.
          * aria-hidden because all three boxes carry "after +63" in their own
          * names — reading it a fourth time as loose text says nothing new.
          */}
        <span
          aria-hidden="true"
          className="tnum shrink-0 rounded-lg border border-input-border bg-line/40 px-2.5 py-2.5 text-sm font-medium text-ink-secondary"
        >
          +63
        </span>
        <div className="min-w-0 flex-1">
          <SegmentedDigits
            sizes={MOBILE_SIZES}
            labels={MOBILE_LABELS}
            groups={groups}
            onGroups={publish}
            onBlur={onBlur}
            invalid={Boolean(error)}
            normalise={mobileSubscriberDigits}
            idPrefix={`${groupId}-mobile`}
          />
        </div>
      </div>
    </fieldset>
  )
}

function splitMobile(value: string): string[] {
  const digits = mobileSubscriberDigits(value)

  return [digits.slice(0, 3), digits.slice(3, 6), digits.slice(6, 10)]
}

/* ── The landline ──────────────────────────────────────────────────────── */

/**
 * Four for the area code, then the number.
 *
 * The client's wording is that the landline "groups its first four digits (area
 * code)". A Philippine area code is two to four digits — 02 for Metro Manila,
 * 032 for Cebu, 6 for none at all if somebody writes only the local number — so
 * four is the widest it gets and the box is sized to hold the longest rather
 * than to demand it. The second box takes the subscriber number, which is seven
 * digits outside Metro Manila and eight inside it.
 *
 * The split is POSITIONAL on the way back in: the first four digits of a stored
 * number go in the first box. It has to be, because nothing in "0281234567"
 * says where the area code ends. That means one of the 788 addresses on file
 * holding a bare eight-digit local number will read back with its first four
 * digits sitting in the area-code box — visibly shifted, which the applicant
 * can correct, rather than silently re-grouped into a different number.
 */
const LANDLINE_SIZES = [4, 8]

const LANDLINE_LABELS = ['Landline area code, up to four digits', 'Landline number']

/** Area code and number, space-joined; a trailing empty group is dropped. */
function joinLandline(groups: string[]): string {
  return groups.filter((g) => g !== '').join(' ')
}

function splitLandline(value: string): string[] {
  const digits = value.replace(/\D/g, '')

  return [digits.slice(0, 4), digits.slice(4, 12)]
}

export function LandlineInput({
  legend,
  value,
  onChange,
  onBlur,
  error,
  hintId,
  errorId,
}: {
  legend: string
  value: string
  onChange: (value: string) => void
  onBlur?: () => void
  error?: string
  hintId?: string
  errorId?: string
}) {
  const groupId = useId()
  const [groups, setGroups] = useState<string[]>(() => splitLandline(value))

  // Same contract as the mobile above: the boxes lead while typing, the form
  // wins when the value changes from outside. Compared on digits.
  useEffect(() => {
    if (value.replace(/\D/g, '') !== groups.join('')) setGroups(splitLandline(value))
  }, [value, groups])

  function publish(next: string[]) {
    setGroups(next)
    onChange(joinLandline(next))
  }

  return (
    <fieldset
      aria-describedby={[hintId, error ? errorId : null].filter(Boolean).join(' ') || undefined}
      className="min-w-0 border-0 p-0"
    >
      <legend className={legendCls}>{legend}</legend>
      <SegmentedDigits
        sizes={LANDLINE_SIZES}
        labels={LANDLINE_LABELS}
        groups={groups}
        onGroups={publish}
        onBlur={onBlur}
        invalid={Boolean(error)}
        separator="–"
        idPrefix={`${groupId}-landline`}
      />
    </fieldset>
  )
}

/*
 * Matches Proto's FieldLabel exactly, so a fieldset question and a plain
 * labelled input sitting side by side in the same grid read as one row of
 * questions rather than two kinds of thing.
 */
const legendCls = 'mb-1.5 block text-[13px] font-semibold text-ink'

/*
 * The prototype's filled input. `tnum` because a phone number is read digit by
 * digit and proportional figures make the same count look a different width in
 * each box.
 */
const boxCls =
  'tnum w-full min-w-0 rounded-lg border border-input-border bg-input px-2 py-2.5 text-center text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal'
