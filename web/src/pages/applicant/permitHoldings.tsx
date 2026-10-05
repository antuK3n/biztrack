import { useId, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  AlertCircleIcon,
  DownloadIcon,
  EyeIcon,
} from '../../components/icons'
import { type SortFilterOption } from '../../components/ui/Proto'
import { businessName, formatDate } from '../../lib/format'
import { permits as permitsApi } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { PageMeta, Permit } from '../../lib/types'

/*
 * What an applicant holds, and how a business's row is drawn.
 *
 * ── Why this is its own module ─────────────────────────────────
 *
 * All of this lived inside ProfilePage, where the permits were a section at the
 * bottom of the account record. They have a page of their own now [client,
 * 28 September 2026: *"create a page dedicated for approved permits for more
 * visibility and accessibility"*], and Profile still wants the count for its
 * summary line — so the loading, the grouping and the row live here and both
 * screens read them.
 *
 * Nothing about the rows changed in the move.
 */

/**
 * A permit is "nearing expiry" inside this window. Matches the renewal window
 * the register warns on elsewhere, so a business does not read as fine here and
 * as urgent on the dashboard.
 */
export const NEARING_DAYS = 30

/*
 * `/permits` is paginated (default 50, hard cap 200) because unpaged it once
 * answered 4,122 rows. That bound is right for a list with paging controls and
 * wrong here: this screen groups the rows and then prints "N businesses total",
 * so a page-one-only read would quietly under-count a landlord with a dozen
 * businesses. Walk the pages instead. The ceiling stops a pathological account
 * from turning one screen into an unbounded fetch loop.
 */
const MAX_PERMIT_PAGES = 10

async function loadAllPermits(): Promise<{
  permits: Permit[]
  unbilled: PageMeta['unbilled_fees']
}> {
  const all: Permit[] = []
  let unbilled: PageMeta['unbilled_fees']

  for (let page = 1; page <= MAX_PERMIT_PAGES; page++) {
    const { data, meta } = await permitsApi.page({ page, per_page: 200 })
    all.push(...data)
    // The same on every page; taken from the first and not re-read.
    if (page === 1) unbilled = meta.unbilled_fees
    if (meta.current_page >= meta.last_page) break
  }

  return { permits: all, unbilled }
}

/** What one fetch has to bring back before this page can group anything. */
export interface ProfileHoldings {
  permits: Permit[]
  /*
   * Ridden back on the FIRST page's meta, because it is a fact about the
   * owner and not about the page — every page would repeat it.
   */
  unbilled: PageMeta['unbilled_fees']
}

/**
 * Both halves of "what does this account hold", in parallel.
 *
 * `Promise.all`, not two `useAsync` hooks: the groups are built from both lists
 * at once, and two independent loading flags would let the page render a
 * business with its permits and then visibly grow a second block of rows under
 * it a moment later. One wait, one paint.
 *
 * It fetched `/permits/held` alongside this until 4 October 2026 — the
 * clearances an applicant had submitted a copy of instead of applying for.
 * The client had that route removed, so there is one feed again.
 */
export async function loadHoldings(): Promise<ProfileHoldings> {
  const paged = await loadAllPermits()

  return { permits: paged.permits, unbilled: paged.unbilled }
}

/* ── Approved Businesses ──────────────────────────────────────────────── */

export interface BusinessGroup {
  id: number
  name: string
  permits: Permit[]
  /**
   * Clearances the applicant submitted a copy of, on any filing for this
   * business. Kept in their own array rather than mixed into `permits`: every
   * derived value below — expiry, nearing, expired, flagged — is a fact about
   * an ISSUED permit, and a held copy has none of them to contribute.
   */
  /** Latest expiry in the group — the date shown when nothing is wrong. */
  latestExpiry: string | null
  /** Soonest expiry — the date shown when a renewal is due or already late. */
  soonestExpiry: string | null
  /** Something in the group expires within NEARING_DAYS but has not yet. */
  nearing: boolean
  /** Something in the group is already past its validity. */
  expired: boolean
  /** Something in the group was suspended or revoked by an officer. */
  flagged: boolean
}

export const SORTS: SortFilterOption[] = [
  { value: 'name', label: 'Business name (A–Z)' },
  { value: 'name_desc', label: 'Business name (Z–A)' },
  { value: 'expiry', label: 'Expiring soonest' },
  { value: 'expiry_desc', label: 'Expiring latest' },
  { value: 'permits', label: 'Most permits first' },
]

