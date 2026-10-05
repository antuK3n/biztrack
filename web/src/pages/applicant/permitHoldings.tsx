import { useId, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  AlertCircleIcon,
  DownloadIcon,
  EyeIcon,
  MailIcon,
} from '../../components/icons'
import { StatusChip, type SortFilterOption } from '../../components/ui/Proto'
import { STATUS_TONES } from '../admin/permitStatusTones'
import { businessName, formatBytes, formatDate } from '../../lib/format'
import { documents as documentsApi, permits as permitsApi } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { toApiError } from '../../lib/api'
import { DocumentActions } from '../../components/DocumentActions'
import type { HeldClearance, PageMeta, Permit } from '../../lib/types'

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
  held: HeldClearance[]
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
 * `/permits/held` is unpaged — it is bounded at six clearances per filing and
 * only ever carries the caller's own uploads, so the walk `loadAllPermits` does
 * has nothing to defend against here.
 */
export async function loadHoldings(): Promise<ProfileHoldings> {
  const [paged, held] = await Promise.all([loadAllPermits(), permitsApi.held()])
  return { permits: paged.permits, held, unbilled: paged.unbilled }
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
  held: HeldClearance[]
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
  // The other three all ask a question about an ISSUED permit, so a business
  // holding nothing but copies the applicant submitted answers no to every one
  // of them and is reachable only under "All". This is the option that finds it.
  { value: 'held', label: 'Has a copy you submitted' },
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
function PermitDownloadButton({ permit, label }: { permit: Permit; label: string }) {
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
      className={`inline-flex shrink-0 items-center gap-1.5 rounded-full bg-royal px-3.5 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-royal-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal ${
        busy ? 'opacity-50' : ''
      }`}
    >
      {failed ? <AlertCircleIcon size={15} aria-hidden="true" /> : <DownloadIcon size={15} aria-hidden="true" />}
      {failed ? 'Retry' : busy ? 'Preparing…' : 'PDF'}
    </button>
  )
}

/**
 * A clearance the applicant already held and submitted a copy of.
 *
 * This row exists to be told apart from the royal permit row above it, at a
 * glance and without reading the label. The City did not issue this document —
 * it is the applicant's own file, uploaded in place of applying for that
 * clearance — and no Permit record exists for it (WorkflowService::approveAndIssue
 * only issues the permit types actually ON the filing, and submitting a copy is
 * precisely the act of leaving one off).
 *
 * So, deliberately, and none of this is to be "tidied up" later:
 *  - no permit number, because the City never assigned one;
 *  - no validity dates, because the City never recorded any;
 *  - no eye link to /permits/{id} and no verify QR, because there is nothing at
 *    either end. `id` here is a DOCUMENT id.
 *  - a dashed outline and the plain canvas, not the solid royal fill the issued
 *    permits wear. Colour is not carrying that on its own: the row says
 *    "Your own copy" in words and names the file it is a copy of.
 *
 * The permit certificate is a legal instrument and this codebase has already
 * had to strip a vendor logo off it for exactly this reason. A fabricated
 * number or validity here would be the same mistake one screen earlier.
 */
