import { useEffect, useMemo, useState } from 'react'
import { permits } from '../../lib/resources'
import { toApiError } from '../../lib/api'
import { businessName, formatDate } from '../../lib/format'
import { useAsync } from '../../lib/useAsync'
import type { Permit, PermitRegisterRow } from '../../lib/types'
import type { PermitSort } from '../../lib/resources'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { PageTitle, ProtoCard, SortFilter, StatusChip } from '../../components/ui/Proto'
import type { ChipTone } from '../../components/ui/Proto'
import { FileTextIcon } from '../../components/icons'
import { useAuth } from '../../stores/auth'
import {
  OFFICES,
  columnsFor,
  officeOf,
  permitCodeForDepartment,
  type OfficeCode,
  type PermitColumn,
} from './permitColumns'

/*
 * Permits — every certificate the City has issued, as one long table.
 *
 * Issue #103 asked for "a page listing ALL approved permits as a table". It
 * shipped with five columns and the reasoning, written here at the time, that
 * "only the necessary columns" meant the fewest a reader could decide on.
 *
 * The client read that table and asked for the opposite, in plain terms:
 * "ilagay lahat sa isang mahabang table pahaba left to right ... lahat ng info
 * about sa permit na kailangan sa kada office pati mga finill outan kada
 * permit". This is that table.
 *
 * The leading column took two goes. "Mauuna ang BAN" put the business account
 * number first; the clarification named the value rather than the word —
 * "BIZ-2026-0000x tracking id sa pag aapply at pagbayad na ang application,
 * the next permit no. sa permit ng office na inapplyan nya" — and that is the
 * tracking ID, not the BAN. The filing leads, the office's own certificate
 * follows it, and the rest of the record follows both. See the identifier
 * block in `permitColumns.ts`.
 *
 * ── Both halves of the ask, and why they are not in conflict ───────────────
 *
 * Two sentences that look like they pull apart: one long table with everything
 * in it, AND "naka depende kung anong office ito" — each office sees what it
 * needs. They are the same table read two ways, so the office filter decides:
 *
 *   No office chosen  the register. Every shared column plus all five office
 *                     sheets, scrolling sideways. This is the long table.
 *   An office chosen  that office. The shared columns plus its own sheet, and
 *                     the other four sheets drop away.
 *
 * ── What the columns are, and where each comes from ────────────────────────
 *
 * `permitColumns.ts` holds them as data — heading, value and whether the
 * SERVER can sort on it — so the head and the body are drawn from one list and
 * cannot fall out of step. A column in the body but not the head shifts every
 * cell to its right by one, silently, and the table still renders.
 *
 * The face — trade name, owner, address, barangay, city, line of business — is
 * read off the SNAPSHOT taken when the certificate was signed, never off the
 * register as it reads today. See `PermitFace` and the `issued_details`
 * migration.
 *
 * ── Where search, filter, sort and paging run ──────────────────────────────
 *
 * All four on the server now. Sorting used to run in the browser over the 25
 * rows in hand, because `/permits` accepted no ordering; the note here warned
 * against adding an `order` param to "fix" it, since an unknown key is dropped
 * in silence. The endpoint takes `sort` and `dir` against its own whitelist,
 * so the sort reaches the whole register and an unknown key is a 422.
 *
 * Nine columns can be ordered. The office-sheet answers cannot: they live in a
 * JSON column no index reaches, and their headers are plain text rather than
 * buttons — an unsortable header that looked pressable would be a control that
 * appears to work.
 *
 * ── Revoke is still not on this screen ─────────────────────────────────────
 *
 * Unchanged, and still decided rather than unfinished. `PermitStatus::Revoked`
 * and `permits.revoked_at` / `revoked_reason` exist, nothing writes them, and
 * the live register holds no row in that state. A revoked permit means a
 * business is trading unlawfully — an enforcement act with a real-world
 * consequence, needing the City to say who may do it and on what grounds.
 *
 * What DID change: the table now carries "Revoked on" and "Revocation reason"
 * as columns. The day a writer exists, a revoked permit reads as one rather
 * than rendering as an ordinary row whose status chip quietly turned red.
 */

/** Rows per request. Matches Records and Owner Status. */
const PAGE_SIZE = 25