/** "All" stays first: SortFilter marks Filter active by comparing to `options[0]`. */
export const FILTERS: SortFilterOption[] = [
  { value: 'all', label: 'All businesses' },
  { value: 'nearing', label: `Expiring within ${NEARING_DAYS} days` },
  { value: 'expired', label: 'Has an expired permit' },
  { value: 'flagged', label: 'Suspended or revoked' },
]

/** Sorts on a nullable date without letting "no date" jump to the front. */
const NEVER = '9999-12-31'

function Triangle({ open }: { open: boolean }) {
  return (
    <svg
      width="20"
      height="20"
      viewBox="0 0 24 24"
      aria-hidden="true"
      className={`shrink-0 text-ink-secondary transition-transform ${open ? 'rotate-180' : ''}`}
    >
      <path d="M5 8h14l-7 9L5 8Z" fill="currentColor" />
    </svg>
  )
}

/*
 * The download icon in the royal permit row used to be a second link to the
 * permit page — same destination as the eye beside it, under an icon that
 * promises a file. It downloads, so the two icons mean two things.
 *
 * `label` is the whole "Business Permit for CedarBloom Café" phrase, not just
 * the permit type: an icon-only control in a list of five identical icons has
 * to say which permit it acts on, or a screen-reader user hears "download"
 * twenty times with no way to tell them apart.
 */
function PermitDownloadButton({
  permit,
  label,
  /* Muted on a past permit, so the history reads as paper rather than cover. */
  muted = false,
}: {
  permit: Permit
  label: string
  muted?: boolean
}) {
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState(false)

  async function download() {
    if (busy) return
    setBusy(true)
    setFailed(false)
    try {
      await permitsApi.pdf(permit.id, `${permit.permit_number}.pdf`)
    } catch {
      // The permit page carries the full message; here there is room for the
      // fact that it failed and an invitation to try the other route.
      setFailed(true)
    } finally {
      setBusy(false)
    }
  }

  return (
    <button
      type="button"
      onClick={download}
      // `aria-disabled`, never `disabled`. A disabled button drops out of the
      // tab order and is not announced, so a screen-reader user mid-download
      // watches the control they just pressed disappear. The second press is
      // stopped by the guard at the top of `download` instead.
      aria-disabled={busy}
      aria-label={failed ? `Download failed for ${label}. Try again` : `Download ${label} as PDF`}
      className={`shrink-0 rounded transition-opacity hover:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${
        muted ? 'text-ink-secondary focus-visible:outline-royal' : 'text-white focus-visible:outline-white'
      } ${
        busy ? 'opacity-50' : ''
      }`}
    >
      {failed ? <AlertCircleIcon size={22} /> : <DownloadIcon size={22} />}
    </button>
  )
}

/**
 * Is this certificate part of the owner's history rather than their cover?
 *
 * `superseded` is what a renewal leaves behind, `revoked` is cover withdrawn,
 * and an expired permit is one whose term simply ran out. None of the three is
 * what the business is trading on today.
 *
 * `suspended` is deliberately NOT here. A suspended permit is current — it is
 * the one the owner has to do something about — and filing it away under Past
 * would hide the heaviest thing the system does to them.
 */
function isPastPermit(permit: Permit): boolean {
  if (permit.status === 'superseded' || permit.status === 'revoked') return true

  return permit.days_until_expiry !== null && permit.days_until_expiry < 0
}

/**
 * One certificate on a business card.
 *
 * ── Why `past` changes more than a colour ────────────────────────────────
 *
 * Lifted out of the list on 4 October 2026 so the current permits and the
 * historical ones can be drawn as two lists. They were one, and a replaced
 * certificate sat at the bottom of it in the same royal blue as the live ones,
 * told apart only by a small chip. The client opened last year's Sanitary
 * Permit from that list, read its VALID UNTIL, and reported the renewal as
 * broken — *"I just renewed that sanitary form and the expiration date should
 * be Oct. 4, 2027"* — while the permit they had just been issued sat four rows
 * above with the right dates on it.
 *
 * So a past row is muted rather than merely chipped: the live permits are the
 * blue block, and the history reads as paper in a drawer. Both stay openable
 * and downloadable, which is the whole reason they are still on the page.
 */
