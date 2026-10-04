/**
 * When the City answers its messages — Malabon City Hall's own week.
 *
 * ── Why this is a module and not a sentence in the composer ────────────────
 *
 * The client asked for it in one place: *"Put a message somewhere that offices
 * can only reply within work hours"* [1 October 2026]. One place is where it
 * starts. The hours themselves are a fact about the City, and a fact that is
 * typed into a view is a fact that goes stale in every other view that later
 * needs it — the applicant's composer, the staff composer, an auto-reply, a
 * tooltip on a thread that has been quiet since Thursday.
 *
 * So the hours are declared once, the sentence is built once, and "is the
 * counter open right now" is answerable rather than something each screen
 * works out from `new Date()` and gets subtly different.
 *
 * ── The week is four days, and that is not a typo ──────────────────────────
 *
 * 7:00 AM to 6:00 PM, MONDAY TO THURSDAY. Malabon City Hall runs a compressed
 * week: four longer days rather than five shorter ones, which is why the day
 * starts an hour before the national norm and ends two hours after it, and why
 * Friday is not on this list. Confirmed by the client when the checklist left
 * the opening hour out.
 *
 * Nothing here enforces anything. The API accepts a message at any hour and
 * always has — an applicant writing at midnight on a Saturday should be able
 * to get the question out of their head and into the thread. This only says
 * when an answer is to be expected, because the alternative is an applicant
 * refreshing a thread on a Friday evening wondering whether they have been
 * ignored.
 */

/** First and last hour of the counter, as 24-hour clock. */
export const OPENS_HOUR = 7

export const CLOSES_HOUR = 18

/** 1 = Monday … 4 = Thursday, matching `Date.getDay()`. */
export const OPEN_DAYS = [1, 2, 3, 4]

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

/** `7:00 AM`, `6:00 PM` — the way a notice prints an hour. */
function clock(hour24: number): string {
  const suffix = hour24 >= 12 ? 'PM' : 'AM'
  const hour = hour24 % 12 === 0 ? 12 : hour24 % 12

  return `${hour}:00 ${suffix}`
}

/** `Monday to Thursday, 7:00 AM – 6:00 PM`. Always the same words. */
export const OFFICE_HOURS_LABEL = `Monday to Thursday, ${clock(OPENS_HOUR)} – ${clock(CLOSES_HOUR)}`

/**
 * Is an office answering right now?
 *
 * Local time, deliberately: the applicant, the office and the server all sit
 * in one city, and a notice about a counter down the road should agree with
 * the clock on the reader's own wall.
 */
export function isOpenNow(now: Date = new Date()): boolean {
  return OPEN_DAYS.includes(now.getDay()) && now.getHours() >= OPENS_HOUR && now.getHours() < CLOSES_HOUR
}

/**
 * When the counter next opens, named the way a person would say it.
 *
 * "tomorrow morning" while it is still Sunday-to-Wednesday night, "on Monday"
 * across the long weekend, "later this morning" before seven on a working day.
 * The point is that the reader can tell how long they are waiting without
 * counting days on the office-hours line themselves.
 */
export function nextOpening(now: Date = new Date()): string {
  const today = now.getDay()
  const open = OPEN_DAYS.includes(today)

  // Before the counter opens on a day it will open.
  if (open && now.getHours() < OPENS_HOUR) return 'later this morning'

  // After it closed, or on a day it never opens: find the next day that does.
  for (let ahead = 1; ahead <= 7; ahead += 1) {
    const day = (today + ahead) % 7
    if (!OPEN_DAYS.includes(day)) continue

    return ahead === 1 ? 'tomorrow morning' : `on ${DAY_NAMES[day]}`
  }

  // Unreachable while OPEN_DAYS has a day in it; a sentence, not a crash.
  return 'the next working day'
}

/**
 * The whole notice, as one sentence, for whichever state the clock is in.
 *
 * Both halves always say the hours, because a reader who arrives while the
 * counter is open still wants to know when it closes — a notice that only
 * appears out of hours is a notice nobody sees until they are already waiting.
 */
export function officeHoursNote(now: Date = new Date()): { open: boolean; text: string } {
  const open = isOpenNow(now)

  return {
    open,
    text: open
      ? `Offices reply ${OFFICE_HOURS_LABEL}. You can send a message at any time.`
      : `Offices are closed now — they reply ${OFFICE_HOURS_LABEL}. Send your message now and expect an answer ${nextOpening(now)}.`,
  }
}