/** How long to wait before a keystroke becomes a request. Same as Records. */
const SEARCH_DEBOUNCE_MS = 300

/**
 * The status filter, and why it is not simply the PermitStatus enum.
 *
 * Only the three states the register actually holds are offered. A pill for
 * Revoked would return an empty table on every press — and worse, it would
 * advertise a capability this system does not have, which is the same claim
 * the missing Revoke button is careful not to make. Add the pill in the same
 * change that adds the writer, not before.
 */
type StatusFilter = '' | 'active' | 'expired' | 'superseded'

const STATUS_FILTERS: { value: StatusFilter; label: string }[] = [
  { value: '', label: 'All' },
  { value: 'active', label: 'Active' },
  { value: 'expired', label: 'Expired' },
  { value: 'superseded', label: 'Superseded' },
]

/**
 * Status tones, in the tints the other admin tables already use.
 *
 * Superseded is grey rather than red: it is an ordinary, correct outcome — the
 * business renewed early and this certificate was replaced — and colouring it
 * as a problem would break "Red Means Stop" (DESIGN.md). Red is held for
 * `revoked`, which is the one enforcement state in this map, and is listed
 * even though no row carries it so that the day a writer exists the table does
 * not silently render it in the neutral grey of the fallback.
 */
const STATUS_TONES: Record<string, ChipTone> = {
  active: 'tint-green',
  expired: 'tint-yellow',
  superseded: 'tint-gray',
  suspended: 'tint-purple',
  revoked: 'tint-red',
}

/**
 * "Which of mine lapse soon" — the operational question this table could not
 * answer.
 *
 * Status says what a certificate IS; this says what is about to happen to it,
 * and the two do not overlap: everything here is Active, and everything here
 * will be Expired if nobody acts. Offered to every office, because it is the
 * question an office asks of its own certificates rather than one only the
 * register-wide readers have.
 *
 * The windows are the ones a counter actually uses. 180 and 365 are not here:
 * at that range it selects most of the register and reads as a filter that did
 * nothing.
 */
type ExpiryWindow = '' | '30' | '60' | '90'

const EXPIRY_FILTERS: { value: ExpiryWindow; label: string }[] = [
  { value: '', label: 'Any' },
  { value: '30', label: 'Within 30 days' },
  { value: '60', label: 'Within 60 days' },
  { value: '90', label: 'Within 90 days' },
]

/**
 * The orderings the Sort menu offers, as ORDERINGS rather than as a column and
 * a direction to combine.
 *
 * Nine sortable columns times two directions is eighteen entries, and a reader
 * picking "Valid until, ascending" has to work out for themselves that this
 * means "expiring soonest". A sort menu should name the answer, so these do —
 * and the ten below are the ten an office actually asks for, not the Cartesian
 * product.
 *
 * The column headers still sort, and keep taking any of the nine either way.
 * This is the discoverable half: a header is only found by a reader who
 * already suspects it is pressable.
 *
 * The first entry is the DEFAULT, which is also what the endpoint answers when
 * no sort is named — so "Newest issued" is a true description of an
 * unsorted request rather than a selection the page has to make on arrival.
 */
const SORT_OPTIONS: { value: string; label: string; key: PermitSort; dir: 'asc' | 'desc' }[] = [
  { value: 'issued_at:desc', label: 'Newest issued', key: 'issued_at', dir: 'desc' },
  { value: 'issued_at:asc', label: 'Oldest issued', key: 'issued_at', dir: 'asc' },
  { value: 'valid_until:asc', label: 'Expiring soonest', key: 'valid_until', dir: 'asc' },
  { value: 'valid_until:desc', label: 'Expiring latest', key: 'valid_until', dir: 'desc' },
  { value: 'tracking_id:asc', label: 'Tracking ID (A–Z)', key: 'tracking_id', dir: 'asc' },
  { value: 'permit_number:asc', label: 'Permit no. (A–Z)', key: 'permit_number', dir: 'asc' },
  { value: 'ban:asc', label: 'BAN (A–Z)', key: 'ban', dir: 'asc' },
  { value: 'business:asc', label: 'Business (A–Z)', key: 'business', dir: 'asc' },
  { value: 'permit_type:asc', label: 'Certificate (A–Z)', key: 'permit_type', dir: 'asc' },
  { value: 'status:asc', label: 'Status (A–Z)', key: 'status', dir: 'asc' },
]

