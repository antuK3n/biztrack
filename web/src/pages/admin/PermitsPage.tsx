import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { permits } from '../../lib/resources'
import { toApiError } from '../../lib/api'
import { businessName } from '../../lib/format'
import { useAsync } from '../../lib/useAsync'
import type { Permit, PermitRegisterRow } from '../../lib/types'
import { BusinessMapPage } from './BusinessMapPage'
import type { PermitSort } from '../../lib/resources'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { PageTitle, ProtoCard, SortFilter, StatusChip } from '../../components/ui/Proto'
import type { ChipTone } from '../../components/ui/Proto'
import { FileTextIcon } from '../../components/icons'
import { DocumentActions } from '../../components/DocumentActions'
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
 * ── Revoke ─────────────────────────────────────────────────────────────────
 *
 * On this screen since checklist item 23, for BPLO only (`permit.revoke`), and
 * on the Mayor's Permit only [client, 4 October 2026: "paki tanggal ang revoke
 * sa super admin, at bplo, dapat sa bplo ayon lang kaya nyang i revoke"]; the
 * server refuses the rest (PermitController::revoke). It was held back
 * until then on purpose: a revoked permit means a business is trading
 * unlawfully, and a button with no audit, no notice and no public effect
 * behind it would have documented an act nobody authorised. The server does
 * all three now (WorkflowService::revokePermit), so the control exists, on
 * rows that are in force, behind a dialog that names the permit and business
 * and will not proceed without a reason.
 *
 * ── Three things this screen answers besides the table ─────────────────────
 *
 *   Other offices   the five clearances as one view, apart from BPLO's own
 *                   Mayor's Permits (checklist item 18) — an Office choice.
 *   Retired         businesses removed from the register, hidden unless the
 *                   Filter asks for them (checklist item 21).
 *   Map             every business at its pin, by the state of its Mayor's
 *                   Permit (checklist item 16) — BPLO and the super admin.
 */

/** Rows per request. Matches Records and Owner Status. */
const PAGE_SIZE = 25

/** How long to wait before a keystroke becomes a request. Same as Records. */
const SEARCH_DEBOUNCE_MS = 300

/**
 * The status filter — every state a permit can be in.
 *
 * It offered three while nothing wrote Revoked, on the rule "add the option in
 * the same change that adds the writer". This is that change, and Suspended —
 * written since the business permit began being suspended when another office
 * refuses — comes with it.
 */
type StatusFilter = '' | 'active' | 'expired' | 'suspended' | 'revoked' | 'superseded'

const STATUS_FILTERS: { value: StatusFilter; label: string }[] = [
  { value: '', label: 'All' },
  { value: 'active', label: 'Active' },
  { value: 'expired', label: 'Expired' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'revoked', label: 'Revoked' },
  { value: 'superseded', label: 'Superseded' },
]

/**
 * The Office choice, which is an office's code or one of two groupings.
 *
 * `''` is the whole register, every office's sheet side by side — the long
 * table the client asked for. `OTHER` is checklist item 18's "other permits in
 * a separate view": BPLO's own table is the Mayor's Permit, and the five
 * clearances the other offices issue are the second view, asked of the server
 * as "every type but BUSINESS" rather than five requests.
 */
type OfficeChoice = OfficeCode | '' | typeof OTHER_OFFICES

const OTHER_OFFICES = 'OTHER'

/**
 * Retired businesses — removed from the register, their certificates kept
 * (checklist item 21). Hidden by default: a clerk working the register is
 * working live businesses, and a retired one's permits are history.
 */
type RetiredFilter = 'hide' | 'include' | 'only'

const RETIRED_FILTERS: { value: RetiredFilter; label: string }[] = [
  { value: 'hide', label: 'Hidden' },
  { value: 'include', label: 'Shown with the rest' },
  { value: 'only', label: 'Only retired businesses' },
]

/** A permit that is in force — the only kind Revoke is offered on. */
function revocable(permit: PermitRegisterRow): boolean {
  return (
    permit.permit_type?.code === 'BUSINESS' &&
    (permit.status === 'active' || permit.status === 'suspended')
  )
}

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

