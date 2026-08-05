import { useEffect, useId, useRef, useState } from 'react'
import type { ClipboardEvent, KeyboardEvent } from 'react'

/*
 * ── Item 105 · the TIN, entered as four boxes ─────────────────────────────
 *
 * A Philippine TIN is printed in four groups of three — 123-456-789-000 —
 * where the first nine digits are the taxpayer and the last three are the
 * branch code. It used to be one text box with `000-000-000-000` as a
 * placeholder, and a placeholder is not a format: applicants typed the dashes
 * themselves, typed them in the wrong places, or typed twelve bare digits and
 * had no way to check they had counted right.
 *
 * Four boxes make the shape a property of the control instead of a rule the
 * applicant has to remember, and a miscount becomes visible — a group with two
 * digits in it looks wrong, where `12345678900` does not.
 *
 * ── What this component is NOT allowed to change ──────────────────────────
 *
 * The wire format. `onChange` emits the same dash-joined string the single
 * field emitted, BusinessController::normalizeTin canonicalises it exactly as
 * before, and businesses.tin keeps storing "123-456-789-000". This is an input
 * control and nothing else — no migration, no new API rule, and every filing
 * already in the register still reads back into these boxes (see splitTin).
 */

/** Digits per box. The TIN is printed in threes; so is this. */
const GROUP = 3

/** Four boxes: three for the nine-digit TIN, one for the branch code. */
const GROUPS = 4

/*
 * Each box gets its own name, because "edit text, edit text, edit text, edit
 * text" is what four unlabelled boxes sound like and it is the single most
 * common way a split input fails a screen-reader user (WCAG 3.3.2). The names
 * say which digits of the printed number the box holds, so somebody reading
 * their BIR certificate aloud knows where they are.
 */
const GROUP_LABELS = [
  'TIN, first three digits',
  'TIN, second three digits',
  'TIN, third three digits',
  'TIN branch code, three digits',
]

/**
 * Stored value in, four boxes out.
 *
 * Reads the digits and nothing else, so every shape the API has ever accepted
 * comes back correctly when a draft or a renewal is reopened: the canonical
 * "123-456-789-000", the nine-digit "111-111-111" that seven businesses in the
 * register are actually filed under (branch box left empty), and the bare
 * "123456789000" somebody typed before the dashes were added for them.
 *
 * The last box takes everything from digit 10 onward rather than exactly three.
 * The API accepts a branch code of three to five digits, and a longer one is
 * rare but real — truncating it here to make the boxes tidy would silently
 * corrupt a filing on the way to the screen. `maxLength` only governs what a
 * person may TYPE, so an over-long branch code still displays in full and is
 * only shortened if the applicant edits that box themselves.
 */
function splitTin(value: string): string[] {
  const digits = value.replace(/\D/g, '')
  return [
    digits.slice(0, GROUP),
    digits.slice(GROUP, GROUP * 2),
    digits.slice(GROUP * 2, GROUP * 3),
    digits.slice(GROUP * 3),
  ]
}

/**
 * Four boxes out, stored value in.
 *
 * Trailing empty boxes are dropped so a nine-digit TIN emits "123-456-789" and
 * not "123-456-789-", which the API's regex would reject. A gap in the MIDDLE
 * is deliberately kept as an empty segment: "123--789" is six digits, it fails
 * validation, and it should — quietly closing the gap would turn a half-typed
 * number into a different, plausible-looking one.
 */
function joinTin(groups: string[]): string {
  const trimmed = [...groups]
  while (trimmed.length > 0 && trimmed[trimmed.length - 1] === '') trimmed.pop()

  return trimmed.join('-')
}

