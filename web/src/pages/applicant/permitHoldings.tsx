import { useId, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  AlertCircleIcon,
  DownloadIcon,
  EyeIcon,
  MailIcon,
} from '../../components/icons'
import { StatusChip, type SortFilterOption } from '../../components/ui/Proto'
import { STATUS_TONES } from '../admin/permitStatusTones'
import { businessName, formatDate } from '../../lib/format'
import { permits as permitsApi } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { toApiError } from '../../lib/api'
import { DocumentActions } from '../../components/DocumentActions'
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
    const { data, meta } = await permitsApi.page({ page, per_page: 200, with_requirements: 1 })
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
  /** The Business Account Number, shown under the name [client, 5 October 2026]. */
  ban: string | null
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
  /* Outlined on a past permit, so the history reads as paper rather than cover. */
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
      className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-semibold transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal ${
        muted
          ? 'border border-line bg-white text-ink-secondary hover:border-royal hover:text-royal'
          : 'bg-royal text-white hover:bg-royal-hover'
      } ${
        busy ? 'opacity-50' : ''
      }`}
    >
      {failed ? <AlertCircleIcon size={15} aria-hidden="true" /> : <DownloadIcon size={15} aria-hidden="true" />}
      {failed ? 'Retry' : busy ? 'Preparing…' : 'PDF'}
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
 * One issued permit, on the card's grid.
 *
 * The status is a tinted, worded chip (Never Color Alone): an expired permit
 * whose status still reads `active` says "Expired", because a date passing is
 * not written down anywhere and a certificate that looks current is the one
 * mistake a download makes permanent.
 *
 * ── Why `past` changes more than a colour ────────────────────────────────
 *
 * The current permits and the historical ones are drawn as two lists (4
 * October 2026). They were one, and a replaced certificate sat at the bottom
 * of it looking like the live ones, told apart only by a small chip. The
 * client opened last year's Sanitary Permit from that list, read its VALID
 * UNTIL, and reported the renewal as broken — *"I just renewed that sanitary
 * form and the expiration date should be Oct. 4, 2027"* — while the permit
 * they had just been issued sat four rows above with the right dates on it.
 *
 * So a past row is muted rather than merely chipped, and sits behind its own
 * disclosure. It stays openable and downloadable, which is the whole reason
 * it is still on the page.
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
  /* "Sanitary Permit for CedarBloom Café (MCB-2026-000406)" — the View and
     PDF buttons are the same two words on every row, and neither says which
     of the five rows it belongs to. The number is on the end because a
     renewal leaves two permits of the SAME type on the same business, and
     then the type and the business name together still do not tell them
     apart. */
  const label = `${typeName} for ${business} (${permit.permit_number})`
  const lapsed = permit.status === 'active' && permit.days_until_expiry !== null && permit.days_until_expiry < 0
  const statusKey = lapsed ? 'expired' : permit.status
  const statusText = lapsed ? 'Expired' : permit.status_label

  return (
    <li className={`px-5 py-3.5 sm:px-6 ${past ? 'bg-shell/60' : ''}`}>
      <div className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 sm:grid-cols-[minmax(0,1fr)_7.5rem_10rem_10.5rem]">
        <div className="min-w-0">
          <p className={`break-words font-semibold leading-snug ${past ? 'text-ink-secondary' : 'text-ink'}`}>
            {typeName}
          </p>
          <p className="tnum text-xs text-ink-muted">{permit.permit_number}</p>
          {/* What a suspension waits on, the Track row's line (5 October 2026). */}
          {permit.status === 'suspended' && permit.suspended_for && (
            <p className="text-xs text-ink-secondary">
              Waiting on your {permit.suspended_for.name}
              {permit.suspended_for.office ? ` with ${permit.suspended_for.office}` : ''}
            </p>
          )}
        </div>
        <div className="justify-self-end sm:justify-self-start">
          <StatusChip tone={STATUS_TONES[statusKey] ?? 'tint-gray'}>{statusText}</StatusChip>
        </div>
        <p className="col-span-2 text-sm text-ink-secondary sm:col-span-1">
          <span className="text-xs text-ink-muted sm:hidden">Valid until </span>
          {formatDate(permit.valid_until) || '—'}
        </p>
        <div className="col-span-2 flex items-center gap-2 sm:col-span-1 sm:justify-end">
          <Link
            to={`/permits/${permit.id}`}
            aria-label={`View ${label}`}
            className="inline-flex items-center gap-1.5 rounded-full border border-line bg-white px-3.5 py-1.5 text-xs font-semibold text-ink hover:border-royal hover:text-royal focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal"
          >
            <EyeIcon size={15} aria-hidden="true" /> View
          </Link>
          <PermitDownloadButton permit={permit} label={label} muted={past} />
        </div>
      </div>
      <PermitExtras permit={permit} />
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
 * who has met one of these has met both. Its rows sit on the card's grid, so
 * the columns line up with the current permits above.
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
    <div className="border-t border-line">
      <h4>
        <button
          type="button"
          id={headingId}
          onClick={() => setOpen((o) => !o)}
          aria-expanded={open}
          aria-controls={panelId}
          className="flex w-full items-center gap-3 bg-canvas/40 px-5 py-2.5 text-left transition-colors hover:bg-royal-tint/40 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-royal sm:px-6"
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
      <div id={panelId} role="group" aria-labelledby={headingId} hidden={!open}>
        <ul className="divide-y divide-line border-t border-line">
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
/**
 * Under each permit: what was submitted for it, and — when an office rejected
 * it — the way to that office.
 *
 * [Client, 5 October 2026.] Requirements Submitted is the same list the
 * office's Permits table shows (GET /permits/{id}/requirements): the uploads
 * that office reads and the Other Requirements it asked for, once approved.
 * Fetched when opened, so a page of permits is not a page of requests.
 *
 * A rejected permit says which office rejected it and offers "Message" that
 * office, opening Messages on this filing with that office already chosen —
 * the conversation the owner needs, without hunting for it.
 */
function PermitExtras({ permit }: { permit: Permit }) {
  const [open, setOpen] = useState(false)
  const panelId = useId()
  const [docs, setDocs] = useState<Awaited<ReturnType<typeof permitsApi.requirements>> | null>(null)
  const [error, setError] = useState<string | null>(null)
  const office = permit.permit_type?.office ?? 'the issuing office'
  const rejected = permit.status === 'rejected'
  const count = permit.requirements_count ?? 0
  const messageTo =
    permit.application && permit.permit_type?.department_id
      ? `/messages?application=${permit.application.id}&office=${permit.permit_type.department_id}`
      : '/messages'

  function toggle() {
    const next = !open
    setOpen(next)
    if (next && docs === null) {
      permitsApi
        .requirements(permit.id)
        .then(setDocs)
        .catch((err) => setError(toApiError(err).message))
    }
  }

  /*
   * Only when there is something to say [client, 5 October 2026]: the toggle
   * shows when a requirement is on record, with the count already in it, and
   * the strip is not drawn at all for a permit with neither requirements nor a
   * rejection — an empty disclosure is a click that finds nothing.
   */
  if (!rejected && count === 0) return null

  return (
    <div className="mt-2.5 space-y-2.5">
      {rejected && (
        // One line: who rejected it, and the way to them [client, 5 October 2026].
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-md border border-s-red/40 bg-s-red-tint px-3 py-1.5">
          <p className="text-xs font-semibold text-s-red">Rejected by the {office}.</p>
          <Link
            to={messageTo}
            className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-royal px-3.5 py-1 text-xs font-semibold text-white hover:bg-royal-hover"
          >
            <MailIcon size={14} aria-hidden="true" /> Message the {office}
          </Link>
        </div>
      )}
      {count > 0 && (
      <>
      <button
        type="button"
        onClick={toggle}
        aria-expanded={open}
        aria-controls={panelId}
        className="inline-flex items-center gap-2 rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink hover:border-royal hover:text-royal"
      >
        <span aria-hidden="true" className="text-[10px] text-ink-muted">{open ? '▾' : '▸'}</span>
        Requirements submitted
        <span className="tnum rounded-full bg-royal px-1.5 py-px text-[10px] font-bold text-white">{count}</span>
      </button>
      <div id={panelId} hidden={!open}>
        {error ? (
          <p role="alert" className="text-xs font-medium text-s-red">{error}</p>
        ) : docs === null ? (
          <p className="text-xs text-ink-muted">Loading…</p>
        ) : docs.length === 0 ? (
          <p className="text-xs text-ink-muted">No requirements on record for this permit.</p>
        ) : (
          <ul className="divide-y divide-line">
            {docs.map((d) => (
              <li key={d.id} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1.5 py-2">
                <div className="min-w-0">
                  <p className="flex flex-wrap items-center gap-2 text-sm font-semibold text-ink">
                    {d.name}
                    {d.from_request && (
                      <span className="rounded bg-s-orange-tint px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-s-orange-ink">
                        Other Requirements
                      </span>
                    )}
                  </p>
                  <p className="truncate text-xs text-ink-muted" title={d.filename}>
                    {d.filename}
                  </p>
                </div>
                <DocumentActions id={d.id} filename={d.filename} label={d.name} />
              </li>
            ))}
          </ul>
        )}
      </div>
      </>
      )}
    </div>
  )
}

/**
 * One business: a card whose heading opens onto its permits.
 *
 * ── Laid out to be read, not decoded [client, 5 October 2026] ─────────────
 *
 * "Paki ayos ui … alignments ng permit kasi jumbled pa eh, tas sa name ng
 * business iinclude na rin BAN." The permits used to be a stack of royal bars
 * with the badge, the number and the icons floating wherever the name left
 * room, so no two rows lined up. Now:
 *
 *   - the heading names the business, its Business Account Number and how
 *     many permits it holds, with ONE status pill on the right saying the
 *     thing that matters most (expired, expiring soon, or valid until);
 *   - each permit is a row on the same grid — certificate and number |
 *     status | valid until | actions — so the eye runs straight down each
 *     column, and on a phone the row stacks in that same order;
 *   - requirements and a rejection sit under their own permit, indented to
 *     it, rather than as a band across the card.
 *
 * The disclosure is a real `<button aria-expanded>` controlling a panel that
 * stays in the DOM and toggles `hidden`, so `aria-controls` always resolves.
 */
export function BusinessRow({
  group,
  defaultOpen = true,
  open: openProp,
  onToggle,
}: {
  group: BusinessGroup
  defaultOpen?: boolean
  /**
   * Controlled from the page, so only one business is open at a time [client,
   * 5 October 2026: "wag na muna iopen lahat ng business isa isa na lang"].
   * Without it the card keeps its own state, as the Profile page uses it.
   */
  open?: boolean
  onToggle?: () => void
}) {
  const [ownOpen, setOwnOpen] = useState(defaultOpen)
  const open = openProp ?? ownOpen
  const panelId = useId()
  /*
   * Split once, here, rather than filtered twice in the markup below — the
   * two lists have to be exhaustive over `group.permits` or a certificate
   * would vanish from the page entirely.
   */
  const current = group.permits.filter((permit) => !isPastPermit(permit))
  const past = group.permits.filter(isPastPermit)
  const headingId = useId()
  const cardRef = useRef<HTMLLIElement>(null)

  function toggle() {
    const opening = !open
    if (onToggle) onToggle()
    else setOwnOpen(opening)
    /*
     * Opening this one closes the one above it, and the page jumps by that
     * card's height — so bring the opened card's heading back into view rather
     * than leave the reader looking at a different business.
     */
    if (opening) requestAnimationFrame(() => cardRef.current?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }))
  }

  const noPermits = group.permits.length === 0
  /*
   * A decision against a permit outranks every date [client, 5 October 2026]:
   * with the cards closed, this pill is all an owner sees, and "Valid until"
   * on a business whose Mayor's Permit was revoked would tell them all is well.
   */
  const sanctioned = group.permits.filter((p) => ['suspended', 'revoked', 'rejected'].includes(p.status)).length
  const pill = noPermits
    ? { text: 'No permit issued yet', className: 'bg-canvas text-ink-secondary' }
    : sanctioned > 0
      ? {
          text: `${sanctioned} permit${sanctioned === 1 ? '' : 's'} need${sanctioned === 1 ? 's' : ''} attention`,
          className: 'bg-s-red text-white',
        }
      : group.expired
      ? { text: `Permit expired · ${formatDate(group.soonestExpiry)}`, className: 'bg-s-red-tint text-s-red' }
      : group.nearing
        ? { text: `Expires soon · ${formatDate(group.soonestExpiry)}`, className: 'bg-s-yellow-tint text-amber-800' }
        : { text: `Valid until ${formatDate(group.latestExpiry)}`, className: 'bg-s-green-tint text-s-green' }

  const count = group.permits.length
  const meta = [
    group.ban ? null : 'No Business Account No. yet',
    count > 0 ? `${count} permit${count === 1 ? '' : 's'}` : null,
  ].filter(Boolean)

  return (
    <li ref={cardRef} className="scroll-mt-4 overflow-hidden rounded-xl bg-white shadow-card">
      <h3>
        <button
          type="button"
          id={headingId}
          onClick={toggle}
          aria-expanded={open}
          aria-controls={panelId}
          className="flex w-full items-center gap-4 px-5 py-4 text-left transition-colors hover:bg-royal-tint/40 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-royal sm:px-6"
        >
          <Triangle open={open} />
          <span className="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-6">
            <span className="min-w-0">
              <span className="block truncate text-lg font-bold text-ink">{group.name}</span>
              <span className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-ink-muted">
                {group.ban && (
                  <span className="basis-full sm:basis-auto">
                    Business Account No. <b className="tnum font-semibold text-ink-secondary">{group.ban}</b>
                  </span>
                )}
                {meta.map((m) => (
                  <span key={m} className="sm:before:mr-2 sm:before:content-['·'] sm:first:before:hidden">
                    {m}
                  </span>
                ))}
              </span>
            </span>
            <span className={`shrink-0 self-start rounded-full px-3 py-1 text-xs font-semibold sm:self-auto ${pill.className}`}>
              {pill.text}
            </span>
          </span>
        </button>
      </h3>

      <div id={panelId} role="group" aria-labelledby={headingId} hidden={!open} className="border-t border-line">
        {group.permits.length > 0 && (
          /* Column headings for the grid below, from `sm` up — on a phone each
             row labels its own date instead. */
          <div className="hidden grid-cols-[minmax(0,1fr)_7.5rem_10rem_10.5rem] gap-x-4 bg-canvas/60 px-6 py-2 text-[11px] font-semibold uppercase tracking-wider text-ink-muted sm:grid">
            <span>Permit</span>
            <span>Status</span>
            <span>Valid until</span>
            <span className="text-right">Actions</span>
          </div>
        )}
        {/*
          ── Cover, then history ───────────────────────────────────────────

          One list until 4 October 2026, with the replaced certificates at the
          bottom of it looking like the live ones. The client opened last
          year's Sanitary Permit from that list and reported the renewal as
          broken, which is the list doing its job badly rather than the reader
          doing theirs: nothing about the row said it was history.

          Asked whether renewing should stop superseding at all, so that past
          permits stay visible — they already were. `superseded` is what keeps
          exactly one certificate of a type live, and it is what keeps the
          replaced one out of next year's renewal picker (see
          `PermitStatus::Superseded`, added for that bug). The thing that was
          missing was not the records; it was the line between them.
        */}
        {current.length > 0 && (
          <ul className="divide-y divide-line">
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
    const groupFor = (business: { id: number; name: string; ban?: string | null } | null): BusinessGroup => {
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
      if (existing) {
        existing.ban ??= business?.ban ?? null
        return existing
      }
      const created: BusinessGroup = {
        id: key,
        name: businessName(business),
        ban: business?.ban ?? null,
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
      if (permit.status === 'suspended' || permit.status === 'revoked' || permit.status === 'rejected')
        group.flagged = true
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