/*
 * ── The Expiring window is not on this screen ─────────────────────────────
 *
 * It was a filter here — Any / within 30 / 60 / 90 days — and the client took
 * it off [24 September 2026: "sa filter yung 'Expiring Any'"].
 *
 * `expiring_within` remains on the endpoint, documented and covered by tests:
 * it is a real capability, the Renewal Risk screen asks the same question of
 * the same data, and deleting a tested server filter because one page stopped
 * sending it would be throwing away the work rather than the control. Nothing
 * here sends it.
 *
 * The two orderings that answer the same question from the Sort menu —
 * "Expiring soonest" and "Expiring latest" — stay. They narrow nothing, so
 * they are not the control that was removed.
 */

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

/*
 * What BPLO reads on another office's tab: enough to know WHICH permit, for
 * WHICH business, and whether it is in force — then the certificate itself in
 * the last column. No office-sheet answers, no uploads, and not the columns
 * that are constant within one office's tab (its certificate name and office).
 * Valid-from and issued-on are left off too: on a clearance they are the same
 * date. Issued-by is left off because it names the OTHER office's officer,
 * which BPLO has no use for — and without these the certificate column fits.
 */
const PERMIT_ONLY_KEYS = new Set([
  'tracking_id',
  'permit_number',
  'business',
  'owner_name',
  'status',
  'valid_until',
  'days',
])

