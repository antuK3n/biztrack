import { useId, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import { AlertTriangleIcon, ChevronRightIcon, MailIcon, SearchIcon } from '../../components/icons'
import { Modal } from '../../components/ui/Modal'
import { ErrorState, Skeleton } from '../../components/ui/primitives'
import { ProtoCard, StatusChip } from '../../components/ui/Proto'
import type { ChipTone } from '../../components/ui/Proto'
import { activePortal, portalPath } from '../../lib/api'
import { formatCalendarDate, formatDate, formatDateTime, formatMoney, initialsOf } from '../../lib/format'
import { admin } from '../../lib/resources'
import { BUSINESS_STATUS, genericStatusTone } from '../../lib/status'
import type { OwnerProfile, OwnerProfileEvent } from '../../lib/types'
import { useAsync } from '../../lib/useAsync'
import { STATUS_TONES } from './permitStatusTones'

/*
 * ── One owner, on one screen ───────────────────────────────────────────────
 *
 * [Client, 20 October 2026: "makikita na mga details nya (account information,
 * payment history, status history, etc. — other permits na dapat naview lang
 * kada admin) … make this good HCI, good format, and good spacing, hindi
 * sayang sa mga spaces at easily sa mga admin makita ang mga important
 * infos".]
 *
 * A page rather than a modal: a profile is five sections deep, a modal would
 * scroll inside a scroll, and a page has an address an officer can send or
 * open in a second tab beside the filing they are reviewing.
 *
 * Laid out by what an officer asks first:
 *
 *   - the HEADER answers "who, and are they in good standing" — name, status,
 *     contact, and four counts — with a blacklisting stated in full beneath;
 *   - the MAIN column holds the registers: businesses with every permit
 *     (read-only), then filings, then payments;
 *   - the SIDE column holds the reference facts — account information — and
 *     the status history, which is long and so shows its latest entries first
 *     with the rest one press away.
 *
 * Everything is scoped by the server to the reader's own caseload: a
 * clearance office sees the businesses, filings and payments its office
 * handled, and the owner's other permits read-only (OwnerProfileController).
 */

const PAYMENT_TONES: Record<string, ChipTone> = {
  completed: 'tint-green',
  pending: 'tint-yellow',
  failed: 'tint-red',
  refunded: 'tint-gray',
}

const FILING_TONES: Record<string, ChipTone> = {
  success: 'tint-green',
  danger: 'tint-red',
  attention: 'tint-orange',
  progress: 'tint-purple',
  neutral: 'tint-gray',
}

/*
 * ── The page shows the latest; "View all" opens the rest in a modal ────────
 *
 * [Client, 21 October 2026: "masyadong mahaba yung iba … kapag pinindot ang
 * show all pwede bang modal na lang at sa modal mag scroll".] Each register
 * shows its first few rows — the ones asked about — and "View all" opens the
 * whole list in a modal that scrolls on its own. The page stays one screen
 * of summary; expanding a list in place had pushed everything below it out
 * of reach, and the side column's history alone ran longer than the page.
 */
const HISTORY_SHOWN = 5
const ROWS_SHOWN = 5
const BUSINESSES_SHOWN = 6

type FullView = 'businesses' | 'filings' | 'payments' | 'fees' | 'history' | null

const SEX: Record<string, string> = { M: 'Male', F: 'Female' }

export function OwnerProfilePage() {
  const { ownerId } = useParams()
  const id = Number(ownerId)
  const { data, loading, error, reload } = useAsync(() => admin.ownerProfile(id), [id])
  const listPath = portalPath(activePortal(), '/admin/business-owners')

  return (
    <div>
      <nav aria-label="Breadcrumb" className="mb-3 flex items-center gap-1.5 text-sm text-ink-muted">
        <Link to={listPath} className="font-semibold text-royal hover:underline">
          Business Owners
        </Link>
        <ChevronRightIcon size={14} aria-hidden="true" />
        <span aria-current="page" className="truncate text-ink-secondary">
          {data?.account.name ?? 'Profile'}
        </span>
      </nav>

      {loading && !data ? (
        <ProfileSkeleton />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : data ? (
        <Profile profile={data} />
      ) : null}
    </div>
  )
}

function Profile({ profile }: { profile: OwnerProfile }) {
  const { account } = profile
  const [view, setView] = useState<FullView>(null)
  const close = () => setView(null)
  const payments = profile.payments ?? []
  const fees = profile.fees ?? []

  return (
    <div className="space-y-5">
      {/* ── Who, and in what standing ─────────────────────────────────── */}
      <ProfileHeader profile={profile} />

      <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_21rem]">
        {/* ── The registers ──────────────────────────────────────────── */}
        <div className="min-w-0 space-y-5">
          <Section title="Businesses and permits" count={profile.businesses.length}>
            <BusinessList businesses={profile.businesses.slice(0, BUSINESSES_SHOWN)} />
            <ViewAll
              shown={Math.min(BUSINESSES_SHOWN, profile.businesses.length)}
              total={profile.businesses.length}
              noun="businesses"
              lead="First"
              onClick={() => setView('businesses')}
            />
          </Section>

          <Section title="Filings" count={profile.filings.length}>
            {profile.filings.length === 0 ? (
              <Empty>No filings submitted.</Empty>
            ) : (
              <FilingsTable filings={profile.filings.slice(0, ROWS_SHOWN)} />
            )}
            <ViewAll
              shown={Math.min(ROWS_SHOWN, profile.filings.length)}
              total={profile.filings.length}
              noun="filings"
              onClick={() => setView('filings')}
            />
          </Section>

          {profile.payments !== null ? (
            <Section title="Payment history" count={payments.length}>
              {payments.length === 0 ? (
                <Empty>No payments on these filings.</Empty>
              ) : (
                <PaymentsTable payments={payments.slice(0, ROWS_SHOWN)} />
              )}
              <ViewAll
                shown={Math.min(ROWS_SHOWN, payments.length)}
                total={payments.length}
                noun="payments"
                onClick={() => setView('payments')}
              />
            </Section>
          ) : (
            /*
              A clearance office's view of the money: its own share of each
              bill, as the review sheet shows it, and whether the bill was
              paid — not the owner's payments to the other offices.
            */
            <Section title="Your office's fees" count={fees.length}>
              {fees.length === 0 ? (
                <Empty>No fee for your office on these filings.</Empty>
              ) : (
                <FeesTable fees={fees.slice(0, ROWS_SHOWN)} />
              )}
              <ViewAll
                shown={Math.min(ROWS_SHOWN, fees.length)}
                total={fees.length}
                noun="fees"
                onClick={() => setView('fees')}
              />
            </Section>
          )}
        </div>

        {/* ── Reference facts and the timeline ───────────────────────── */}
        <div className="min-w-0 space-y-5">
          <AccountSection account={account} />
          <HistorySection events={profile.history} onViewAll={() => setView('history')} />
        </div>
      </div>

      {/* ── The full lists, each in a modal that scrolls on its own ────── */}
      <Modal
        open={view === 'businesses'}
        onClose={close}
        title={`Businesses and permits · ${profile.businesses.length}`}
        description={account.name}
        size="xl"
        flush
        fixedHeight
      >
        <BusinessBrowser businesses={profile.businesses} />
      </Modal>
      <Modal
        open={view === 'filings'}
        onClose={close}
        title={`Filings · ${profile.filings.length}`}
        description={account.name}
        size="xl"
        flush
        fixedHeight
      >
        <FilingsBrowser filings={profile.filings} />
      </Modal>
      <Modal
        open={view === 'payments'}
        onClose={close}
        title={`Payment history · ${payments.length}`}
        description={`${account.name} · press a payment to see what it paid for`}
        size="xl"
        flush
        fixedHeight
      >
        <PaymentsBrowser payments={payments} />
      </Modal>
      <Modal
        open={view === 'fees'}
        onClose={close}
        title={`Your office's fees · ${fees.length}`}
        description={account.name}
        size="xl"
        flush
        fixedHeight
      >
        <FeesBrowser fees={fees} />
      </Modal>
      <Modal
        open={view === 'history'}
        onClose={close}
        title="Status history"
        description={account.name}
        size="lg"
        flush
        fixedHeight
      >
        <HistoryBrowser events={profile.history} />
      </Modal>
    </div>
  )
}

/** Name, standing, contact, the Message button and the four counts. */
function ProfileHeader({ profile }: { profile: OwnerProfile }) {
  const { account, summary } = profile
  const blacklisted = account.status === 'blacklisted'
  const hasContact = 'email' in account || 'mobile_number' in account

  return (
    <ProtoCard className="p-5">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex min-w-0 items-center gap-4">
          <span
            aria-hidden="true"
            className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-royal text-lg font-bold text-white"
          >
            {initialsOf(account.name)}
          </span>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
              <h1 className="truncate text-2xl font-bold text-ink">{account.name}</h1>
              <StatusChip tone={blacklisted ? 'tint-red' : 'tint-green'}>{account.status_label}</StatusChip>
            </div>
            {(hasContact || 'created_at' in account) && (
              <p className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-sm text-ink-secondary">
                {'email' in account && <span className="break-all">{account.email ?? 'No email'}</span>}
                {'mobile_number' in account && <span className="tnum">{account.mobile_number ?? 'No mobile'}</span>}
                {'created_at' in account && (
                  <span className="text-ink-muted">Owner since {formatDate(account.created_at)}</span>
                )}
              </p>
            )}
            {/*
              The owner's General enquiry with the reader's own office
              [client, 21 October 2026]. Every office seat in Messages has it;
              the super admin, who holds no conversation with owners, does not
              (can_message). The name rides along for the pane's title, in
              case the owner is past the inbox's first page.
            */}
            {profile.can_message && (
              <Link
                to={`${portalPath(activePortal(), '/messages')}?application=general-${account.id}`}
                state={{ ownerName: account.name }}
                className="mt-2.5 inline-flex items-center gap-1.5 rounded-full bg-royal px-4 py-1.5 text-sm font-semibold text-white hover:bg-royal-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal"
              >
                <MailIcon size={16} aria-hidden="true" />
                Message
                <span className="sr-only"> {account.name}</span>
              </Link>
            )}
          </div>
        </div>

        <dl className="grid shrink-0 grid-cols-2 gap-px overflow-hidden rounded-xl bg-line sm:grid-cols-4">
          <Stat label="Businesses" value={summary.businesses.toLocaleString()} />
          <Stat label="Active permits" value={summary.active_permits.toLocaleString()} />
          <Stat label="Filings" value={summary.filings.toLocaleString()} />
          <Stat
            label="Needs attention"
            value={summary.needs_attention.toLocaleString()}
            warn={summary.needs_attention > 0}
          />
        </dl>
      </div>

      {blacklisted && (
        <div
          role="note"
          className="mt-4 flex gap-3 rounded-lg border border-s-red/40 bg-s-red-tint px-4 py-2.5 text-sm"
        >
          <AlertTriangleIcon size={18} aria-hidden="true" className="mt-0.5 shrink-0 text-s-red" />
          <p className="text-ink">
            <b className="text-s-red">Blacklisted {formatDate(account.blacklisted_at)}</b>
            {account.blacklisted_by && <> by {account.blacklisted_by}</>}
            {account.blacklist_reason && <> — {account.blacklist_reason}</>}. Every business this owner holds is
            suspended while it stands.
          </p>
        </div>
      )}
    </ProtoCard>
  )
}

/** Only the facts this reader was sent (OwnerProfileController::accountFor). */
function AccountSection({ account }: { account: OwnerProfile['account'] }) {
  return (
    <Section title="Account information">
      <dl className="divide-y divide-line px-5">
        <Fact label="Full name">{account.name}</Fact>
        <Fact label="Sex">{account.gender ? (SEX[account.gender] ?? account.gender) : '—'}</Fact>
        {'email' in account && (
          <Fact label="Email">
            <span className="break-all">{account.email ?? '—'}</span>
            {account.email && 'email_verified_at' in account && (
              <span className={`ml-1.5 text-xs ${account.email_verified_at ? 'text-s-green' : 'text-ink-muted'}`}>
                {account.email_verified_at ? '· Confirmed' : '· Not confirmed'}
              </span>
            )}
          </Fact>
        )}
        {'mobile_number' in account && (
          <Fact label="Mobile">
            <span className="tnum">{account.mobile_number ?? '—'}</span>
          </Fact>
        )}
        {'created_at' in account && <Fact label="Registered">{formatDate(account.created_at)}</Fact>}
        {'last_login_at' in account && <Fact label="Last signed in">{formatDateTime(account.last_login_at)}</Fact>}
        {'is_active' in account && <Fact label="Sign-in">{account.is_active ? 'Allowed' : 'Turned off'}</Fact>}
      </dl>
      {!('email' in account) && (
        <p className="border-t border-line px-5 py-2.5 text-xs leading-snug text-ink-muted">
          Reach the owner through Message, or the business's own contact numbers on the filing.
        </p>
      )}
    </Section>
  )
}

/** Businesses as one-line rows that open onto their permits; Expand all when there are several. */
function BusinessList({ businesses }: { businesses: OwnerProfile['businesses'] }) {
  // One business opens itself: there is nothing to choose between.
  const [open, setOpen] = useState<Set<number>>(() => new Set(businesses.length === 1 ? [businesses[0].id] : []))
  const allOpen = businesses.length > 0 && open.size === businesses.length
  const toggle = (id: number) =>
    setOpen((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })

  return (
    <>
      {businesses.length > 1 && (
        <div className="flex justify-end border-b border-line px-5 py-1.5">
          <button
            type="button"
            onClick={() => setOpen(allOpen ? new Set() : new Set(businesses.map((b) => b.id)))}
            className="text-xs font-semibold text-royal hover:underline"
          >
            {allOpen ? 'Collapse all' : 'Expand all'}
          </button>
        </div>
      )}
      <ul className="divide-y divide-line">
        {businesses.map((b) => (
          <BusinessBlock key={b.id} business={b} open={open.has(b.id)} onToggle={() => toggle(b.id)} />
        ))}
      </ul>
    </>
  )
}

function FilingsTable({ filings }: { filings: OwnerProfile['filings'] }) {
  return (
    <Table
      head={['Tracking ID', 'Business', 'Status', 'Filed']}
      rows={filings.map((f) => ({
        key: f.id,
        cells: [
          <span key="c1" className="block">
            <span className="tnum block whitespace-nowrap font-semibold text-ink">{f.tracking_id ?? '—'}</span>
            <span className="block text-xs text-ink-muted">{f.type_label ?? '—'}</span>
          </span>,
          <span key="c2" className="block min-w-[8rem] text-ink">
            {f.business_name ?? '—'}
          </span>,
          f.status_label ? (
            <StatusChip
              key="c3"
              className="whitespace-nowrap"
              tone={FILING_TONES[genericStatusTone(f.status ?? '')] ?? 'tint-gray'}
            >
              {f.status_label}
            </StatusChip>
          ) : (
            '—'
          ),
          <span key="c4" className="block">
            <span className="block whitespace-nowrap">{formatDate(f.submitted_at)}</span>
            {f.decided_at && (
              <span className="block whitespace-nowrap text-xs text-ink-muted">Decided {formatDate(f.decided_at)}</span>
            )}
          </span>,
        ],
      }))}
    />
  )
}

function FeesTable({ fees }: { fees: NonNullable<OwnerProfile['fees']> }) {
  return (
    <Table
      head={['Tracking ID', 'Business', 'Paid', 'Your fee']}
      alignLastRight
      rows={fees.map((f) => ({
        key: f.application_id,
        cells: [
          <span key="f1" className="tnum whitespace-nowrap font-semibold text-ink">
            {f.tracking_id ?? '—'}
          </span>,
          <span key="f2" className="block min-w-[8rem] text-ink">
            {f.business_name ?? '—'}
          </span>,
          f.paid ? (
            <span key="f3" className="block">
              <StatusChip tone="tint-green">Paid</StatusChip>
              <span className="mt-0.5 block whitespace-nowrap text-xs text-ink-muted">{formatDate(f.paid_at)}</span>
            </span>
          ) : (
            <StatusChip key="f3" tone="tint-yellow">
              Not yet paid
            </StatusChip>
          ),
          <span key="f4" className="tnum whitespace-nowrap font-semibold text-ink">
            {formatMoney(f.amount)}
          </span>,
        ],
      }))}
    />
  )
}

/* ── Businesses, each with its permits ──────────────────────────────────── */

function BusinessBlock({
  business,
  open,
  onToggle,
}: {
  business: OwnerProfile['businesses'][number]
  open: boolean
  onToggle: () => void
}) {
  const status = BUSINESS_STATUS[business.status]
  const panelId = useId()
  /*
   * What the closed row says about the permits: how many of the live ones
   * stand in each state ("4 Active · 2 Expired"). Superseded certificates are
   * history, not holdings, so they are left out of the count and shown greyed
   * inside the table.
   */
  const counts = new Map<string, number>()
  for (const p of business.permits) {
    if (p.status === 'superseded') continue
    counts.set(p.status_label, (counts.get(p.status_label) ?? 0) + 1)
  }
  const summary = [...counts].map(([label, n]) => `${n} ${label}`).join(' · ')

  return (
    <li>
      <h3>
        <button
          type="button"
          onClick={onToggle}
          aria-expanded={open}
          aria-controls={panelId}
          className="flex w-full items-center gap-3 px-5 py-3 text-left transition-colors hover:bg-royal-tint/40 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-royal"
        >
          <ChevronRightIcon
            size={16}
            aria-hidden="true"
            className={`shrink-0 text-ink-muted transition-transform ${open ? 'rotate-90' : ''}`}
          />
          <span className="min-w-0 flex-1">
            <span className="block truncate font-bold text-ink">{business.name}</span>
            <span className="mt-0.5 flex flex-wrap gap-x-2 text-xs text-ink-muted">
              <span>
                BAN <b className="tnum font-semibold text-ink-secondary">{business.ban ?? '—'}</b>
              </span>
              <span className="before:mr-2 before:content-['·']">{summary || 'No permit issued yet'}</span>
            </span>
          </span>
          <StatusChip tone={status?.tone ?? 'tint-gray'} className="shrink-0">
            {status?.label ?? business.status_label}
          </StatusChip>
        </button>
      </h3>

      <div id={panelId} hidden={!open} className="px-5 pb-4">
        {business.address && <p className="-mt-1 mb-2.5 pl-7 text-xs text-ink-muted">{business.address}</p>}
        {business.permits.length === 0 ? (
          <p className="pl-7 text-sm text-ink-muted">No permit issued yet.</p>
        ) : (
          <div className="overflow-x-auto rounded-lg border border-line">
            <table className="w-full min-w-[34rem] text-left text-sm">
              <thead>
                <tr className="bg-canvas/60 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
                  <th scope="col" className="px-3.5 py-2">
                    Permit
                  </th>
                  <th scope="col" className="px-3.5 py-2">
                    Office
                  </th>
                  <th scope="col" className="px-3.5 py-2">
                    Status
                  </th>
                  <th scope="col" className="px-3.5 py-2">
                    Valid until
                  </th>
                </tr>
              </thead>
              <tbody>
                {business.permits.map((p) => {
                  const past = p.status === 'superseded'
                  return (
                    <tr key={p.id} className={`border-t border-line ${past ? 'text-ink-muted' : ''}`}>
                      <td className="px-3.5 py-2">
                        <span className={`block font-semibold ${past ? '' : 'text-ink'}`}>
                          {p.type_name ?? 'Permit'}
                        </span>
                        <span className="tnum block text-xs text-ink-muted">{p.permit_number}</span>
                      </td>
                      <td className="px-3.5 py-2 text-ink-secondary">{p.office_code ?? '—'}</td>
                      <td className="px-3.5 py-2">
                        <StatusChip tone={STATUS_TONES[p.status] ?? 'tint-gray'}>{p.status_label}</StatusChip>
                      </td>
                      <td className="tnum whitespace-nowrap px-3.5 py-2">{formatCalendarDate(p.valid_until)}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </li>
  )
}

/* ── Status history ─────────────────────────────────────────────────────── */

/*
 * ── Whose status history, said on every entry ─────────────────────────────
 *
 * [Client, 20 October 2026: "paki indicate, lahat paki indicate nang maayos
 * para di confusing".] Every entry names what it is about before what
 * happened to it: the office's code, the certificate and its number, the
 * business, the move from one status to the next, the reason, and who — with
 * their office.
 *
 * A clearance office is sent its own certificate's history only, so its list
 * needs no filter. BPLO and the super admin are sent everything, and choose
 * between all of it, the account and businesses, or one office's permit.
 */

/** The offices in the order the City lists them, each with its certificate. */
const OFFICE_ORDER = ['BPLO', 'CPDO', 'CHO', 'BFP', 'OBO', 'CENRO']

type HistoryFilter = 'all' | 'account' | string

function eventDot(e: OwnerProfileEvent): string {
  switch (e.to) {
    case 'blacklisted':
    case 'revoked':
    case 'rejected':
      return 'bg-s-red'
    case 'suspended':
      return 'bg-s-purple'
    case 'expired':
    case 'flagged':
      return 'bg-s-yellow'
    case 'active':
      return 'bg-s-green'
    default:
      return 'bg-line'
  }
}

/** The pill at the head of each entry: the office, or Account / Business. */
function subjectPill(e: OwnerProfileEvent): string {
  if (e.scope === 'account') return 'Account'
  if (e.scope === 'business') return 'Business'
  return e.office_code ?? 'Permit'
}

function HistorySection({ events, onViewAll }: { events: OwnerProfileEvent[]; onViewAll: () => void }) {
  const offices = OFFICE_ORDER.filter((code) => events.some((e) => e.scope === 'permit' && e.office_code === code))
  const onlyOwn = offices.length === 1 && !events.some((e) => e.scope !== 'permit')

  return (
    <Section title="Status history" count={events.length}>
      {onlyOwn && (
        <p className="border-b border-line px-5 py-2 text-xs text-ink-muted">
          Your office's{' '}
          <b className="font-semibold text-ink-secondary">
            {events.find((e) => e.office_code === offices[0])?.permit_type}
          </b>{' '}
          only.
        </p>
      )}
      {events.length === 0 ? (
        <Empty>Nothing recorded yet.</Empty>
      ) : (
        <HistoryList events={events.slice(0, HISTORY_SHOWN)} />
      )}
      <ViewAll
        shown={Math.min(HISTORY_SHOWN, events.length)}
        total={events.length}
        noun="entries"
        onClick={onViewAll}
      />
    </Section>
  )
}

/**
 * The whole history, in the modal: the Show filter (for BPLO and the super
 * admin, who are sent every office's), then every entry in full.
 */
function HistoryBrowser({ events }: { events: OwnerProfileEvent[] }) {
  const [filter, setFilter] = useState<HistoryFilter>('all')
  const [query, setQuery] = useState('')
  const offices = OFFICE_ORDER.filter((code) => events.some((e) => e.scope === 'permit' && e.office_code === code))
  const hasAccount = events.some((e) => e.scope !== 'permit')
  const certificateOf = (code: string) => events.find((e) => e.office_code === code)?.permit_type ?? code
  const choosable = hasAccount || offices.length > 1
  const filtered = events.filter(
    (e) =>
      (filter === 'all'
        ? true
        : filter === 'account'
          ? e.scope !== 'permit'
          : e.scope === 'permit' && e.office_code === filter) &&
      matches(query, [
        e.label,
        e.permit_type,
        e.permit_number,
        e.business_name,
        e.reason,
        e.by,
        e.by_office,
        e.from_label,
        e.to_label,
      ]),
  )

  return (
    <div>
      <Toolbar
        query={query}
        onQuery={setQuery}
        placeholder="Permit no., business, reason or person"
        searchLabel="Search the status history"
        shown={filtered.length}
        total={events.length}
        filter={
          choosable
            ? {
                label: 'Show',
                value: filter,
                onChange: setFilter,
                options: [
                  { value: 'all', label: 'All offices' },
                  ...(hasAccount ? [{ value: 'account', label: 'Account and businesses' }] : []),
                  ...offices.map((code) => ({ value: code, label: `${code} — ${certificateOf(code)}` })),
                ],
              }
            : undefined
        }
      />
      {filtered.length === 0 ? <NoMatch /> : <HistoryList events={filtered} full />}
    </div>
  )
}

/*
 * ── Search and filter, in each "View all" modal ───────────────────────────
 *
 * [Client, 21 October 2026: "add sa modal yung search bar o filter o both kung
 * ano mas maganda".] Both, because they answer different questions: the search
 * finds a particular thing by what the reader already knows of it — a tracking
 * ID, a reference, a permit number, a business name — and the filter narrows
 * by the state an officer is looking for: what needs attention, what is not
 * yet paid. Each modal filters on the field its list is about, with the
 * choices read off the data so none of them comes up empty.
 *
 * The bar sits at the top of the modal's own scroll and stays there, and says
 * how many of how many it is showing, so a narrowed list never reads as the
 * whole of it.
 */
function matches(query: string, fields: (string | null | undefined)[]): boolean {
  const needle = query.trim().toLowerCase()
  if (!needle) return true
  return fields.some((field) => field?.toLowerCase().includes(needle))
}

interface ToolbarFilter {
  label: string
  value: string
  onChange: (value: string) => void
  options: { value: string; label: string }[]
}

function Toolbar({
  query,
  onQuery,
  placeholder,
  searchLabel,
  shown,
  total,
  filter,
}: {
  query: string
  onQuery: (q: string) => void
  placeholder: string
  searchLabel: string
  shown: number
  total: number
  filter?: ToolbarFilter
}) {
  const filterId = useId()

  return (
    <div className="sticky top-0 z-10 border-b border-line bg-surface px-5 py-3">
      <div className="flex flex-col gap-2.5 sm:flex-row sm:items-center">
        <div className="relative min-w-0 flex-1">
          <SearchIcon
            size={16}
            aria-hidden="true"
            className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-muted"
          />
          <input
            type="search"
            value={query}
            onChange={(e) => onQuery(e.target.value)}
            placeholder={placeholder}
            aria-label={searchLabel}
            className="w-full rounded-lg border border-input-border bg-input py-2 pl-9 pr-3 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
          />
        </div>
        {filter && (
          <div className="flex shrink-0 items-center gap-2">
            <label htmlFor={filterId} className="text-sm font-semibold text-ink-secondary">
              {filter.label}
            </label>
            <select
              id={filterId}
              value={filter.value}
              onChange={(e) => filter.onChange(e.target.value)}
              className="min-w-0 rounded-lg border border-input-border bg-input px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal sm:max-w-[16rem]"
            >
              {filter.options.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
          </div>
        )}
      </div>
      <p className="mt-1.5 text-xs text-ink-muted" aria-live="polite">
        {shown === total
          ? `${total.toLocaleString()} in all`
          : `Showing ${shown.toLocaleString()} of ${total.toLocaleString()}`}
      </p>
    </div>
  )
}

function NoMatch() {
  return <p className="px-5 py-8 text-center text-sm text-ink-muted">Nothing matches. Try another search or filter.</p>
}

/** The distinct labels a list holds, in first-seen order, as filter choices. */
function choicesOf(labels: (string | null | undefined)[]): { value: string; label: string }[] {
  return [...new Set(labels.filter((l): l is string => Boolean(l)))].map((l) => ({ value: l, label: l }))
}

const ATTENTION = ['expired', 'suspended', 'revoked', 'rejected']

function BusinessBrowser({ businesses }: { businesses: OwnerProfile['businesses'] }) {
  const [query, setQuery] = useState('')
  const [filter, setFilter] = useState('all')
  const live = (b: OwnerProfile['businesses'][number]) => b.permits.filter((p) => p.status !== 'superseded')
  const filtered = businesses.filter(
    (b) =>
      (filter === 'all' ||
        (filter === 'attention' && live(b).some((p) => ATTENTION.includes(p.status))) ||
        (filter === 'clear' && live(b).length > 0 && live(b).every((p) => p.status === 'active')) ||
        (filter === 'none' && live(b).length === 0)) &&
      matches(query, [b.name, b.ban, b.address, ...b.permits.map((p) => p.permit_number)]),
  )

  return (
    <div>
      <Toolbar
        query={query}
        onQuery={setQuery}
        placeholder="Business name, BAN or permit no."
        searchLabel="Search the businesses"
        shown={filtered.length}
        total={businesses.length}
        filter={{
          label: 'Show',
          value: filter,
          onChange: setFilter,
          options: [
            { value: 'all', label: 'All businesses' },
            { value: 'attention', label: 'Needs attention' },
            { value: 'clear', label: 'All permits active' },
            { value: 'none', label: 'No permit yet' },
          ],
        }}
      />
      {/* Keyed on the narrowing, so Expand all starts over for the list now shown. */}
      {filtered.length === 0 ? <NoMatch /> : <BusinessList key={`${filter}|${query}`} businesses={filtered} />}
    </div>
  )
}

function FilingsBrowser({ filings }: { filings: OwnerProfile['filings'] }) {
  const [query, setQuery] = useState('')
  const [filter, setFilter] = useState('all')
  const filtered = filings.filter(
    (f) =>
      (filter === 'all' || f.status_label === filter) && matches(query, [f.tracking_id, f.business_name, f.type_label]),
  )

  return (
    <div>
      <Toolbar
        query={query}
        onQuery={setQuery}
        placeholder="Tracking ID or business"
        searchLabel="Search the filings"
        shown={filtered.length}
        total={filings.length}
        filter={{
          label: 'Status',
          value: filter,
          onChange: setFilter,
          options: [{ value: 'all', label: 'All statuses' }, ...choicesOf(filings.map((f) => f.status_label))],
        }}
      />
      {filtered.length === 0 ? <NoMatch /> : <FilingsTable filings={filtered} />}
    </div>
  )
}

function PaymentsBrowser({ payments }: { payments: ProfilePayment[] }) {
  const [query, setQuery] = useState('')
  const [filter, setFilter] = useState('all')
  const filtered = payments.filter(
    (p) =>
      (filter === 'all' || p.status_label === filter) &&
      matches(query, [p.reference_number, p.tracking_id, p.business_name, p.method_label]),
  )

  return (
    <div>
      <Toolbar
        query={query}
        onQuery={setQuery}
        placeholder="Reference, tracking ID or business"
        searchLabel="Search the payments"
        shown={filtered.length}
        total={payments.length}
        filter={{
          label: 'Status',
          value: filter,
          onChange: setFilter,
          options: [{ value: 'all', label: 'All statuses' }, ...choicesOf(payments.map((p) => p.status_label))],
        }}
      />
      {filtered.length === 0 ? <NoMatch /> : <PaymentsTable payments={filtered} />}
    </div>
  )
}

function FeesBrowser({ fees }: { fees: NonNullable<OwnerProfile['fees']> }) {
  const [query, setQuery] = useState('')
  const [filter, setFilter] = useState('all')
  const filtered = fees.filter(
    (f) => (filter === 'all' || (filter === 'paid') === f.paid) && matches(query, [f.tracking_id, f.business_name]),
  )

  return (
    <div>
      <Toolbar
        query={query}
        onQuery={setQuery}
        placeholder="Tracking ID or business"
        searchLabel="Search your office's fees"
        shown={filtered.length}
        total={fees.length}
        filter={{
          label: 'Show',
          value: filter,
          onChange: setFilter,
          options: [
            { value: 'all', label: 'All fees' },
            { value: 'paid', label: 'Paid' },
            { value: 'unpaid', label: 'Not yet paid' },
          ],
        }}
      />
      {filtered.length === 0 ? <NoMatch /> : <FeesTable fees={filtered} />}
    </div>
  )
}

/*
 * One entry in three short lines: what happened, what it happened to, and
 * when and by whom — with the reason under it. A generic "Status changed"
 * is replaced by the move itself ("Active → Suspended"), which is what it
 * meant; a worded event ("Suspended — the business was sanctioned") keeps its
 * words. On the page the subject and reason are cut to one line each; the
 * modal prints them in full.
 */
function HistoryList({ events, full = false }: { events: OwnerProfileEvent[]; full?: boolean }) {
  return (
    <ol className="divide-y divide-line px-5">
      {events.map((e, i) => {
        const generic = e.label === 'Status changed' || e.label === 'Business status changed'
        const title =
          generic && e.to_label
            ? `${e.scope === 'business' ? 'Business ' : ''}${e.from_label ? `${e.from_label} → ` : ''}${e.to_label}`
            : e.label
        const about =
          e.scope === 'permit'
            ? [`${e.permit_type} · ${e.permit_number}`, e.business_name].filter(Boolean).join(' · ')
            : e.scope === 'business'
              ? (e.business_name ?? 'Business')
              : "The owner's account"
        const who = e.by ? `${e.by}${e.by_office ? ` (${e.by_office})` : ''}` : e.by_office

        return (
          <li key={`${e.scope}-${e.id}-${i}`} className="flex gap-3 py-2.5">
            <span aria-hidden="true" className={`mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full ${eventDot(e)}`} />
            <div className="min-w-0 flex-1">
              <p className="text-sm font-semibold leading-snug text-ink">{title}</p>
              <p className="mt-0.5 flex min-w-0 items-center gap-1.5 text-xs text-ink-secondary" title={about}>
                <span className="shrink-0 rounded bg-royal-tint px-1.5 py-px text-[10px] font-bold uppercase tracking-wide text-royal">
                  {subjectPill(e)}
                </span>
                <span className={full ? 'min-w-0' : 'min-w-0 truncate'}>{about}</span>
              </p>
              {e.reason && (
                <p
                  className={`mt-0.5 text-xs italic leading-snug text-ink-secondary ${full ? '' : 'line-clamp-1'}`}
                  title={e.reason}
                >
                  “{e.reason}”
                </p>
              )}
              <p className="mt-0.5 text-[11px] text-ink-muted">
                {formatDateTime(e.at)}
                {who ? ` · ${who}` : e.label !== 'Account created' ? ' · Automatic' : ''}
              </p>
            </div>
          </li>
        )
      })}
    </ol>
  )
}

/* ── Payments, each opening onto what it paid for ──────────────────────────
 *
 * [Client, 21 October 2026: "di ba may mga breakdown pa yon kung ano ano ang
 * babayaran bago ang total amount? i want once click lalabas yon, tas clinick
 * uli yon mawawala dropdown".] A row opens its Tax Order of Payment beneath
 * it; pressing it again closes it, and opening another closes the first, so
 * one breakdown is read at a time.
 *
 * The lines are grouped by the office each is charged for, with a subtotal,
 * because "which office is this money for" is the question a BPLO clerk asks
 * of a bill that pays six offices at once.
 *
 * The toggle is a real button with `aria-expanded` in the first cell; the
 * rest of the row forwards a press to it, so a mouse can hit anywhere and a
 * keyboard has one stop per payment.
 */
type ProfilePayment = NonNullable<OwnerProfile['payments']>[number]

function PaymentsTable({ payments }: { payments: ProfilePayment[] }) {
  const [openId, setOpenId] = useState<number | null>(null)

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead>
          <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
            <th scope="col" className="whitespace-nowrap px-4 py-2.5">
              Reference
            </th>
            <th scope="col" className="whitespace-nowrap px-4 py-2.5">
              For
            </th>
            <th scope="col" className="whitespace-nowrap px-4 py-2.5">
              Method
            </th>
            <th scope="col" className="whitespace-nowrap px-4 py-2.5">
              Status
            </th>
            <th scope="col" className="whitespace-nowrap px-4 py-2.5 text-right">
              Amount
            </th>
          </tr>
        </thead>
        {payments.map((p) => (
          <PaymentRows
            key={p.id}
            payment={p}
            open={openId === p.id}
            onToggle={() => setOpenId((current) => (current === p.id ? null : p.id))}
          />
        ))}
      </table>
    </div>
  )
}

function PaymentRows({ payment: p, open, onToggle }: { payment: ProfilePayment; open: boolean; onToggle: () => void }) {
  const panelId = useId()
  const hasBreakdown = p.breakdown.length > 0

  return (
    <tbody className="border-t border-line">
      <tr
        onClick={(e) => {
          if (!hasBreakdown || (e.target as HTMLElement).closest('button')) return
          onToggle()
        }}
        className={`text-ink-secondary ${hasBreakdown ? 'cursor-pointer hover:bg-royal-tint/40' : ''} ${open ? 'bg-royal-tint/30' : ''}`}
      >
        <td className="px-4 py-2.5 align-middle">
          {hasBreakdown ? (
            <button
              type="button"
              onClick={onToggle}
              aria-expanded={open}
              aria-controls={panelId}
              aria-label={`${p.reference_number ?? 'Payment'}: ${open ? 'hide' : 'show'} what it paid for`}
              className="flex items-start gap-1.5 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal"
            >
              <ChevronRightIcon
                size={14}
                aria-hidden="true"
                className={`mt-0.5 shrink-0 text-ink-muted transition-transform ${open ? 'rotate-90' : ''}`}
              />
              <span>
                <span className="tnum block whitespace-nowrap font-semibold text-ink">{p.reference_number ?? '—'}</span>
                <span className="block whitespace-nowrap text-xs text-ink-muted">
                  {formatDate(p.paid_at ?? p.created_at)}
                </span>
              </span>
            </button>
          ) : (
            <span className="block pl-5">
              <span className="tnum block whitespace-nowrap font-semibold text-ink">{p.reference_number ?? '—'}</span>
              <span className="block whitespace-nowrap text-xs text-ink-muted">
                {formatDate(p.paid_at ?? p.created_at)}
              </span>
            </span>
          )}
        </td>
        <td className="px-4 py-2.5 align-middle">
          <span className="block min-w-[8rem]">
            <span className="block text-ink">{p.business_name ?? '—'}</span>
            <span className="tnum block text-xs text-ink-muted">{p.tracking_id ?? ''}</span>
          </span>
        </td>
        <td className="px-4 py-2.5 align-middle">{p.method_label ?? '—'}</td>
        <td className="px-4 py-2.5 align-middle">
          <StatusChip tone={PAYMENT_TONES[p.status ?? ''] ?? 'tint-gray'}>{p.status_label}</StatusChip>
        </td>
        <td className="tnum whitespace-nowrap px-4 py-2.5 text-right align-middle font-semibold text-ink">
          {formatMoney(p.amount)}
        </td>
      </tr>
      {hasBreakdown && (
        <tr id={panelId} hidden={!open}>
          <td colSpan={5} className="px-4 pb-4 pt-1">
            <Breakdown payment={p} />
          </td>
        </tr>
      )}
    </tbody>
  )
}

/** The Tax Order of Payment, grouped by office, with each office's subtotal and the total. */
function Breakdown({ payment: p }: { payment: ProfilePayment }) {
  const groups = new Map<string, { label: string; amount: number }[]>()
  for (const line of p.breakdown) {
    const office = line.office ?? 'Other'
    groups.set(office, [...(groups.get(office) ?? []), line])
  }
  const ordered = [...groups.entries()].sort(
    ([a], [b]) =>
      (OFFICE_ORDER.indexOf(a) === -1 ? 99 : OFFICE_ORDER.indexOf(a)) -
      (OFFICE_ORDER.indexOf(b) === -1 ? 99 : OFFICE_ORDER.indexOf(b)),
  )
  const total = p.assessed_total ?? p.breakdown.reduce((sum, line) => sum + line.amount, 0)
  const partial = Math.abs(total - p.amount) >= 0.005

  return (
    <div className="rounded-lg border border-line bg-canvas/40 px-4 py-3">
      <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">What this paid for</p>
      <div className="space-y-3">
        {ordered.map(([office, lines]) => (
          <div key={office}>
            <div className="flex items-baseline justify-between gap-4 border-b border-line pb-1">
              <span className="rounded bg-royal-tint px-1.5 py-px text-[10px] font-bold uppercase tracking-wide text-royal">
                {office}
              </span>
              <span className="tnum text-xs font-semibold text-ink-secondary">
                {formatMoney(lines.reduce((sum, line) => sum + line.amount, 0))}
              </span>
            </div>
            <ul className="mt-1 space-y-0.5">
              {lines.map((line, i) => (
                <li key={i} className="flex items-baseline justify-between gap-4 text-xs text-ink-secondary">
                  <span className="min-w-0">{line.label}</span>
                  <span className="tnum shrink-0">{formatMoney(line.amount)}</span>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
      <div className="mt-3 flex items-baseline justify-between gap-4 border-t-2 border-ink/20 pt-2 text-sm">
        <span className="font-semibold text-ink">Total assessed</span>
        <span className="tnum font-bold text-ink">{formatMoney(total)}</span>
      </div>
      {/* Only when they differ — a re-bill or a part payment — and then both are said. */}
      {partial && (
        <div className="mt-1 flex items-baseline justify-between gap-4 text-xs text-ink-secondary">
          <span>Paid in this payment</span>
          <span className="tnum font-semibold">{formatMoney(p.amount)}</span>
        </div>
      )}
    </div>
  )
}

/* ── Small pieces ───────────────────────────────────────────────────────── */

function Section({
  title,
  count,
  aside,
  children,
}: {
  title: string
  count?: number
  aside?: ReactNode
  children: ReactNode
}) {
  return (
    <ProtoCard className="overflow-hidden">
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-line px-5 py-3.5">
        <h2 className="text-base font-bold text-ink">
          {title}
          {count !== undefined && <span className="tnum ml-2 text-sm font-semibold text-ink-muted">{count}</span>}
        </h2>
        {aside}
      </div>
      {children}
    </ProtoCard>
  )
}

/** `warn`: amber, not red — a lapsed or held permit is a thing to look at, not an error. */
function Stat({ label, value, warn = false }: { label: string; value: string; warn?: boolean }) {
  return (
    <div className="bg-canvas/60 px-4 py-2.5 lg:min-w-[7.5rem]">
      <dt className="text-[11px] font-semibold uppercase tracking-wider text-ink-muted">{label}</dt>
      <dd className={`tnum mt-0.5 text-lg font-bold ${warn ? 'text-amber-800' : 'text-ink'}`}>{value}</dd>
    </div>
  )
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="grid grid-cols-[7.5rem_minmax(0,1fr)] gap-3 py-2.5 text-sm">
      <dt className="text-ink-muted">{label}</dt>
      <dd className="font-medium text-ink">{children}</dd>
    </div>
  )
}

/**
 * "Latest 5 of 18 · View all" under a list cut to its first rows; opens the
 * whole list in a modal. Short enough for the side column on one line; the
 * button's accessible name carries the count and the noun. Nothing when it
 * all fits.
 */
function ViewAll({
  shown,
  total,
  noun,
  onClick,
  lead = 'Latest',
}: {
  shown: number
  total: number
  noun: string
  onClick: () => void
  /** "First" where the list is not newest-first (businesses, by name). */
  lead?: string
}) {
  if (total <= shown) return null
  return (
    <div className="flex items-center justify-between gap-3 border-t border-line px-5 py-2.5">
      <span className="text-xs text-ink-muted">
        {lead} {shown.toLocaleString()} of {total.toLocaleString()}
      </span>
      <button
        type="button"
        onClick={onClick}
        aria-label={`View all ${total.toLocaleString()} ${noun}`}
        className="whitespace-nowrap text-sm font-semibold text-royal hover:underline"
      >
        View all
      </button>
    </div>
  )
}

function Empty({ children }: { children: ReactNode }) {
  return <p className="px-5 py-4 text-sm text-ink-muted">{children}</p>
}

function Table({
  head,
  rows,
  alignLastRight = false,
}: {
  head: string[]
  rows: { key: number; cells: ReactNode[] }[]
  alignLastRight?: boolean
}) {
  const right = (i: number) => (alignLastRight && i === head.length - 1 ? 'text-right' : '')
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead>
          <tr className="bg-canvas/50 text-[11px] font-semibold uppercase tracking-wider text-ink-muted">
            {head.map((h, i) => (
              <th key={h} scope="col" className={`whitespace-nowrap px-4 py-2.5 ${right(i)}`}>
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.key} className="border-t border-line text-ink-secondary">
              {r.cells.map((c, i) => (
                <td key={i} className={`px-4 py-2.5 align-middle ${right(i)}`}>
                  {c}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function ProfileSkeleton() {
  return (
    <div className="space-y-5" aria-busy="true" aria-label="Loading the owner's profile">
      <Skeleton className="h-28 w-full rounded-2xl" />
      <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <Skeleton className="h-96 w-full rounded-2xl" />
        <Skeleton className="h-72 w-full rounded-2xl" />
      </div>
    </div>
  )
}