function PermitRow({
  permit,
  business,
  past = false,
}: {
  permit: Permit
  business: string
  past?: boolean
}) {
  const typeName = permit.permit_type?.name ?? 'Permit'
  /* "Sanitary Permit for CedarBloom Café (MCB-2026-000406)" — the
     eye and the arrow are the only labels a sighted user gets, and
     neither says which of the five rows it belongs to. The number is
     on the end because a renewal leaves two permits of the SAME type
     on the same business, and then the type and the business name
     together still do not tell them apart. */
  const label = `${typeName} for ${business} (${permit.permit_number})`
  const expired = permit.days_until_expiry !== null && permit.days_until_expiry < 0
  const note = permit.status !== 'active' ? permit.status_label : expired ? 'Expired' : null
  /*
   * ── Two kinds of badge, because they are two kinds of news ──
   *
   * Every note rendered in the same outlined white, so
   * "Superseded" — the ordinary result of renewing — looked
   * exactly like "Suspended", which means the business may not
   * trade on this permit today. One is bookkeeping and the other
   * is the heaviest thing the system does to an owner.
   *
   * Filled red for the two an officer DECIDED, outline for the
   * two that are just what happened to a date. The word is still
   * there in both, so the distinction never rests on the colour
   * (DESIGN.md, Never Color Alone) — the fill is what makes it
   * findable while scrolling a long profile.
   */
  const sanctioned = permit.status === 'suspended' || permit.status === 'revoked'

  return (
    <li
      className={`flex items-center gap-3 rounded-lg px-4 py-3 sm:gap-4 sm:px-5 ${
        past ? 'border border-line bg-shell' : 'bg-royal shadow-card'
      }`}
    >
      <div className="min-w-0 flex-1">
        <span
          className={`block truncate text-base font-bold ${past ? 'text-ink-secondary' : 'text-white'}`}
        >
          {typeName}
        </span>
        {/* What a suspension waits on, the Track row's line (5 October 2026). */}
        {permit.status === 'suspended' && permit.suspended_for && (
          <span className={`block truncate text-xs ${past ? 'text-ink-muted' : 'text-white/85'}`}>
            Waiting on your {permit.suspended_for.name}
            {permit.suspended_for.office ? ` with ${permit.suspended_for.office}` : ''}
          </span>
        )}
      </div>
      {note && (
        <span
          className={`shrink-0 rounded px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wide ${
            sanctioned
              ? 'border border-s-red bg-s-red text-white'
              : past
                ? 'border border-line text-ink-muted'
                : 'border border-white/70 text-white'
          }`}
        >
          {note}
        </span>
      )}
      {/* The permit number is what an owner quotes at a counter, so
          it survives the redesign — dropped only where there is no
          width for it rather than dropped outright. */}
      <span
        className={`hidden shrink-0 text-xs font-semibold md:inline ${past ? 'text-ink-muted' : 'text-white/75'}`}
      >
        {permit.permit_number}
      </span>
      <Link
        to={`/permits/${permit.id}`}
        aria-label={`View ${label}`}
        className={`shrink-0 rounded transition-opacity hover:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${
          past ? 'text-ink-secondary focus-visible:outline-royal' : 'text-white focus-visible:outline-white'
        }`}
      >
        <EyeIcon size={22} />
      </Link>
      <PermitDownloadButton permit={permit} label={label} muted={past} />
    </li>
  )
}
/**
 * The business's own history, behind the same triangle its permits are.
 *
 * ── Why a disclosure and not a heading ──────────────────────────────────────
 *
 * It shipped as an open block with a heading and a paragraph of explanation,
 * and on a profile with several businesses that is what the page became: three
 * lines of prose between every pair of businesses, repeated down the screen.
 * The client's verdict was *"the layout is very terrible"* [4 October 2026],
 * with the remedy — make it a dropdown like the businesses themselves.
 *
 * So it borrows the business row's shape exactly: a real `<button
 * aria-expanded>` over a panel that stays in the DOM and toggles `hidden`, the
 * triangle decorative, the button's own text naming what it opens. A reader
 * who has met one of these has met both.
 *
 * ── Closed, where the business above it is open ─────────────────────────────
 *
 * The opposite default, for the opposite reason. A business opens because its
 * permits are what the page is for [client brief, "more visibility"]. These
 * are last year's paper: worth keeping, worth reaching, not worth spending the
 * screen on every time. The count is in the summary so it is findable without
 * opening — a disclosure labelled only "Past permits" makes a reader click to
 * find out whether there is anything behind it.
 */