/** A permit's uploads: a count that expands into View/Download per file. */
function UploadsCell({ permit }: { permit: PermitRegisterRow }) {
  const [open, setOpen] = useState(false)
  const docs = permit.documents

  if (!docs) return <span className="text-xs text-ink-muted">—</span>
  if (docs.length === 0) return <span className="text-xs text-ink-muted">None uploaded</span>

  return (
    <div className="min-w-[13rem]">
      <button
        type="button"
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        aria-label={`${open ? 'Hide' : 'Show'} ${docs.length} uploaded requirement${docs.length === 1 ? '' : 's'} for ${permit.permit_number}`}
        className="inline-flex items-center gap-1.5 rounded-full border border-line bg-white px-3 py-1 text-xs font-semibold text-ink hover:border-royal hover:text-royal"
      >
        <span aria-hidden="true" className="text-[10px] text-ink-muted">{open ? '▾' : '▸'}</span>
        {docs.length} file{docs.length === 1 ? '' : 's'}
      </button>
      {open && (
        <ul className="mt-2 space-y-2.5">
          {docs.map((d) => (
            <li key={d.id}>
              {/*
                A file the office asked for after filing, under Other
                Requirements, says so — otherwise it reads as one of the
                requirements filed at the start [client, 4 October 2026].
              */}
              {d.from_request && (
                <div className="mb-1">
                  <StatusChip tone="tint-orange">Other Requirements</StatusChip>
                </div>
              )}
              <p className="text-xs font-semibold text-ink">{d.name}</p>
              <p className="max-w-[16rem] truncate text-[11px] text-ink-muted" title={d.filename}>
                {d.filename}
              </p>
              {d.from_request && (
                <p className="mt-0.5 max-w-[16rem] text-[11px] leading-snug text-ink-muted">
                  Sent by the business owner when the office requested it.
                </p>
              )}
              <div className="mt-1">
                <DocumentActions id={d.id} filename={d.filename} label={d.name} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/**
 * Which office's permits — a visible tab strip, not a menu entry.
 *
 * One tab per office, named the way staff say it (the department code) with
 * the certificate under it, so "BPLO" and "Mayor's / Business Permit" are read
 * together without a 70-character option. The full office name is the tab's
 * accessible name and its tooltip.
 *
 * It scrolls sideways on a narrow screen rather than wrapping into a block of
 * pills, so the strip stays one line and the table stays where the eye left it.
 */
const OFFICE_TAB_CODES: Record<OfficeCode, string> = {
  BUSINESS: 'BPLO',
  ZONING: 'Zoning',
  SANITARY: 'CHO',
  FSIC: 'BFP',
  OCCUPANCY: 'OBO',
  CEC: 'CENRO',
}

function OfficeTabs({
  value,
  onChange,
  includeAll = true,
}: {
  value: OfficeChoice
  onChange: (v: OfficeChoice) => void
  /** ALL is the super admin's; BPLO is shown its own office and the five others. */
  includeAll?: boolean
}) {
  const tabs: { value: OfficeChoice; short: string; sub: string; full: string }[] = [
    // "ALL", and no "Other offices" tab [client, 4 October 2026]. The OTHER
    // view still exists behind the choice type — a link can open it — but it
    // is not one of the tabs.
    { value: '', short: 'ALL', sub: 'Every permit', full: 'All offices, every permit' },
    ...OFFICES.map((o) => ({ value: o.code as OfficeChoice, short: OFFICE_TAB_CODES[o.code], sub: o.name, full: `${o.office} — ${o.name}` })),
  ]

  const shown = includeAll ? tabs : tabs.filter((t) => t.value !== '')

  return (
    // Wraps rather than scrolls: at 1440px a one-line strip cut CENRO in half
    // and hid "Other offices" off the edge, and a tab nobody can see is a
    // filter nobody uses.
    <div role="group" aria-label="Office" className="mb-4 overflow-x-auto pb-1">
      {/*
        One straight line [client, 4 October 2026]: seven equal columns. A long
        certificate name wraps inside its own tab rather than pushing the strip
        onto a second row, so every tab stays the same width and the row reads
        as one control. Below `md` it scrolls sideways instead of squeezing
        seven columns into a phone.
      */}
      <div className="grid min-w-[44rem] gap-2" style={{ gridTemplateColumns: `repeat(${shown.length}, minmax(0, 1fr))` }}>
        {shown.map((t) => {
          const active = t.value === value
          return (
            <button
              key={t.value || 'all'}
              type="button"
              aria-pressed={active}
              aria-label={t.full}
              title={t.full}
              onClick={() => onChange(t.value)}
              className={`flex min-w-0 flex-col items-start justify-center rounded-lg border px-3 py-2 text-left transition-colors ${
                active
                  ? 'border-royal bg-royal text-white shadow-card'
                  : 'border-line bg-white text-ink hover:border-royal'
              }`}
            >
              <span className="text-sm font-bold leading-tight">{t.short}</span>
              <span className={`text-[11px] leading-tight ${active ? 'text-white/85' : 'text-ink-muted'}`}>
                {t.sub}
              </span>
            </button>
          )
        })}
      </div>
    </div>
  )
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
  /*
   * ── BPLO reads every office's PERMITS, not every office's files ─────────
   *
   * [Client, 4 October 2026: "sa bplo side, bat nakikita nya lahat? dapat yung
   * permit nya lang … at mga mismong permit sa other offices, no need sa ibang
   * fields".] BPLO and the super admin both hold `application.view_any_office`,
   * so this screen treated them alike. They are not alike: the super admin
   * audits everything; BPLO issues the Mayor's Permit and needs to SEE that the
   * other offices' permits exist, not to read their sheets and uploads.
   *
   * So for BPLO: its own tab carries everything, and another office's tab is
   * the permits alone — the shared columns and the certificate, no office-sheet
   * columns, no requirements. `user.manage` is what marks the super admin.
   */
  const isSuperAdmin = permissions?.includes('user.manage') ?? false
  const bploView = readsEveryOffice && !isSuperAdmin
  const canRevoke = permissions?.includes('permit.revoke') ?? false
  const ownOffice = permitCodeForDepartment(department)

  /*
   * A link can open this page already searching — the Business Map's "Find in
   * register" does, with `?q=<permit no.>&office=all`, so the permit it names
   * is found whichever office the reader would otherwise open on. Read once,
   * as initial state; after that the controls own it.
   */
  const [params] = useSearchParams()
  const linkedQuery = params.get('q') ?? ''
  const linkedAllOffices = params.get('office') === 'all'

  /*
   * A reader who sees one office is locked to it. `null` falls back to the
   * whole table, which is the safe direction: an account this map does not
   * recognise keeps the picker rather than being shown an empty screen.
   */
  const locked: OfficeCode | null = readsEveryOffice ? null : ownOffice

  const [search, setSearch] = useState(linkedQuery)
  const [query, setQuery] = useState(linkedQuery)
  const [status, setStatus] = useState<StatusFilter>('')
  const [retired, setRetired] = useState<RetiredFilter>('hide')
  /*
   * Table or Map. The map is BPLO's and the super admin's (the endpoint is
   * gated on the same `application.view_any_office`), so a single-office
   * reader never gets the switch.
   */
  const [mode, setMode] = useState<'table' | 'map'>('table')
  /*
   * No issued-date range. The Filter panel carried a From / To pair over
   * `issued_at` and the client took it out [1 October 2026].
   *
   * The register is already ordered by issuance, newest first, and the Sort
   * menu offers that column in both directions — so the question the pair
   * answered is one the table answers by scrolling, at the cost of two date
   * inputs that are the only typing in a menu of choices.
   *
   * `issued_from` / `issued_to` remain on the API (see PermitController), so
   * nothing server-side is lost and a reader that wants the range back needs
   * only the control.
   */
  /*
   * ── Where the picker starts ──────────────────────────────────────────────
   *
   * On the reader's OWN office when they have one, on every office when they
   * do not [client, 24 September 2026: "bplo admin office, make the office
   * permit default sa bplo, but still sa filter ganon pa rin meron all
   * offices, at yung 6 other offices and their permits"].
   *
   * That is one rule and it lands correctly on both readers who get a picker:
   *
   *   BPLO         belongs to BPLO, so it opens on the Mayor's Permit — the
   *                certificate it issues, and the work in front of it — and
   *                widens to the whole register whenever it wants.
   *   super admin  belongs to no office, so it opens on all six. There is no
   *                "own office" to open on, and defaulting it to BPLO's would
   *                be an auditor arriving pre-filtered to one office's work.
   *
   * Written as a `useState` initialiser rather than an effect: an effect would
   * render the unfiltered table first and then narrow it, which is a visible
   * flash of the whole register and a wasted request.
   */
  const [chosen, setChosen] = useState<OfficeChoice>(() => (linkedAllOffices ? '' : (ownOffice ?? '')))
  const [sort, setSort] = useState<Sort | null>(null)
  const [page, setPage] = useState(1)

  // What the table is actually showing: the reader's own office when they have
  // one, otherwise whatever the picker says.
  const choice: OfficeChoice = locked ?? chosen
  // The columns to draw. The Other offices view is the register minus the
  // Mayor's Permit, which has no sheet, so it draws the register's columns.
  const office: OfficeCode | '' = choice === OTHER_OFFICES ? '' : choice

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
  /*
   * The permit being revoked, or null. Whole row for the same reason as
   * `lifting`: the dialog names the certificate and the business, so the
   * officer confirms the row they meant rather than an id.
   */
  const [revoking, setRevoking] = useState<PermitRegisterRow | null>(null)
  const [revokeReason, setRevokeReason] = useState('')
  const [revokeBusy, setRevokeBusy] = useState(false)
  const [revokeError, setRevokeError] = useState<string | null>(null)

  const { data, loading, error, reload } = useAsync(
    () =>
      permits.register({
        q: query || undefined,
        status: status || undefined,
        permit_type: choice === OTHER_OFFICES ? undefined : choice || undefined,
        exclude_permit_type: choice === OTHER_OFFICES ? 'BUSINESS' : undefined,
        retired,
        sort: sort?.key,
        dir: sort?.dir,
        page,
        per_page: PAGE_SIZE,
      }),
    [query, status, choice, retired, sort?.key, sort?.dir, page],
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

  function selectOffice(next: OfficeChoice) {
    setChosen(next)
    setPage(1)
    /*
     * A sort on a column that is about to disappear would keep ordering the
     * table by something the reader can no longer see. Choosing the Mayor's
     * Permit drops Valid until and the certificate column, both sortable, so
     * the render below clears a sort on either rather than this handler
     * guessing which columns the next office keeps.
     */
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
    query !== '',
    locked === null && chosen !== '',
    retired !== 'hide',
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
  const permitOnly = bploView && office !== 'BUSINESS'
  const columns = useMemo(
    () =>
      permitOnly
        ? columnsFor(office).filter(
            // The certificate type only on the mixed list the Business Map links to
            // (?office=all); on one office's tab every row is the same type.
            (c) => PERMIT_ONLY_KEYS.has(c.key) || (office === '' && c.key === 'permit_type'),
          )
        : columnsFor(office),
    [office, permitOnly],
  )

  /*
   * Only the orderings whose column is on screen. BPLO's own table has no
   * Valid until (checklist item 17), and an "Expiring soonest" that reordered
   * the rows by a date nobody can see reads as a sort that did nothing — the
   * reason the BAN ordering left with the BAN column.
   */
  const sortOptions = SORT_OPTIONS.filter((o) => columns.some((c) => c.sort === o.key))

  /*
   * A sort on a column that has just left the table (BPLO switching to its own
   * office while sorted by expiry) is dropped rather than kept ordering the
   * rows by something invisible. Done while rendering, which is React's
   * pattern for state that follows other state, rather than in an effect that
   * would draw one frame of the wrong order first.
   */
  if (sort !== null && !columns.some((c) => c.sort === sort.key)) {
    setSort(null)
  }

  const sortValue = sort
    ? (sortOptions.find((o) => o.key === sort.key && o.dir === sort.dir)?.value ?? '')
    : SORT_OPTIONS[0].value

  function selectSort(value: string) {
    const picked = sortOptions.find((o) => o.value === value)
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

  const rows = data?.data ?? []
  const total = data?.meta.total ?? 0
  const lastPage = data?.meta.last_page ?? 1

  const filterLabel = STATUS_FILTERS.find((f) => f.value === status)?.label ?? 'All'
  const officeLabel =
    choice === OTHER_OFFICES ? 'every office but BPLO' : office === '' ? 'every office' : officeOf(office)

  /** Find a permit the map pointed at: back to the table, the whole register, searched. */
  function findInRegister(permitNumber: string) {
    setMode('table')
    setChosen('')
    setStatus('')
    setRetired('include')
    setSearch(permitNumber)
    setQuery(permitNumber)
    setPage(1)
  }

  async function confirmRevoke() {
    if (revoking === null || revokeBusy || revokeReason.trim() === '') return
    setRevokeBusy(true)
    setRevokeError(null)
    try {
      await permits.revoke(revoking.id, revokeReason.trim())
      closeRevoke()
      reload()
    } catch (err) {
      setRevokeError(toApiError(err).message)
    } finally {
      setRevokeBusy(false)
    }
  }

  function closeRevoke() {
    setRevoking(null)
    setRevokeReason('')
    setRevokeError(null)
  }
  const sortedColumn = sort ? columns.find((c) => c.sort === sort.key)?.label : null

  return (
    <div>
      <PageTitle
        right={
          /* The table's controls; the map carries its own, so none of these would act on it. */
          mode === 'map' && readsEveryOffice ? undefined : (
          <span className="flex flex-wrap items-center gap-x-3 gap-y-2 pb-1">
            {/*
              A placeholder is not an accessible name — it disappears on the
              first keystroke — so the field carries a real label, and that
              label names EVERYTHING `q` matches.

              The PLACEHOLDER no longer tries to. It read "Permit no., BAN,
              business, owner or tracking ID…", which is forty-six characters
              in a 320px box: it was clipped mid-list on the screen it was
              written for, so the reader got "…business, owner or tracki" and
              could not tell whether the sixth thing they wanted to search by
              was in the part they could not see.

              The list is still told, twice, in the two places it is wanted:
              here for a screen reader, and in the empty state — "Search
              matches the permit number, the BAN, the business name, the
              owner, the tracking ID and the permit type" — which is the
              moment a correct query looks like missing data and the only
              moment the full list changes what the reader does next.
            */}
            <label htmlFor="permits-search" className="sr-only">
              Search permits by permit number, BAN, business name, owner, tracking ID or permit type
            </label>
            <input
              id="permits-search"
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search permits…"
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
                options: sortOptions.map(({ value, label }) => ({ value, label })),
                onChange: selectSort,
              }}
              filter={{
                value: status,
                options: STATUS_FILTERS.map(({ value, label }) => ({ value, label })),
                onChange: (v: string) => selectStatus(v as StatusFilter),
              }}
              filterFields={[
                // Office moved OUT of this menu to the OfficeTabs strip above the
                // table [client, 4 October 2026: "paki labas na lang sa filter"]:
                // it decides which columns exist, so it is the first choice made.
                {
                  label: 'Retired businesses',
                  value: retired,
                  options: RETIRED_FILTERS,
                  onChange: (v: string) => {
                    setRetired(v as RetiredFilter)
                    setPage(1)
                  },
                },
              ]}
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
          )
        }
      >
        Permits
      </PageTitle>

      {/*
        Table or Map, for the two readers the map is for. A tab strip in the
        style the Analytics screens use, with the selected view marked by
        `aria-pressed` as well as fill, so the choice is not colour alone.
      */}
      {readsEveryOffice && (
        <div role="group" aria-label="Permits view" className="-mt-2 mb-5 flex flex-wrap gap-2">
          {(
            [
              { value: 'table', label: 'Table' },
              { value: 'map', label: 'Map' },
            ] as const
          ).map((tab) => (
            <button
              key={tab.value}
              type="button"
              aria-pressed={mode === tab.value}
              onClick={() => setMode(tab.value)}
              className={`rounded-full border px-5 py-1.5 text-sm font-semibold transition-colors ${
                mode === tab.value
                  ? 'border-royal bg-royal text-white'
                  : 'border-line bg-white text-ink-secondary hover:border-royal hover:text-royal'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      )}

      {mode === 'map' && readsEveryOffice ? (
        <BusinessMapPage embedded onFindInRegister={findInRegister} />
      ) : (
      <>
      {locked === null && <OfficeTabs value={chosen} onChange={selectOffice} includeAll={!bploView} />}


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
            <table className={`${permitOnly ? "w-full" : "w-max"} text-left text-sm`}>
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
                  {/*
                    ── The office's uploads, then the permit itself, last ──────

                    [Client, 4 October 2026: "lahat ng requirements na pinasa,
                    inupload lahat makikita rin dapat doon … tas pang last ang
                    mismong permit nya".] The row now reads in the order the
                    filing happened: who and what (shared columns), what the
                    office's own sheet asked, what was uploaded for it, and
                    finally the certificate it produced.

                    The last heading is visible now. It was a hidden "Actions",
                    which named the buttons rather than the thing they open.
                  */}
                  {!permitOnly && (
                    <th scope="col" className="whitespace-nowrap px-4 py-3">
                      Requirements Submitted
                    </th>
                  )}
                  <th scope="col" className="whitespace-nowrap px-4 py-3 text-right">
                    Permit
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.map((permit) => (
                  <tr key={permit.id} className="border-t border-line align-top">
                    {columns.map((column) => {
                      const text = cellFor(permit, column)
                      return (
                        <td
                          key={column.key}
                          className={[
                            // The permit-only view fits the card: names may wrap there rather than
                            // push the certificate column off the edge.
                            permitOnly && (column.key === 'business' || column.key === 'owner_name') ? 'px-4 py-3.5' : 'whitespace-nowrap px-4 py-3.5',
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
                    {/*
                      Each upload with its own View and Download, through
                      DocumentActions — the endpoint is authenticated, so a bare
                      link would fetch the login page. Named by what the document
                      IS, not its filename, so a screen reader hears "Barangay
                      Business Clearance" rather than "scan_003.pdf".
                    */}
                    {/*
                      Collapsed to a count until asked. Listed in full, three
                      uploads with their View/Download buttons made one row
                      ~250px tall and pushed the rest of the register down a
                      screen — the table stopped being something you run your
                      eye down. The count says there IS something; the toggle
                      shows it.
                    */}
                    {!permitOnly && (
                      <td className="px-4 py-3.5">
                        <UploadsCell permit={permit} />
                      </td>
                    )}
                    <td className="whitespace-nowrap px-4 py-3.5 text-right">
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
                      {/*
                        ── Revoke, on rows that are in force ─────────────────

                        Checklist item 23, for BPLO and the super admin. Drawn
                        only on Active and Suspended rows — an expired or
                        superseded certificate has already stopped being valid,
                        and the server refuses to revoke it — and only for a
                        reader holding `permit.revoke`. Red, because it is the
                        destructive act on this row (DESIGN.md, Red Means
                        Stop); the dialog behind it is the confirmation.
                      */}
                      {canRevoke && revocable(permit) && (
                        <button
                          type="button"
                          onClick={() => setRevoking(permit)}
                          aria-label={`Revoke ${permit.permit_number}`}
                          className="ml-2 rounded-full border border-s-red px-4 py-1.5 text-xs font-semibold text-s-red hover:bg-s-red hover:text-white"
                        >
                          Revoke
                        </button>
                      )}
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

          {revoking !== null && (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-ink/40 px-4">
              <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="revoke-heading"
                aria-describedby="revoke-consequence"
                className="w-full max-w-md rounded-xl bg-white p-6 shadow-raised"
              >
                <h2 id="revoke-heading" className="text-base font-bold text-ink">
                  Revoke {revoking.permit_number}?
                </h2>
                {/*
                  The certificate and the business by name, then what happens
                  — each consequence once. An officer confirming an enforcement
                  act should not have to trust that they clicked the row they
                  meant, or guess who is told.
                */}
                <p id="revoke-consequence" className="mt-2 text-sm leading-relaxed text-ink-secondary">
                  {revoking.permit_type?.name ?? 'This permit'} for{' '}
                  <span className="font-semibold text-ink">{businessName(revoking.business)}</span> stops
                  being valid today. The owner is notified with your reason, and anyone who scans the
                  permit’s QR code sees it as revoked. This cannot be undone.
                </p>
                <label className="mt-4 block">
                  <span className="text-xs font-bold uppercase tracking-wide text-ink-muted">
                    Reason for revoking
                  </span>
                  <textarea
                    value={revokeReason}
                    onChange={(e) => setRevokeReason(e.target.value)}
                    rows={3}
                    aria-required="true"
                    className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-royal"
                  />
                </label>
                <p className="mt-1 text-xs text-ink-muted">
                  Recorded in the audit log against your account.
                </p>
                {revokeError !== null && (
                  <p role="alert" className="mt-2 text-xs font-medium text-s-red">
                    {revokeError}
                  </p>
                )}
                <div className="mt-5 flex justify-end gap-3">
                  <button
                    type="button"
                    onClick={closeRevoke}
                    className="rounded-full border border-line px-5 py-2 text-sm font-semibold text-ink hover:bg-shell-deep"
                  >
                    Cancel
                  </button>
                  {/* aria-disabled, never disabled (AGENTS.md §6.2). */}
                  <button
                    type="button"
                    aria-disabled={revokeBusy || revokeReason.trim() === '' || undefined}
                    onClick={confirmRevoke}
                    className="rounded-full bg-s-red px-5 py-2 text-sm font-semibold text-white hover:brightness-110 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                  >
                    {revokeBusy ? 'Revoking…' : 'Revoke permit'}
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
                {choice === OTHER_OFFICES
                  ? ' issued by every office but BPLO'
                  : office !== '' && ` issued by ${officeOf(office)}`}
                {retired === 'only' && ' held by retired businesses'}
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
                {retired === 'hide' && ' Retired businesses are hidden.'}
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
      </>
      )}
    </div>
  )
}