interface Sort {
  key: PermitSort
  dir: 'asc' | 'desc'
}

/**
 * What a cell shows for a column belonging to ANOTHER office's sheet.
 *
 * The sheets share field names — `application_type` is on four of the five —
 * and every one of them reads the same `office_form` object. Without this
 * guard a zoning permit's "Nature of Application" would also appear under the
 * Sanitary and CEC headings, which is not a blank cell but a wrong one: it
 * would state that the health office asked a question and got an answer on a
 * filing it never had a sheet for.
 */
function cellFor(row: PermitRegisterRow, column: PermitColumn): string {
  if (column.office && row.permit_type?.code !== column.office) return '—'
  const value = column.value(row)
  return value === null || value.trim() === '' ? '—' : value
}

export function PermitsPage() {
  /*
   * ── Who is reading, and how many offices they can see ────────────────────
   *
   * `application.view_any_office` is the permission that makes a reader
   * office-blind: BPLO, which issues the Mayor's Permit and coordinates every
   * other office's clearance, and the super admin, who audits. Everybody else
   * is scoped by `PermitController::scopeToReader` to the certificates their
   * own office issues — a CENRO session asking for FSICs gets an empty table,
   * not the fire office's rows.
   *
   * So the Office picker was a control with exactly one answer for five of the
   * six offices, and four fifths of the table was columns their sheets never
   * fill. The client: "yung pilian ng offices kasi kung anong permit lang sa
   * kanila yung lang dapat, bplo lang dapat may ganyan."
   *
   * The picker is therefore a BPLO-and-admin control, and a single-office
   * reader gets their own office's columns without being asked. This is a
   * screen decision only — the server already refuses the rows either way, and
   * hiding a control that cannot work is not what keeps the boundary.
   */
  const permissions = useAuth((s) => s.user?.permissions)
  const department = useAuth((s) => s.user?.department?.code)

  const readsEveryOffice = permissions?.includes('application.view_any_office') ?? false
  const ownOffice = permitCodeForDepartment(department)

  /*
   * A reader who sees one office is locked to it. `null` falls back to the
   * whole table, which is the safe direction: an account this map does not
   * recognise keeps the picker rather than being shown an empty screen.
   */
  const locked: OfficeCode | null = readsEveryOffice ? null : ownOffice

  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [status, setStatus] = useState<StatusFilter>('')
  const [expiring, setExpiring] = useState<ExpiryWindow>('')
  const [issuedFrom, setIssuedFrom] = useState('')
  const [issuedTo, setIssuedTo] = useState('')
  const [chosen, setChosen] = useState<OfficeCode | ''>('')
  const [sort, setSort] = useState<Sort | null>(null)
  const [page, setPage] = useState(1)

  // What the table is actually showing: the reader's own office when they have
  // one, otherwise whatever the picker says.
  const office: OfficeCode | '' = locked ?? chosen

  /*
   * Which row's certificate is being fetched, and what went wrong if it did.
   *
   * Keyed by permit id rather than a bare boolean: a shared flag would grey
   * every View button on the page, telling 24 readers their click had stalled
   * when it was somebody else's row that was loading.
   */
  const [viewing, setViewing] = useState<number | null>(null)
  const [viewError, setViewError] = useState<string | null>(null)
  /*
   * The permit whose suspension is being lifted, or null. The whole permit
   * rather than its id, because the dialog names the certificate and the
   * business — an admin confirming a lift should not have to trust that they
   * clicked the row they meant.
   */
  const [lifting, setLifting] = useState<Permit | null>(null)
  const [liftReason, setLiftReason] = useState('')
  const [liftBusy, setLiftBusy] = useState(false)
  const [liftError, setLiftError] = useState<string | null>(null)

  const { data, loading, error, reload } = useAsync(
    () =>
      permits.register({
        q: query || undefined,
        status: status || undefined,
        permit_type: office || undefined,
        expiring_within: expiring ? Number(expiring) : undefined,
        issued_from: issuedFrom || undefined,
        issued_to: issuedTo || undefined,
        sort: sort?.key,
        dir: sort?.dir,
        page,
        per_page: PAGE_SIZE,
      }),
    [query, status, office, expiring, issuedFrom, issuedTo, sort?.key, sort?.dir, page],
  )

  // Let the admin finish typing before asking the server.
  useEffect(() => {
    const id = window.setTimeout(() => {
      setQuery(search.trim())
      setPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(id)
  }, [search])

  /*
   * Page 9 of the whole register is not page 9 of a narrowed set, and landing
   * past the end shows an empty table that reads as "no permits here".
   */
  function selectStatus(next: StatusFilter) {
    setStatus(next)
    setPage(1)
  }

  function selectOffice(next: OfficeCode | '') {
    setChosen(next)
    setPage(1)
    /*
     * A sort on a column that is about to disappear would keep ordering the
     * table by something the reader can no longer see. Only office columns
     * vanish and none of them is sortable, so the sort always survives — the
     * page reset is the whole of what changing office costs.
     */
  }

  function selectExpiring(next: ExpiryWindow) {
    setExpiring(next)
    setPage(1)
  }

  /*
   * How many things are narrowing the table.
   *
   * It colours the Filter button and it names the count in the empty state:
   * with four controls folded into one panel, "No permits" would otherwise
   * read as a fact about the register rather than about what is set inside a
   * menu the reader has closed.
   */
  const narrowed = [
    status !== '',
    expiring !== '',
    issuedFrom !== '',
    issuedTo !== '',
    query !== '',
    locked === null && chosen !== '',
  ].filter(Boolean).length

  /*
   * What the Sort menu shows as chosen.
   *
   * Derived from the sort STATE rather than held beside it, so the menu and
   * the column headers can never disagree — press a header and the menu
   * follows, pick from the menu and the header's arrow moves. Two sources of
   * truth for one ordering is how a screen ends up claiming to be sorted one
   * way while the rows are in another.
   *
   * `null` means no sort was asked for, which the endpoint answers as newest
   * issued first — the menu's first entry, and a true description rather than
   * a selection the page had to invent on arrival.
   */
  const sortOptions = useMemo(
    () => (locked === null ? SORT_OPTIONS : SORT_OPTIONS.filter((o) => o.key !== 'ban')),
    [locked],
  )

  const sortValue = sort
    ? (SORT_OPTIONS.find((o) => o.key === sort.key && o.dir === sort.dir)?.value ?? '')
    : SORT_OPTIONS[0].value

  function selectSort(value: string) {
    const picked = SORT_OPTIONS.find((o) => o.value === value)
    if (!picked) return
    /*
     * The default ordering is expressed as NO sort rather than as
     * `issued_at desc`, so the request the page sends for it is the request
     * the endpoint has always answered — one fewer way for the first page to
     * differ from what the totals are counted over.
     */
    setSort(value === SORT_OPTIONS[0].value ? null : { key: picked.key, dir: picked.dir })
    setPage(1)
  }

  function toggleSort(key: PermitSort) {
    setSort((prev) => {
      if (!prev || prev.key !== key) return { key, dir: 'asc' }
      if (prev.dir === 'asc') return { key, dir: 'desc' }
      // A third press clears it, so a reader can get back to the order the
      // register itself counts in without leaving the screen.
      return null
    })
    setPage(1)
  }

  /**
   * View — open the certificate the City issued, not a screen about it.
   *
   * `/permits/{id}/pdf` already renders the full face (owner, address, line of
   * business, signature block, QR). A second detail screen built from the list
   * payload would show less and look just as authoritative, and would drift
   * from the paper the moment either renderer changed.
   */
  async function view(permit: PermitRegisterRow) {
    /*
     * The tab is opened inside the click, before any await. By the time the
     * authenticated fetch resolves the user gesture has expired and the popup
     * blocker takes the tab silently — the rule PaymentsPage works to.
     */
    const tab = window.open('', '_blank')
    setViewing(permit.id)
    setViewError(null)
    try {
      await permits.viewPdf(permit.id, tab)
    } catch (err) {
      // Close the blank tab, or the failure leaves them staring at an empty
      // window with the message on the page behind it.
      tab?.close()
      setViewError(toApiError(err).message)
    } finally {
      setViewing(null)
    }
  }

  /*
   * The BAN goes with the office picker: both belong to a reader who has more
   * than one office in front of them. `locked === null` is that reader.
   */
  const columns = useMemo(() => columnsFor(office, locked === null), [office, locked])
  const rows = data?.data ?? []
  const total = data?.meta.total ?? 0
  const lastPage = data?.meta.last_page ?? 1

  const filterLabel = STATUS_FILTERS.find((f) => f.value === status)?.label ?? 'All'
  const officeLabel = office === '' ? 'every office' : officeOf(office)
  const sortedColumn = sort ? columns.find((c) => c.sort === sort.key)?.label : null

  return (
    <div>
      <PageTitle
        right={
          <span className="flex flex-wrap items-center gap-x-3 gap-y-2 pb-1">
            {/*
              A placeholder is not an accessible name — it disappears on the
              first keystroke — so the field carries a real label, and that
              label names EVERYTHING `q` matches. The list grew with the table:
              a box that shows a value it will not match makes a correct query
              look like missing data.
            */}
            <label htmlFor="permits-search" className="sr-only">
              Search permits by permit number, BAN, business name, owner, tracking ID or permit type
            </label>
            <input
              id="permits-search"
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Permit no., BAN, business, owner or tracking ID…"
              className="w-80 rounded-lg border border-input-border bg-input px-3.5 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
            />
            {/*
              Sort and Filter, as the two menus the rest of the app already
              carries (Proto's SortFilter, p14). They were five controls laid
              out across the header — status pills, an office select, expiry
              pills and two date inputs — which is a filter bar rather than a
              header, and it pushed the table itself below the fold on a
              laptop.

              Everything that narrowed the table still narrows it; it narrows
              it from inside the Filter panel. The office select appears there
              only for a reader who can see more than one office, which is the
              same rule that governed it in the row.
            */}
            <SortFilter
              sort={{
                value: sortValue,
                /*
                 * Never an ordering by a column the reader cannot see. The BAN
                 * is off an office's table, so "BAN (A–Z)" would silently
                 * reorder the rows by something invisible — which reads as the
                 * sort having done nothing.
                 */
                options: sortOptions.map(({ value, label }) => ({ value, label })),
                onChange: selectSort,
              }}
              filter={{
                value: status,
                options: STATUS_FILTERS.map(({ value, label }) => ({ value, label })),
                onChange: (v: string) => selectStatus(v as StatusFilter),
              }}
              filterFields={[
                {
                  label: 'Expiring',
                  value: expiring,
                  options: EXPIRY_FILTERS.map(({ value, label }) => ({ value, label })),
                  onChange: (v: string) => selectExpiring(v as ExpiryWindow),
                },
                ...(locked === null
                  ? [
                      {
                        label: 'Office',
                        value: chosen,
                        options: [
                          { value: '', label: 'All offices — every column' },
                          ...OFFICES.map((o) => ({ value: o.code, label: `${o.office} — ${o.name}` })),
                        ],
                        onChange: (v: string) => selectOffice(v as OfficeCode | ''),
                      },
                    ]
                  : []),
              ]}
              dateRange={{
                from: issuedFrom,
                to: issuedTo,
                onChange: (from: string, to: string) => {
                  setIssuedFrom(from)
                  setIssuedTo(to)
                  setPage(1)
                },
              }}
            />
            <button
              type="button"
              onClick={reload}
              // aria-disabled, never the native attribute (AGENTS.md §6.2): a
              // disabled control leaves the tab order, so a keyboard reader
              // loses Refresh while the thing it refreshes is loading. The
              // handler needs no guard — `reload` bumps a nonce and useAsync
              // cancels the in-flight request.
              aria-disabled={loading || undefined}
              className="rounded-full border border-line bg-white px-4 py-2 text-sm font-semibold text-ink-secondary hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-40"
            >
              Refresh
            </button>
          </span>
        }
      >
        Permits
      </PageTitle>


      {/*
        The failure of one row's View, said once above the table rather than
        inside the row. A 403 here is almost always the office boundary
        answering — PermitController::authorizeView refuses a certificate
        issued by another office — and that sentence is worth reading in full,
        which it cannot be in a table cell.
      */}
      {viewError && (
        <p role="alert" className="mb-4 rounded-lg border border-s-red/30 bg-s-red-tint px-4 py-3 text-sm text-s-red">
          {viewError}
        </p>
      )}

      {loading ? (
        <SkeletonList rows={8} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={FileTextIcon}
          /*
           * The status word only belongs in the title when a status was
           * CHOSEN. It read "No all permits" whenever anything else emptied
           * the table, because the neutral status is labelled "All" and the
           * title interpolated it regardless — a sentence that is not English
           * and, worse, names the one control that was not responsible.
           */
          title={
            query
              ? 'No permits match your search'
              : status
                ? `No ${filterLabel.toLowerCase()} permits`
                : narrowed > 0
                  ? 'No permits match these filters'
                  : 'No permits yet'
          }
          description={
            query
              ? 'Search matches the permit number, the BAN, the business name, the owner, the tracking ID and the permit type. Try another spelling.'
              : narrowed > 0
                ? /*
                   * Four controls live inside a panel the reader has closed,
                   * so an empty table would otherwise read as a fact about the
                   * register. The count says how many are set and where they
                   * are, which is the whole of what a reader needs to undo
                   * them.
                   */
                  `${narrowed} filter${narrowed === 1 ? ' is' : 's are'} narrowing this. Open Filter above to change ${narrowed === 1 ? 'it' : 'them'}.`
                : 'Permits appear here as offices approve filings and issue certificates.'
          }
        />
      ) : (
        <ProtoCard className="overflow-hidden rounded-xl">
          {/*
            The table is wider than the screen by design, so the scroller is
            focusable and labelled: a region that scrolls but cannot be reached
            by keyboard hides every column past the fold from a reader who does
            not use a mouse. `tabIndex={0}` on a scroll container is the one
            case where that is correct rather than a stray tab stop.
          */}
          <div
            className="overflow-x-auto focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-royal"
            tabIndex={0}
            role="region"
            aria-label={`Issued permits for ${officeLabel}, scrolls sideways`}
          >
            <table className="w-max text-left text-sm">
              <thead>
                <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  {columns.map((column) => {
                    const active = sort !== null && column.sort === sort.key
                    return (
                      <th
                        key={column.key}
                        scope="col"
                        // The sort state belongs on the column: a screen reader
                        // announces `aria-sort` and cannot read a glyph. An
                        // unsortable column says nothing rather than "none",
                        // which would claim it could be sorted.
                        aria-sort={
                          column.sort === undefined
                            ? undefined
                            : active
                              ? sort.dir === 'asc'
                                ? 'ascending'
                                : 'descending'
                              : 'none'
                        }
                        className="whitespace-nowrap px-4 py-3"
                      >
                        {column.sort === undefined ? (
                          /*
                            Plain text, not a button. The server orders through
                            a whitelist of nine columns and an office-sheet
                            answer lives in a JSON blob no index reaches, so a
                            pressable header here would do nothing.
                          */
                          <span>{column.label}</span>
                        ) : (
                          <button
                            type="button"
                            onClick={() => toggleSort(column.sort as PermitSort)}
                            className="inline-flex items-center gap-1 rounded uppercase tracking-wider hover:text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
                          >
                            {column.label}
                            <span aria-hidden="true" className={active ? 'text-royal' : 'opacity-40'}>
                              {active ? (sort.dir === 'asc' ? '▲' : '▼') : '⇅'}
                            </span>
                          </button>
                        )}
                        {/*
                          Which office's form this column comes from. Only
                          drawn with every office in view: once an office is
                          chosen, repeating its name on nine headings is noise,
                          and the table already says whose it is above.
                        */}
                        {column.office && office === '' && (
                          <span className="mt-0.5 block text-[10px] font-medium normal-case tracking-normal text-royal">
                            {officeOf(column.office)} form
                          </span>
                        )}
                      </th>
                    )
                  })}
                  {/*
                    The actions column has no heading text a reader needs, but
                    it cannot be an empty `<th>`: a column header with no
                    accessible name is announced as blank. The word is there and
                    hidden.
                  */}
                  <th scope="col" className="px-4 py-3 text-right">
                    <span className="sr-only">Actions</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.map((permit) => (
                  <tr key={permit.id} className="border-t border-line">
                    {columns.map((column) => {
                      const text = cellFor(permit, column)
                      return (
                        <td
                          key={column.key}
                          className={[
                            'whitespace-nowrap px-4 py-3.5',
                            column.tnum ? 'tnum' : '',
                            // The lead identifier carries the row, so it is
                            // the one drawn in full ink.
                            column.key === 'tracking_id' ? 'font-bold text-ink' : 'text-ink-secondary',
                          ]
                            .filter(Boolean)
                            .join(' ')}
                        >
                          {column.key === 'status' ? (
                            /*
                              The chip is tinted AND worded — "Never Color
                              Alone" (DESIGN.md). The label comes from the
                              server so the browser never has to name a status
                              it has not been taught, which is how a `revoked`
                              row would otherwise render as a blank pill.
                            */
                            <StatusChip tone={STATUS_TONES[permit.status] ?? 'tint-gray'}>
                              {permit.status_label}
                            </StatusChip>
                          ) : (
                            text
                          )}
                        </td>
                      )
                    })}
                    <td className="px-4 py-3.5 text-right">
                      <button
                        type="button"
                        onClick={() => view(permit)}
                        /*
                         * Twenty-five buttons reading "View" are twenty-five
                         * identical stops for a screen reader (AGENTS.md §6.2),
                         * so the permit number goes in the accessible name and
                         * the visible label stays one word.
                         */
                        aria-label={`View certificate ${permit.permit_number}`}
                        aria-disabled={viewing === permit.id || undefined}
                        className="rounded-full border border-royal px-4 py-1.5 text-xs font-semibold text-royal hover:bg-royal hover:text-white aria-disabled:cursor-wait aria-disabled:opacity-50"
                      >
                        {viewing === permit.id ? 'Opening…' : 'View'}
                      </button>
                      {/*
                        ── Lift, on suspended rows only ──────────────────────

                        A suspension is automatic: a clearance office refusing
                        one of the other permits suspends the business permit
                        in the same transaction, so nothing waits on a queue
                        being opened. This is the other half the LGU asked for
                        — *"CAN be suspended"* — a person able to overrule it.

                        Drawn only where it applies rather than disabled
                        everywhere: on an active permit it is not a control in
                        a wrong state, it is a control about nothing.

                        The permit number is in the accessible name for the
                        reason the View button beside it gives.
                      */}
                      {permit.status === 'suspended' && (
                        <button
                          type="button"
                          onClick={() => setLifting(permit)}
                          aria-label={`Lift the suspension on ${permit.permit_number}`}
                          className="ml-2 rounded-full border border-s-red px-4 py-1.5 text-xs font-semibold text-s-red hover:bg-s-red hover:text-white"
                        >
                          Lift
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {lifting !== null && (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 px-4">
              <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="lift-heading"
                className="w-full max-w-md rounded-xl bg-white p-6 shadow-raised"
              >
                <h2 id="lift-heading" className="text-base font-bold text-ink">
                  Lift the suspension on {lifting.permit_number}?
                </h2>
                {/*
                  What the act does and what it deliberately does NOT do. An
                  admin who believes this also grants the refused clearance
                  would be lifting it for a reason that is not true.
                */}
                <p className="mt-2 text-sm leading-relaxed text-ink-secondary">
                  {businessName(lifting.business)} may trade on this permit again. The permit
                  that was rejected stays rejected — that is the issuing office’s decision,
                  not yours — so this says the business may operate while it is unsettled.
                </p>
                <label className="mt-4 block">
                  <span className="text-xs font-bold uppercase tracking-wide text-ink-muted">
                    Why are you lifting it?
                  </span>
                  <textarea
                    value={liftReason}
                    onChange={(e) => setLiftReason(e.target.value)}
                    rows={3}
                    className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
                    placeholder="e.g. CHO confirmed the refusal was filed against the wrong business."
                  />
                </label>
                {/* This is audited and read back later; say so where it is typed. */}
                <p className="mt-1 text-xs text-ink-muted">
                  Recorded in the audit log against your account.
                </p>
                {liftError !== null && (
                  <p role="alert" className="mt-2 text-xs font-medium text-s-red">
                    {liftError}
                  </p>
                )}
                <div className="mt-5 flex justify-end gap-3">
                  <button
                    type="button"
                    onClick={() => {
                      setLifting(null)
                      setLiftReason('')
                      setLiftError(null)
                    }}
                    className="rounded-full border border-line px-5 py-2 text-sm font-semibold text-ink hover:bg-shell-deep"
                  >
                    Cancel
                  </button>
                  {/*
                    `aria-disabled`, never `disabled` (AGENTS.md §6.2): a screen
                    reader skips a disabled control and takes the sentence
                    explaining it along too.
                  */}
                  <button
                    type="button"
                    aria-disabled={liftBusy || liftReason.trim() === '' || undefined}
                    onClick={async () => {
                      if (liftBusy || liftReason.trim() === '') return
                      setLiftBusy(true)
                      setLiftError(null)
                      try {
                        await permits.liftSuspension(lifting.id, liftReason.trim())
                        setLifting(null)
                        setLiftReason('')
                        reload()
                      } catch (err) {
                        setLiftError(toApiError(err).message)
                      } finally {
                        setLiftBusy(false)
                      }
                    }}
                    className="rounded-full bg-s-red px-5 py-2 text-sm font-semibold text-white hover:brightness-110 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                  >
                    {liftBusy ? 'Lifting…' : 'Lift suspension'}
                  </button>
                </div>
              </div>
            </div>
          )}

          <div className="flex items-center justify-between gap-4 border-t border-line px-5 py-3.5">
            <div>
              {/*
                Both numbers named (AGENTS.md §6.4). "Showing 25" says nothing;
                "25 of 2,182 active permits" says what the pager is moving
                through.
              */}
              <p role="status" aria-live="polite" className="text-sm text-ink-muted">
                Showing {rows.length.toLocaleString()} of {total.toLocaleString()}{' '}
                {status ? `${filterLabel.toLowerCase()} permits` : 'issued permits'}
                {office !== '' && ` issued by ${officeOf(office)}`}
                {expiring && ` expiring within ${expiring} days`}
                {issuedFrom && ` issued from ${formatDate(issuedFrom)}`}
                {issuedTo && ` issued up to ${formatDate(issuedTo)}`}
                {query && ' matching your search'}
              </p>
              <p className="mt-1 text-xs text-ink-muted">
                {/*
                  The sort's REACH, said plainly. It used to run in the browser
                  over the page in hand and the footer had to admit it; the
                  server orders the whole register now, and saying so is what
                  tells a reader that page 2 continues the order rather than
                  restarting it.
                */}
                {sortedColumn
                  ? `Sorted by ${sortedColumn}, ${sort?.dir === 'asc' ? 'ascending' : 'descending'}, across the whole register.`
                  : 'Ordered by issue date, newest first.'}
                {office === '' &&
                  ' Every office’s form is shown; pick an office above to see only its own columns.'}
                {locked !== null &&
                  ` These are ${officeOf(locked)}’s certificates — the ones filed with this office.`}
              </p>
            </div>
            <div className="flex items-center gap-1.5">
              <button
                type="button"
                aria-label="Previous page"
                // aria-disabled, never the native attribute: the pager must
                // stay in the tab order at either end of the register so a
                // keyboard reader is told they have reached one rather than
                // losing the control.
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                aria-disabled={page <= 1 || loading || undefined}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-40"
              >
                ‹
              </button>
              <span className="text-xs text-ink-muted">
                Page {page.toLocaleString()} of {lastPage.toLocaleString()}
              </span>
              <button
                type="button"
                aria-label="Next page"
                onClick={() => setPage((p) => Math.min(lastPage, p + 1))}
                aria-disabled={page >= lastPage || loading || undefined}
                className="flex h-7 w-7 items-center justify-center rounded-md border border-line text-sm text-ink-secondary hover:bg-canvas aria-disabled:cursor-not-allowed aria-disabled:opacity-40"
              >
                ›
              </button>
            </div>
          </div>
        </ProtoCard>
      )}
    </div>
  )
}