function PastPermits({ permits, business }: { permits: Permit[]; business: string }) {
  const [open, setOpen] = useState(false)
  const panelId = useId()
  const headingId = useId()

  return (
    <div className="pt-1">
      <h4>
        <button
          type="button"
          id={headingId}
          onClick={() => setOpen((o) => !o)}
          aria-expanded={open}
          aria-controls={panelId}
          className="flex w-full items-center gap-3 rounded-lg border border-line bg-white px-4 py-2.5 text-left transition-colors hover:bg-royal-tint/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal sm:px-5"
        >
          <Triangle open={open} />
          <span className="flex min-w-0 flex-1 flex-col gap-0.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
            <span className="text-sm font-bold text-ink-secondary">
              Past permits ({permits.length})
            </span>
            <span className="shrink-0 text-xs italic text-ink-muted">
              Renewed or ended · still downloadable
            </span>
          </span>
        </button>
      </h4>
      <div id={panelId} role="group" aria-labelledby={headingId} hidden={!open} className="pt-2">
        <ul className="space-y-2">
          {permits.map((permit) => (
            <PermitRow key={permit.id} permit={permit} business={business} past />
          ))}
        </ul>
      </div>
    </div>
  )
}
/**
 * One business, collapsed to a heading row that opens into its permits (p25–26).
 *
 * The disclosure is a real `<button aria-expanded>` controlling a panel that
 * stays in the DOM and toggles `hidden`, so `aria-controls` always resolves and
 * assistive tech can be told what the triangle opens. The triangle itself is
 * decorative — the button's own text is the business name.
 */
export function BusinessRow({ group, defaultOpen = true }: { group: BusinessGroup; defaultOpen?: boolean }) {
  /*
   * ---- Open, because the page exists to show these -------------------------
   *
   * Every section started collapsed, so a screen built "for more visibility and
   * accessibility" of approved permits [client brief] opened showing none of
   * them: four business names and a count, and a click needed before a single
   * permit was on screen.
   *
   * Collapsed-by-default is right for a disclosure that hides detail nobody
   * asked for. Here the detail IS what was asked for, and the heading rows are
   * the navigation through it rather than the content.
   *
   * It stays a real disclosure, so a reader with a dozen businesses can shut
   * the ones they are not working on — and `defaultOpen` is a prop rather than
   * a constant so a caller with a long list can start them closed.
   */
  const [open, setOpen] = useState(defaultOpen)
  const panelId = useId()
  /*
   * Split once, here, rather than filtered twice in the markup below — the
   * two lists have to be exhaustive over `group.permits` or a certificate
   * would vanish from the page entirely.
   */
  const current = group.permits.filter((permit) => !isPastPermit(permit))
  const past = group.permits.filter(isPastPermit)
  const headingId = useId()

  /*
   * Three different things the date line can be saying, and the row has to say
   * which. `status` covers revoked and suspended, which an officer sets. Expiry
   * is only ever a date passing, so nothing writes it down — a permit whose
   * validity ran out last week still reads `active` in the register. Both are
   * spelled out rather than colour-coded: an expired permit that looks current
   * is the one mistake a download makes permanent.
   */
  const alarming = group.expired || group.nearing
  /*
   * A group can now hold nothing but copies the applicant submitted, because a
   * business whose filing is still in flight has no issued permit yet and this
   * list no longer waits for one. "Permit Expiration: —" on such a row would be
   * a date the register has and is declining to print; the truth is that no
   * permit exists to expire, and saying so is also the one sentence that tells
   * the applicant these rows are not the permits they are waiting for.
   */
  const noPermits = group.permits.length === 0
  const expiryLabel = noPermits
    ? 'No permit issued yet'
    : group.expired
      ? 'Permit expired: '
      : group.nearing
        ? 'Nearing Permit Expiration: '
        : 'Permit Expiration: '
  const expiryDate = noPermits ? '' : formatDate(alarming ? group.soonestExpiry : group.latestExpiry)

  return (
    <li className="space-y-2.5">
      <h3>
        <button
          type="button"
          id={headingId}
          onClick={() => setOpen((o) => !o)}
          aria-expanded={open}
          aria-controls={panelId}
          className="flex w-full items-center gap-4 rounded-xl bg-white px-5 py-4 text-left shadow-card transition-colors hover:bg-royal-tint/40 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal sm:gap-5 sm:px-6 sm:py-5"
        >
          <Triangle open={open} />
          {/* Side by side from `sm` up as designed; stacked below it, because a
              phone-width row put the business name and the expiry in the same
              line and truncated the name to three characters. */}
          <span className="flex min-w-0 flex-1 flex-col gap-0.5 sm:flex-row sm:items-center sm:justify-between sm:gap-5">
            <span className="truncate text-lg font-bold text-ink">{group.name}</span>
            <span className={`shrink-0 text-sm italic ${alarming ? 'font-semibold text-s-red' : 'text-ink-muted'}`}>
              {expiryLabel}
              {expiryDate}
            </span>
          </span>
        </button>
      </h3>
      {/*
        * Two lists in one panel, not one list of two kinds of thing.
        *
        * The issued permits and the submitted copies each get their own `<ul>`
        * under their own heading. Interleaving them would put a document the
        * City issued and a document the applicant uploaded on consecutive rows
        * of one list, where the only thing separating them is styling — and
        * styling is not a distinction a screen reader passes on.
        *
        * `role="group"` on the wrapper, because the wrapper is now a plain div
        * and `aria-labelledby` on a div with no role names nothing. It is a
        * group rather than a `<section>`: a landmark per business would put a
        * dozen regions on one page for no navigational gain.
        */}
      <div
        id={panelId}
        role="group"
        aria-labelledby={headingId}
        hidden={!open}
        className="space-y-2"
      >
        {/*
          ── Cover, then history ───────────────────────────────────────────

          One list until 4 October 2026, with the replaced certificates at the
          bottom of it in the same royal blue as the live ones. The client
          opened last year's Sanitary Permit from that list and reported the
          renewal as broken, which is the list doing its job badly rather than
          the reader doing theirs: nothing about the row said it was history.

          Asked whether renewing should stop superseding at all, so that past
          permits stay visible — they already were. `superseded` is what keeps
          exactly one certificate of a type live, and it is what keeps the
          replaced one out of next year's renewal picker (see
          `PermitStatus::Superseded`, added for that bug). The thing that was
          missing was not the records; it was the line between them.
        */}
        {current.length > 0 && (
          <ul className="space-y-2">
            {current.map((permit) => (
              <PermitRow key={permit.id} permit={permit} business={group.name} />
            ))}
          </ul>
        )}

        {past.length > 0 && <PastPermits permits={past} business={group.name} />}

      </div>
    </li>
  )
}