export function TinInput({
  value,
  onChange,
  onBlur,
  error,
  hintId,
  errorId,
}: {
  /** The dash-joined TIN the form holds — unchanged from the single-field days. */
  value: string
  onChange: (value: string) => void
  /** Fired once, when focus leaves the whole group — not on every box. */
  onBlur?: () => void
  error?: string
  hintId?: string
  errorId?: string
}) {
  const groupId = useId()

  /*
   * The boxes hold their own state rather than being derived from `value` on
   * every render, and they have to.
   *
   * Deriving them would mean re-splitting the joined digits each keystroke:
   * type "12" in box 1, move to box 2, type "3", and the three digits re-split
   * as ["123", "", "", ""] — box 2 empties under the cursor and box 1 gains a
   * digit the applicant never put there. So the boxes are the truth while the
   * applicant is typing, and `value` is what that truth is published as.
   */
  const [groups, setGroups] = useState<string[]>(() => splitTin(value))
  const boxes = useRef<(HTMLInputElement | null)[]>([])

  /*
   * ...but the form still owns the value, so an edit from OUTSIDE has to win:
   * a draft loading, a renewal prefilling from a prior permit, or "Clear All".
   * The comparison is on digits, which is exactly the information the boxes
   * carry — so our own emissions round-trip and never re-seed (which would
   * fight the applicant's cursor), while a genuinely different number does.
   */
  useEffect(() => {
    // splitTin keeps every digit it is given, so a re-seed always satisfies
    // this test on the next pass — the check cannot chase its own tail.
    if (value.replace(/\D/g, '') !== groups.join('')) setGroups(splitTin(value))
  }, [value, groups])

  function publish(next: string[]) {
    setGroups(next)
    onChange(joinTin(next))
  }

  /*
   * Move to another box and put the caret where the next keystroke should land.
   *
   * Synchronous, and that is load-bearing. An earlier version deferred the
   * caret to a requestAnimationFrame, which lost a race against fast typing:
   * the frame ran a keystroke or two later and dropped the caret back to
   * position 0, so "123456789000" typed straight through came out as
   * 123-645-789-000 — the right digits in the wrong order, which is exactly the
   * kind of wrong a TIN must never be. focus() fires onFocus → select()
   * synchronously, so setting the range right after it wins.
   */
  function focusBox(index: number, caret: 'start' | 'end' | 'select') {
    const box = boxes.current[index]
    if (!box) return
    box.focus()
    // 'select' is for auto-advance: landing on a box that already holds three
    // digits with the caret at its end means the next keypress does nothing at
    // all (maxLength is reached). Selecting means it replaces, which is what
    // somebody correcting a group is trying to do.
    if (caret === 'select') {
      box.select()

      return
    }
    const at = caret === 'end' ? box.value.length : 0
    box.setSelectionRange(at, at)
  }

  /**
   * Spread digits across the boxes from `start`, one group each.
   *
   * Used by both paste and over-typing, because they are the same event as far
   * as the boxes are concerned: more digits arrived than this box can hold.
   */
  function distribute(start: number, digits: string) {
    const next = [...groups]
    let rest = digits
    let index = start
    while (rest.length > 0 && index < GROUPS) {
      // The last box is the branch code and takes the remainder, so pasting a
      // long branch code does not silently drop its tail.
      next[index] = index === GROUPS - 1 ? rest : rest.slice(0, GROUP)
      rest = index === GROUPS - 1 ? '' : rest.slice(GROUP)
      index += 1
    }
    publish(next)

    // Land on the box the applicant would type into next, not back at the
    // start — a full TIN pasted in leaves focus on the branch code, and a
    // half-TIN leaves it on the first box still waiting for digits.
    const lastWritten = Math.min(index - 1, GROUPS - 1)
    const spilledOver = next[lastWritten].length >= GROUP && lastWritten < GROUPS - 1
    focusBox(spilledOver ? lastWritten + 1 : lastWritten, 'end')
  }

  function handleChange(index: number, raw: string) {
    // Digits only. Anything else is a dash somebody typed out of habit, and
    // dropping it silently is kinder than an error about a character they were
    // told for years to type.
    const digits = raw.replace(/\D/g, '')

    // Over-typing a full box spills forward rather than being swallowed.
    if (digits.length > GROUP && index < GROUPS - 1) {
      distribute(index, digits)

      return
    }

    const next = [...groups]
    next[index] = index === GROUPS - 1 ? digits : digits.slice(0, GROUP)
    publish(next)

    /*
     * Auto-advance. Without it four boxes are strictly worse than one — every
     * third digit would need a Tab the single field never asked for. It fires
     * only on a box that has just been FILLED, so backspacing to two digits
     * and retyping the third does not feel like the field is running away.
     */
    if (next[index].length === GROUP && index < GROUPS - 1) focusBox(index + 1, 'select')
  }

  function handleKeyDown(index: number, e: KeyboardEvent<HTMLInputElement>) {
    const box = e.currentTarget
    const atStart = box.selectionStart === 0 && box.selectionEnd === 0
    const atEnd = box.selectionStart === box.value.length && box.selectionEnd === box.value.length

    /*
     * Backspace in an empty box steps back. Auto-advance moves focus forward on
     * its own, so without this the applicant who mistypes the third digit lands
     * in an empty box and finds Backspace does nothing at all — the box they
     * want to correct is behind them and only the mouse can reach it.
     *
     * It moves the caret and stops there rather than also deleting: one
     * keypress, one visible effect. The next Backspace deletes, in the box the
     * applicant can now see the cursor in.
     */
    if (e.key === 'Backspace' && box.value === '' && index > 0) {
      e.preventDefault()
      focusBox(index - 1, 'end')

      return
    }

    // Arrows cross the boundaries, so the four boxes navigate like the one
    // field they represent.
    if (e.key === 'ArrowLeft' && atStart && index > 0) {
      e.preventDefault()
      focusBox(index - 1, 'end')
    }
    if (e.key === 'ArrowRight' && atEnd && index < GROUPS - 1) {
      e.preventDefault()
      focusBox(index + 1, 'start')
    }
  }

  function handlePaste(index: number, e: ClipboardEvent<HTMLInputElement>) {
    const digits = e.clipboardData.getData('text').replace(/\D/g, '')
    if (!digits) return

    /*
     * Paste is handled here rather than left to the browser, and that is the
     * whole reason this branch exists: `maxLength={3}` truncates a paste to fit
     * the box, so "123-456-789-000" dropped into the first box would become
     * "123" and the other nine digits would be gone without a word. That is the
     * classic failure of split inputs and the one most likely to be hit — the
     * TIN is a number people copy off an email or a certificate, not one they
     * remember.
     *
     * A paste of nine digits or more is a whole TIN however it was aimed, so it
     * always fills from the first box. Anything shorter is a fragment and lands
     * where the applicant put it.
     */
    e.preventDefault()
    distribute(digits.length >= GROUP * 3 ? 0 : index, digits)
  }

  return (
    <fieldset
      /*
       * A real fieldset, so the four boxes are announced as one question with
       * one name. Without it a screen-reader user meets four unrelated numeric
       * fields and no statement anywhere that they add up to a TIN.
       *
       * The error is described on the GROUP, not on each box, so it is heard
       * once on entering the question instead of four times on the way across
       * it — which is the difference between a message and a nag.
       */
      aria-describedby={[hintId, error ? errorId : null].filter(Boolean).join(' ') || undefined}
      className="min-w-0 border-0 p-0"
    >
      <legend className="mb-1.5 block text-[13px] font-semibold text-ink">
        Tax Identification Number (TIN)
        <span className="text-s-red"> *</span>
      </legend>

      <div
        className="flex items-center gap-1.5"
        /*
         * One touch point for "the applicant has finished with this question".
         * Blurring a box to reach the next one is not leaving the field, and
         * treating it as such would flash a format error between every group.
         */
        onBlur={(e) => {
          if (!e.currentTarget.contains(e.relatedTarget as Node | null)) onBlur?.()
        }}
      >
        {groups.map((group, index) => (
          <div key={index} className="flex min-w-0 flex-1 items-center gap-1.5">
            {index > 0 && (
              // Decorative: the separator a screen reader must not read as
              // "minus" four times. The group's name already says it is a TIN.
              <span aria-hidden="true" className="shrink-0 text-ink-muted">
                –
              </span>
            )}
            <input
              ref={(el) => {
                boxes.current[index] = el
              }}
              id={`${groupId}-tin-${index}`}
              value={group}
              onChange={(e) => handleChange(index, e.target.value)}
              onKeyDown={(e) => handleKeyDown(index, e)}
              onPaste={(e) => handlePaste(index, e)}
              /*
               * Tabbing into a full box selects it, so the next digit replaces
               * the group instead of being swallowed by maxLength. A CLICK is
               * unaffected — the browser's own mouse selection lands after the
               * focus event and puts the caret where the pointer was, which is
               * what somebody clicking between two digits is asking for.
               */
              onFocus={(e) => e.currentTarget.select()}
              inputMode="numeric"
              autoComplete="off"
              maxLength={GROUP}
              aria-label={GROUP_LABELS[index]}
              aria-invalid={Boolean(error)}
              className={`${inputBoxCls} ${error ? 'ring-2 ring-s-red' : ''}`}
            />
          </div>
        ))}
      </div>
    </fieldset>
  )
}

/*
 * The prototype's filled input, centred and narrowed to the three digits it
 * holds. `tnum` because a TIN is a number read digit by digit and proportional
 * figures make three of them look like a different width in each box.
 */
const inputBoxCls =
  'tnum w-full min-w-0 rounded-lg border border-input-border bg-input px-1 py-2.5 text-center text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal'