function HeldCopyRow({ copy, business }: { copy: HeldClearance; business: string }) {
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState(false)
  const typeName = copy.permit_type?.name ?? 'Clearance'

  async function download() {
    if (busy) return
    setBusy(true)
    setFailed(false)
    try {
      await documentsApi.download(copy.id, copy.filename)
    } catch {
      setFailed(true)
    } finally {
      setBusy(false)
    }
  }

  return (
    <li className="flex items-center gap-3 rounded-lg border border-dashed border-royal/45 bg-canvas/60 px-4 py-3 sm:gap-4">
      <span className="min-w-0 flex-1">
        <span className="block truncate text-base font-bold text-ink">{typeName}</span>
        {/* The filename and its size are the only two things the register
            actually knows about this document. Printing them says plainly that
            what is on offer is a file the applicant handed in. */}
        <span className="block truncate text-xs text-ink-secondary">
          {copy.filename} · {formatBytes(copy.size_bytes)} · submitted {formatDate(copy.submitted_at)}
        </span>
      </span>
      <span className="shrink-0 rounded border border-ink-secondary px-1.5 py-0.5 text-[11px] font-bold uppercase tracking-wide text-ink-secondary">
        Your own copy
      </span>
      <button
        type="button"
        onClick={download}
        // `aria-disabled`, never `disabled` — same reasoning as the issued
        // permit's download button above. The guard at the top of `download`
        // is what actually stops a second press.
        aria-disabled={busy}
        aria-label={
          failed
            ? `Download failed for your copy of the ${typeName} for ${business}. Try again`
            : `Download your copy of the ${typeName} for ${business}`
        }
        className={`shrink-0 rounded text-royal transition-opacity hover:opacity-80 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal ${
          busy ? 'opacity-50' : ''
        }`}
      >
        {failed ? <AlertCircleIcon size={22} /> : <DownloadIcon size={22} />}
      </button>
    </li>
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
        <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-md border border-s-red/40 bg-s-red-tint px-3 py-2">
          <p className="text-xs leading-relaxed text-ink">
            <b className="text-s-red">Rejected by the {office}.</b> Message them to find out what is needed to have it
            approved again.
          </p>
          <Link
            to={messageTo}
            className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-royal px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-royal-hover"
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
export function BusinessRow({ group, defaultOpen = true }: { group: BusinessGroup; defaultOpen?: boolean }) {
  const [open, setOpen] = useState(defaultOpen)
  const panelId = useId()
  const headingId = useId()

  const noPermits = group.permits.length === 0
  const pill = noPermits
    ? { text: 'No permit issued yet', className: 'bg-canvas text-ink-secondary' }
    : group.expired
      ? { text: `Permit expired · ${formatDate(group.soonestExpiry)}`, className: 'bg-s-red-tint text-s-red' }
      : group.nearing
        ? { text: `Expires soon · ${formatDate(group.soonestExpiry)}`, className: 'bg-s-yellow-tint text-amber-800' }
        : { text: `Valid until ${formatDate(group.latestExpiry)}`, className: 'bg-s-green-tint text-s-green' }

  const count = group.permits.length
  const meta = [
    group.ban ? null : 'No Business Account No. yet',
    count > 0 ? `${count} permit${count === 1 ? '' : 's'}` : null,
    group.held.length > 0 ? `${group.held.length} submitted cop${group.held.length === 1 ? 'y' : 'ies'}` : null,
  ].filter(Boolean)

  return (
    <li className="overflow-hidden rounded-xl bg-white shadow-card">
      <h3>
        <button
          type="button"
          id={headingId}
          onClick={() => setOpen((o) => !o)}
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
          <>
            {/* Column headings for the grid below, from `sm` up — on a phone each
                row labels its own date instead. */}
            <div className="hidden grid-cols-[minmax(0,1fr)_7.5rem_10rem_10.5rem] gap-x-4 bg-canvas/60 px-6 py-2 text-[11px] font-semibold uppercase tracking-wider text-ink-muted sm:grid">
              <span>Permit</span>
              <span>Status</span>
              <span>Valid until</span>
              <span className="text-right">Actions</span>
            </div>
            <ul className="divide-y divide-line">
              {group.permits.map((permit) => (
                <PermitRow key={permit.id} permit={permit} business={group.name} />
              ))}
            </ul>
          </>
        )}

        {group.held.length > 0 && (
          <div className={`px-5 py-4 sm:px-6 ${group.permits.length > 0 ? 'border-t border-line' : ''}`}>
            <h4 className="text-[13px] font-bold text-ink-secondary">Clearances you submitted a copy of</h4>
            {/* Said in full, once per business: the City did not issue these and
                does not stand behind them. */}
            <p className="mb-2.5 mt-0.5 text-xs text-ink-muted">
              Your own documents, uploaded instead of applying for these clearances. The City did not issue them,
              so they carry no permit number and nothing here verifies them.
            </p>
            <ul className="space-y-2">
              {group.held.map((copy) => (
                <HeldCopyRow key={copy.id} copy={copy} business={group.name} />
              ))}
            </ul>
          </div>
        )}
      </div>
    </li>
  )
}

/**
 * One issued permit, on the card's grid.
 *
 * The status is a tinted, worded chip (Never Color Alone): an expired permit
 * whose status still reads `active` says "Expired", because a date passing is
 * not written down anywhere and a certificate that looks current is the one
 * mistake a download makes permanent.
 */
function PermitRow({ permit, business }: { permit: Permit; business: string }) {
  const typeName = permit.permit_type?.name ?? 'Permit'
  const label = `${typeName} for ${business} (${permit.permit_number})`
  const lapsed = permit.status === 'active' && permit.days_until_expiry !== null && permit.days_until_expiry < 0
  const statusKey = lapsed ? 'expired' : permit.status
  const statusText = lapsed ? 'Expired' : permit.status_label

  return (
    <li className="px-5 py-3.5 sm:px-6">
      <div className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 sm:grid-cols-[minmax(0,1fr)_7.5rem_10rem_10.5rem]">
        <div className="min-w-0">
          <p className="break-words font-semibold leading-snug text-ink">{typeName}</p>
          <p className="tnum text-xs text-ink-muted">{permit.permit_number}</p>
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
          <PermitDownloadButton permit={permit} label={label} />
        </div>
      </div>
      <PermitExtras permit={permit} />
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
    () => (enabled ? loadHoldings() : Promise.resolve({ permits: [], held: [], unbilled: undefined })),
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
        held: [],
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
    for (const copy of data?.held ?? []) {
      groupFor(copy.business).held.push(copy)
    }

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
            : filter === 'held'
              ? g.held.length > 0
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
