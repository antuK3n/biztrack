import { useId, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import { AlertTriangleIcon, ChevronRightIcon } from '../../components/icons'
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

/** Status history entries shown before "Show all". */
const HISTORY_SHOWN = 8

/** Filings and payments shown before "Show all": the latest are what is asked about. */
const ROWS_SHOWN = 5

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
  const { account, summary } = profile
  const blacklisted = account.status === 'blacklisted'
  /*
   * Businesses start closed, one line each, so an owner with thirteen of them
   * reads as a list rather than thirteen tables. One business opens itself:
   * there is nothing to choose between.
   */
  const [open, setOpen] = useState<Set<number>>(
    () => new Set(profile.businesses.length === 1 ? [profile.businesses[0].id] : []),
  )
  const allOpen = profile.businesses.length > 0 && open.size === profile.businesses.length
  const toggle = (id: number) =>
    setOpen((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  const [allFilings, setAllFilings] = useState(false)
  const [allPayments, setAllPayments] = useState(false)
  const filings = allFilings ? profile.filings : profile.filings.slice(0, ROWS_SHOWN)
  const payments = allPayments ? profile.payments : profile.payments.slice(0, ROWS_SHOWN)

  return (
    <div className="space-y-5">
      {/* ── Who, and in what standing ─────────────────────────────────── */}
      <ProtoCard className="p-5 sm:p-6">
        <div className="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex min-w-0 items-center gap-4">
            <span
              aria-hidden="true"
              className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-royal text-lg font-bold text-white"
            >
              {initialsOf(account.name)}
            </span>
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2.5">
                <h1 className="truncate text-2xl font-bold text-ink">{account.name}</h1>
                <StatusChip tone={blacklisted ? 'tint-red' : 'tint-green'}>{account.status_label}</StatusChip>
              </div>
              <p className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-sm text-ink-secondary">
                <span className="break-all">{account.email ?? 'No email'}</span>
                <span className="tnum">{account.mobile_number ?? 'No mobile number'}</span>
              </p>
              <p className="mt-0.5 text-xs text-ink-muted">Owner since {formatDate(account.created_at)}</p>
            </div>
          </div>

          <dl className="grid shrink-0 grid-cols-2 gap-px overflow-hidden rounded-xl bg-line sm:grid-cols-4">
            <Stat label="Businesses" value={summary.businesses.toLocaleString()} />
            <Stat label="Active permits" value={summary.active_permits.toLocaleString()} />
            <Stat label="Filings" value={summary.filings.toLocaleString()} />
            <Stat label="Total paid" value={formatMoney(summary.total_paid)} />
          </dl>
        </div>

        {blacklisted && (
          <div
            role="note"
            className="mt-5 flex gap-3 rounded-lg border border-s-red/40 bg-s-red-tint px-4 py-3 text-sm"
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

      <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        {/* ── The registers ──────────────────────────────────────────── */}
        <div className="min-w-0 space-y-5">
          <Section
            title="Businesses and permits"
            count={profile.businesses.length}
            aside={
              profile.businesses.length > 1 ? (
                <button
                  type="button"
                  onClick={() => setOpen(allOpen ? new Set() : new Set(profile.businesses.map((b) => b.id)))}
                  className="text-sm font-semibold text-royal hover:underline"
                >
                  {allOpen ? 'Collapse all' : 'Expand all'}
                </button>
              ) : null
            }
          >
            <ul className="divide-y divide-line">
              {profile.businesses.map((b) => (
                <BusinessBlock key={b.id} business={b} open={open.has(b.id)} onToggle={() => toggle(b.id)} />
              ))}
            </ul>
          </Section>

          <Section title="Filings" count={profile.filings.length}>
            {profile.filings.length === 0 ? (
              <Empty>No filings submitted.</Empty>
            ) : (
              <Table
                head={['Tracking ID', 'Business', 'Status', 'Filed']}
                rows={filings.map((f) => ({
                  key: f.id,
                  cells: [
                    <span key="c1" className="block">
                      <span className="tnum block whitespace-nowrap font-semibold text-ink">
                        {f.tracking_id ?? '—'}
                      </span>
                      <span className="block text-xs text-ink-muted">{f.type_label ?? '—'}</span>
                    </span>,
                    <span key="c2" className="block min-w-[8rem] text-ink">
                      {f.business_name ?? '—'}
                    </span>,
                    f.status_label ? (
                      <StatusChip key="c3" className="whitespace-nowrap" tone={FILING_TONES[genericStatusTone(f.status ?? '')] ?? 'tint-gray'}>
                        {f.status_label}
                      </StatusChip>
                    ) : (
                      '—'
                    ),
                    <span key="c4" className="block">
                      <span className="block whitespace-nowrap">{formatDate(f.submitted_at)}</span>
                      {f.decided_at && (
                        <span className="block whitespace-nowrap text-xs text-ink-muted">
                          Decided {formatDate(f.decided_at)}
                        </span>
                      )}
                    </span>,
                  ],
                }))}
              />
            )}
            <ShowAll
              total={profile.filings.length}
              all={allFilings}
              onToggle={() => setAllFilings((a) => !a)}
              noun="filings"
            />
          </Section>

          <Section
            title="Payment history"
            count={profile.payments.length}
            aside={
              profile.payments.length > 0 ? (
                <span className="text-sm text-ink-secondary">
                  Paid in all <b className="tnum text-ink">{formatMoney(summary.total_paid)}</b>
                </span>
              ) : null
            }
          >
            {profile.payments.length === 0 ? (
              <Empty>No payments on the filings you can see.</Empty>
            ) : (
              <Table
                head={['Reference', 'For', 'Method', 'Status', 'Amount']}
                alignLastRight
                rows={payments.map((p) => ({
                  key: p.id,
                  cells: [
                    <span key="c6" className="block">
                      <span className="tnum block whitespace-nowrap font-semibold text-ink">
                        {p.reference_number ?? '—'}
                      </span>
                      <span className="block whitespace-nowrap text-xs text-ink-muted">
                        {formatDate(p.paid_at ?? p.created_at)}
                      </span>
                    </span>,
                    <span key="c7" className="block min-w-[8rem]">
                      <span className="block text-ink">{p.business_name ?? '—'}</span>
                      <span className="tnum block text-xs text-ink-muted">{p.tracking_id ?? ''}</span>
                    </span>,
                    p.method_label ?? '—',
                    <StatusChip key="c8" tone={PAYMENT_TONES[p.status ?? ''] ?? 'tint-gray'}>
                      {p.status_label}
                    </StatusChip>,
                    <span key="c10" className="tnum whitespace-nowrap font-semibold text-ink">
                      {formatMoney(p.amount)}
                    </span>,
                  ],
                }))}
              />
            )}
            <ShowAll
              total={profile.payments.length}
              all={allPayments}
              onToggle={() => setAllPayments((a) => !a)}
              noun="payments"
            />
          </Section>
        </div>

        {/* ── Reference facts and the timeline ───────────────────────── */}
        <div className="min-w-0 space-y-5">
          <Section title="Account information">
            <dl className="divide-y divide-line px-5">
              <Fact label="Full name">{account.name}</Fact>
              <Fact label="Sex">{account.gender ? (SEX[account.gender] ?? account.gender) : '—'}</Fact>
              <Fact label="Email">
                <span className="break-all">{account.email ?? '—'}</span>
                {account.email && (
                  <span
                    className={`mt-0.5 block text-xs ${account.email_verified_at ? 'text-s-green' : 'text-ink-muted'}`}
                  >
                    {account.email_verified_at ? 'Confirmed' : 'Not confirmed'}
                  </span>
                )}
              </Fact>
              <Fact label="Mobile">
                <span className="tnum">{account.mobile_number ?? '—'}</span>
              </Fact>
              <Fact label="Home address">{account.home_address ?? '—'}</Fact>
              <Fact label="Account created">{formatDate(account.created_at)}</Fact>
              <Fact label="Last signed in">{formatDateTime(account.last_login_at)}</Fact>
              <Fact label="Sign-in">{account.is_active ? 'Allowed' : 'Turned off'}</Fact>
            </dl>
          </Section>

          <HistorySection events={profile.history} />
        </div>
      </div>
    </div>
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
          className="flex w-full items-center gap-3 px-5 py-3.5 text-left transition-colors hover:bg-royal-tint/40 focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-royal"
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

const SCOPE_LABEL: Record<OwnerProfileEvent['scope'], string> = {
  account: 'Account',
  business: 'Business',
  permit: 'Permit',
}

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

function HistorySection({ events }: { events: OwnerProfileEvent[] }) {
  const [all, setAll] = useState(false)
  const shown = all ? events : events.slice(0, HISTORY_SHOWN)

  return (
    <Section title="Status history" count={events.length}>
      <ol className="px-5 py-3">
        {shown.map((e, i) => (
          <li key={`${e.scope}-${e.id}-${i}`} className="relative flex gap-3 pb-4 last:pb-1">
            {/* The rail between dots; the last entry has none below it. */}
            {i < shown.length - 1 && (
              <span aria-hidden="true" className="absolute left-[5px] top-4 h-full w-px bg-line" />
            )}
            <span
              aria-hidden="true"
              className={`relative mt-1.5 h-[11px] w-[11px] shrink-0 rounded-full ring-2 ring-white ${eventDot(e)}`}
            />
            <div className="min-w-0 text-sm">
              <p className="font-semibold leading-snug text-ink">
                {e.label}
                {e.to_label && !e.label.toLowerCase().includes(e.to_label.toLowerCase()) && (
                  <span className="font-normal text-ink-secondary"> → {e.to_label}</span>
                )}
              </p>
              {e.scope !== 'account' && (
                <p className="truncate text-xs text-ink-secondary">
                  <span className="sr-only">{SCOPE_LABEL[e.scope]}: </span>
                  {e.subject}
                </p>
              )}
              {e.reason && <p className="mt-0.5 text-xs leading-snug text-ink-secondary">“{e.reason}”</p>}
              <p className="mt-0.5 text-xs text-ink-muted">
                {formatDateTime(e.at)}
                {e.by && <> · {e.by}</>}
              </p>
            </div>
          </li>
        ))}
      </ol>
      {events.length > HISTORY_SHOWN && (
        <div className="border-t border-line px-5 py-2.5">
          <button
            type="button"
            onClick={() => setAll((a) => !a)}
            aria-expanded={all}
            className="text-sm font-semibold text-royal hover:underline"
          >
            {all ? 'Show the latest only' : `Show all ${events.length.toLocaleString()} entries`}
          </button>
        </div>
      )}
    </Section>
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

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="bg-canvas/60 px-4 py-2.5 lg:min-w-[7.5rem]">
      <dt className="text-[11px] font-semibold uppercase tracking-wider text-ink-muted">{label}</dt>
      <dd className="tnum mt-0.5 text-lg font-bold text-ink">{value}</dd>
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

/** "Show all N" under a list cut to its latest rows; nothing when it all fits. */
function ShowAll({ total, all, onToggle, noun }: { total: number; all: boolean; onToggle: () => void; noun: string }) {
  if (total <= ROWS_SHOWN) return null
  return (
    <div className="border-t border-line px-5 py-2.5">
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={all}
        className="text-sm font-semibold text-royal hover:underline"
      >
        {all ? 'Show the latest only' : `Show all ${total.toLocaleString()} ${noun}`}
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