/* ── What this account holds, grouped and narrowed ────────────────── */

/**
 * Load the permits and the submitted copies, group them by business, and apply
 * the reader's sort and filter.
 *
 * One hook rather than two copies of this logic, because two screens read it:
 * the permits page lists the groups, and Profile prints how many there are. A
 * second implementation of the grouping would let those two numbers disagree.
 */
export function useHoldings(enabled: boolean) {
  const { data, loading, error, reload } = useAsync<ProfileHoldings>(
    () => (enabled ? loadHoldings() : Promise.resolve({ permits: [], unbilled: undefined })),
    [enabled],
  )
  const [sort, setSort] = useState('name')
  const [filter, setFilter] = useState('all')

  const groups = useMemo<BusinessGroup[]>(() => {
    const map = new Map<number, BusinessGroup>()

    /*
     * One factory for both passes, because the second one can reach a business
     * the first never saw: a filing still in flight has clearance copies on it
     * and no issued permit behind it yet, and that business has to appear or
     * the copy the applicant submitted is invisible — which is the whole of the
     * client's second request.
     */
    const groupFor = (business: { id: number; name: string } | null): BusinessGroup => {
      /*
       * `business` is typed non-nullable on Permit and is not. A soft-deleted
       * business leaves its issued permits on the register, and the default
       * scope drops it from the eager load, so this comes back null — the same
       * shape that took the officer queue, the inspection list and the review
       * sheet down by reading `.name` straight off it
       * (RemovedBusinessRenderingTest).
       *
       * Key 0 collects every orphaned row into one group. They have no business
       * id left to tell them apart by, and one row saying the register no longer
       * holds the business is more useful than several. Everything stays
       * reachable either way — that group opens like any other.
       */
      const key = business?.id ?? 0
      const existing = map.get(key)
      if (existing) return existing
      const created: BusinessGroup = {
        id: key,
        name: businessName(business),
        permits: [],
        latestExpiry: null,
        soonestExpiry: null,
        nearing: false,
        expired: false,
        flagged: false,
      }
      map.set(key, created)
      return created
    }

    for (const permit of data?.permits ?? []) {
      const group = groupFor(permit.business as Permit['business'] | null)
      group.permits.push(permit)

      /*
       * ── A replaced permit no longer has an expiry worth announcing ──────
       *
       * `superseded` is what a renewal leaves behind, and the row stays on
       * the list on purpose — the applicant keeps last year's certificate and
       * can still download it. What it must not do is drive the DATE in the
       * heading, and it was: a shop that renewed its Sanitary Permit on
       * 4 October 2026 was still told *"Nearing Permit Expiration: October
       * 23, 2026"* — the date on the certificate it had just replaced — while
       * the new one sat in the same list, unexpired, a few rows above
       * [client, 4 October 2026]. It reads as the renewal having done
       * nothing.
       *
       * The same mistake as the `flagged` filter below, caught a fortnight
       * later in the arithmetic instead of the wording: superseded is a
       * historical record, not a live holding.
       *
       * Only superseded. A SUSPENDED permit still expires and the owner still
       * needs telling, and an expired one is what `expired` is for.
       */
      if (permit.status === 'superseded') continue

      if (permit.valid_until) {
        if (!group.latestExpiry || permit.valid_until > group.latestExpiry) group.latestExpiry = permit.valid_until
        if (!group.soonestExpiry || permit.valid_until < group.soonestExpiry) group.soonestExpiry = permit.valid_until
      }
      const days = permit.days_until_expiry
      if (days !== null && days < 0) group.expired = true
      if (days !== null && days >= 0 && days <= NEARING_DAYS) group.nearing = true
      /*
       * ── Flagged means SANCTIONED, not merely inactive ─────────────────
       *
       * This read `status !== 'active'`, which is every state but one —
       * including `superseded`, which every renewal produces by design. A
       * shop that renewed its sanitary permit in September holds last
       * year's superseded certificate, and the filter above called that
       * "Suspended or revoked".
       *
       * It cost nothing while nothing was ever suspended. Since
       * 24 September 2026 a refused clearance suspends the business permit,
       * so the filter is about to be used for real and has to mean what it
       * says. Expired is excluded for its own reason: `expired` above
       * already carries it, and a lapsed permit is a date passing rather
       * than a decision anybody took.
       */
      if (permit.status === 'suspended' || permit.status === 'revoked') group.flagged = true
    }

    /*
     * The copies, second, so a business that has both keeps the order the page
     * was designed around: what the City issued first, what the applicant
     * handed in after it.
     *
     * Nothing here touches expiry, `nearing`, `expired` or `flagged`. Those are
     * facts about an issued permit and a copy has none of them — a held sanitary
     * certificate might well have expired last month, but the register never
     * recorded its validity, so the page has nothing to say about it and must
     * not guess. Same reason the row prints no dates.
     */
    return [...map.values()]
  }, [data])

  /*
   * Sort and Filter are wired to real state rather than rendered bare. The
   * control is deliberately inert without props (its own doc says so), and two
   * pages shipped it that way — a menu that opens onto nothing was checklist
   * item 90. Every option below changes what the list shows.
   */
  const visible = useMemo(() => {
    const matches = (g: BusinessGroup) =>
      filter === 'nearing'
        ? g.nearing
        : filter === 'expired'
          ? g.expired
          : filter === 'flagged'
            ? g.flagged
            : true

    const key = (g: BusinessGroup) => g.soonestExpiry ?? g.latestExpiry ?? NEVER
    const sorted = groups.filter(matches)
    sorted.sort((a, b) => {
      switch (sort) {
        case 'name_desc':
          return b.name.localeCompare(a.name)
        case 'expiry':
          return key(a).localeCompare(key(b))
        case 'expiry_desc':
          return key(b).localeCompare(key(a))
        case 'permits':
          return b.permits.length - a.permits.length || a.name.localeCompare(b.name)
        default:
          return a.name.localeCompare(b.name)
      }
    })
    return sorted
  }, [groups, sort, filter])

  return { groups, visible, sort, setSort, filter, setFilter, unbilled: data?.unbilled, loading, error, reload }
}
