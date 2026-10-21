import { useId, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import { AlertTriangleIcon, ChevronRightIcon, MailIcon } from '../../components/icons'
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
  const allOfPayments = profile.payments ?? []
  const payments = allPayments ? allOfPayments : allOfPayments.slice(0, ROWS_SHOWN)
  const fees = profile.fees ?? []
  const shownFees = allPayments ? fees : fees.slice(0, ROWS_SHOWN)

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
              {('email' in account || 'mobile_number' in account) && (
                <p className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-sm text-ink-secondary">
                  <span className="break-all">{account.email ?? 'No email'}</span>
                  <span className="tnum">{account.mobile_number ?? 'No mobile number'}</span>
                </p>
              )}
              {'created_at' in account && (
                <p className="mt-0.5 text-xs text-ink-muted">Owner since {formatDate(account.created_at)}</p>
              )}
              {/*
                The owner's General enquiry with the reader's own office
                [client, 21 October 2026]. Every office seat in Messages has
                it; the super admin, who holds no conversation with owners,
                does not (can_message). The name rides along for the pane's
                title, in case the owner is past the inbox's first page.
              */}
              {profile.can_message && (
                <Link
                  to={`${portalPath(activePortal(), '/messages')}?application=general-${account.id}`}
                  state={{ ownerName: account.name }}
                  className="mt-3 inline-flex items-center gap-1.5 rounded-full bg-royal px-4 py-1.5 text-sm font-semibold text-white hover:bg-royal-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-royal"
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

          {profile.payments !== null ? (
            <Section title="Payment history" count={allOfPayments.length}>
              {allOfPayments.length === 0 ? (
                <Empty>No payments on the filings you can see.</Empty>
              ) : (
                <PaymentsTable payments={payments} />
              )}
              <ShowAll
                total={allOfPayments.length}
                all={allPayments}
                onToggle={() => setAllPayments((a) => !a)}
                noun="payments"
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
                <Table
                  head={['Tracking ID', 'Business', 'Paid', 'Your fee']}
                  alignLastRight
                  rows={shownFees.map((f) => ({
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
                          <span className="mt-0.5 block whitespace-nowrap text-xs text-ink-muted">
                            {formatDate(f.paid_at)}
                          </span>
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
              )}
              <ShowAll total={fees.length} all={allPayments} onToggle={() => setAllPayments((a) => !a)} noun="fees" />
            </Section>
          )}
        </div>

        {/* ── Reference facts and the timeline ───────────────────────── */}
        <div className="min-w-0 space-y-5">
          <Section title="Account information">
            <dl className="divide-y divide-line px-5">
              <Fact label="Full name">{account.name}</Fact>
              <Fact label="Sex">{account.gender ? (SEX[account.gender] ?? account.gender) : '—'}</Fact>
              {'email' in account && (
                <Fact label="Email">
                  <span className="break-all">{account.email ?? '—'}</span>
                  {account.email && 'email_verified_at' in account && (
                    <span
                      className={`mt-0.5 block text-xs ${account.email_verified_at ? 'text-s-green' : 'text-ink-muted'}`}
                    >
                      {account.email_verified_at ? 'Confirmed' : 'Not confirmed'}
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
              {'last_login_at' in account && (
                <Fact label="Last signed in">{formatDateTime(account.last_login_at)}</Fact>
              )}
              {'is_active' in account && <Fact label="Sign-in">{account.is_active ? 'Allowed' : 'Turned off'}</Fact>}
            </dl>
            {!('email' in account) && (
              <p className="border-t border-line px-5 py-2.5 text-xs leading-snug text-ink-muted">
                Reach the owner through Message, or the business's own contact numbers on the filing.
              </p>
            )}
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

function HistorySection({ events }: { events: OwnerProfileEvent[] }) {
  const [all, setAll] = useState(false)
  const [filter, setFilter] = useState<HistoryFilter>('all')

  // What this reader was sent decides what there is to choose between.
  const offices = OFFICE_ORDER.filter((code) => events.some((e) => e.scope === 'permit' && e.office_code === code))
  const hasAccount = events.some((e) => e.scope !== 'permit')
  const certificateOf = (code: string) => events.find((e) => e.office_code === code)?.permit_type ?? code
  const choosable = hasAccount || offices.length > 1

  const filtered = events.filter((e) =>
    filter === 'all'
      ? true
      : filter === 'account'
        ? e.scope !== 'permit'
        : e.scope === 'permit' && e.office_code === filter,
  )
  const shown = all ? filtered : filtered.slice(0, HISTORY_SHOWN)

  return (
    <Section title="Status history" count={filtered.length}>
      {choosable ? (
        <div className="border-b border-line px-5 py-3">
          <label htmlFor="history-filter" className="mb-1 block text-xs font-semibold text-ink-muted">
            Show
          </label>
          <select
            id="history-filter"
            value={filter}
            onChange={(e) => {
              setFilter(e.target.value)
              setAll(false)
            }}
            className="w-full rounded-lg border border-input-border bg-input px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-royal"
          >
            <option value="all">All status history</option>
            {hasAccount && <option value="account">Account and businesses</option>}
            {offices.map((code) => (
              <option key={code} value={code}>
                {code} — {certificateOf(code)}
              </option>
            ))}
          </select>
        </div>
      ) : (
        offices.length === 1 && (
          <p className="border-b border-line px-5 py-2.5 text-xs text-ink-muted">
            Your office's <b className="font-semibold text-ink-secondary">{certificateOf(offices[0])}</b> only.
          </p>
        )
      )}

      {shown.length === 0 ? (
        <p className="px-5 py-4 text-sm text-ink-muted">Nothing recorded here yet.</p>
      ) : (
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
              <div className="min-w-0 flex-1 text-sm">
                <p className="font-semibold leading-snug text-ink">{e.label}</p>

                {/* What it is about. */}
                <p className="mt-1 flex min-w-0 items-start gap-1.5 text-xs text-ink-secondary">
                  <span className="mt-px shrink-0 rounded bg-royal-tint px-1.5 py-px text-[10px] font-bold uppercase tracking-wide text-royal">
                    {subjectPill(e)}
                  </span>
                  {e.scope === 'permit' && (
                    <span className="min-w-0">
                      {e.permit_type} · <span className="tnum whitespace-nowrap">{e.permit_number}</span>
                    </span>
                  )}
                  {e.scope === 'account' && <span>The owner's account</span>}
                  {e.scope === 'business' && <span className="min-w-0">{e.business_name}</span>}
                </p>
                {e.scope === 'permit' && e.business_name && (
                  <p className="mt-0.5 truncate text-xs text-ink-muted">Business: {e.business_name}</p>
                )}

                {/* What happened to it. */}
                {e.to_label && (
                  <p className="mt-1 text-xs text-ink-secondary">
                    {e.from_label ? (
                      <>
                        {e.from_label} <span aria-hidden="true">→</span>
                        <span className="sr-only">to</span> <b className="font-semibold text-ink">{e.to_label}</b>
                      </>
                    ) : (
                      <>
                        Status: <b className="font-semibold text-ink">{e.to_label}</b>
                      </>
                    )}
                  </p>
                )}
                {e.reason && <p className="mt-0.5 text-xs leading-snug text-ink-secondary">Reason: “{e.reason}”</p>}

                {/* When, and who. */}
                <p className="mt-0.5 text-xs text-ink-muted">
                  {formatDateTime(e.at)}
                  {e.by ? (
                    <>
                      {' '}
                      · {e.by}
                      {e.by_office && ` (${e.by_office})`}
                    </>
                  ) : e.by_office ? (
                    ` · ${e.by_office}`
                  ) : (
                    e.label !== 'Account created' && ' · Automatic'
                  )}
                </p>
              </div>
            </li>
          ))}
        </ol>
      )}
      {filtered.length > HISTORY_SHOWN && (
        <div className="border-t border-line px-5 py-2.5">
          <button
            type="button"
            onClick={() => setAll((a) => !a)}
            aria-expanded={all}
            className="text-sm font-semibold text-royal hover:underline"
          >
            {all ? 'Show the latest only' : `Show all ${filtered.length.toLocaleString()} entries`}
          </button>
        </div>
      )}
    </Section>
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
