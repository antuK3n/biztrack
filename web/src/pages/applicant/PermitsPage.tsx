import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { AlertCircleIcon, DownloadIcon, EyeIcon, ShieldCheckIcon } from '../../components/icons'
import { EmptyState, ErrorState, SkeletonList } from '../../components/ui/primitives'
import { PageTitle, ProtoCard, SortFilter } from '../../components/ui/Proto'
import { businessName, formatDate } from '../../lib/format'
import { permits as permitsApi } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import { useAuth } from '../../stores/auth'
import type { Permit } from '../../lib/types'

/*
 * Profile (PDF p14–16): big avatar card with the owner's name + "{n}
 * businesses total" briefcase line, then a serif "Approved Businesses"
 * section of accordion rows per business — expanding into ROYAL rows per
 * permit with eye/download icons. Backed by the existing permits fetch.
 */

interface BusinessGroup {
  id: number
  name: string
  permits: Permit[]
  latestExpiry: string | null
  /** Soonest active-permit expiry — the date shown when a renewal is due. */
  soonestExpiry: string | null
  nearing: boolean
}

function AvatarGlyph({ size = 96 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" aria-hidden="true" className="text-line">
      <circle cx="12" cy="12" r="12" fill="currentColor" />
      <circle cx="12" cy="9.5" r="3.6" fill="#7c8494" />
      <path d="M4.8 20a7.5 7.5 0 0 1 14.4 0 12 12 0 0 1-14.4 0Z" fill="#7c8494" />
    </svg>
  )
}

function BriefcaseIcon({ size = 30 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" aria-hidden="true" className="text-royal">
      <rect x="3" y="7" width="18" height="13" rx="2" fill="currentColor" />
      <path d="M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7" stroke="currentColor" strokeWidth="1.75" fill="none" />
      <path d="M3 12.5h18" stroke="#fff" strokeWidth="1.2" />
      <rect x="10.5" y="11.3" width="3" height="2.4" rx="0.6" fill="#fff" />
    </svg>
  )
}

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
 * promises a file. It now downloads, so the two icons mean two things.
 */
function PermitDownloadButton({ permit }: { permit: Permit }) {
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState(false)

  async function download() {
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
      disabled={busy}
      aria-label={
        failed
          ? `Download failed for ${permit.permit_type.name}. Try again`
          : `Download ${permit.permit_type.name} as PDF`
      }
      className="text-white transition-opacity hover:opacity-80 disabled:opacity-50"
    >
      {failed ? <AlertCircleIcon size={22} /> : <DownloadIcon size={22} />}
    </button>
  )
}

function BusinessRow({ group }: { group: BusinessGroup }) {
  const [open, setOpen] = useState(false)
  return (
    <li className="space-y-2.5">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        className="flex w-full items-center gap-5 rounded-xl bg-white px-6 py-5 text-left shadow-card"
      >
        <Triangle open={open} />
        <span className="min-w-0 flex-1 truncate text-lg font-bold text-ink">{group.name}</span>
        <span className={`shrink-0 text-sm italic ${group.nearing ? 'font-semibold text-s-red' : 'text-ink-muted'}`}>
          {group.nearing ? 'Nearing Permit Expiration: ' : 'Permit Expiration: '}
          {formatDate(group.nearing ? group.soonestExpiry : group.latestExpiry)}
        </span>
      </button>
      {open && (
        <ul className="space-y-2">
          {group.permits.map((permit) => (
            <li
              key={permit.id}
              className="flex items-center gap-4 rounded-lg bg-royal px-5 py-3 shadow-card"
            >
              <span className="min-w-0 flex-1 truncate text-base font-bold text-white">
                {permit.permit_type.name}
              </span>
              <Link
                to={`/permits/${permit.id}`}
                aria-label={`View ${permit.permit_type.name}`}
                className="text-white transition-opacity hover:opacity-80"
              >
                <EyeIcon size={22} />
              </Link>
              <PermitDownloadButton permit={permit} />
            </li>
          ))}
        </ul>
      )}
    </li>
  )
}

export function PermitsPage() {
  const user = useAuth((s) => s.user)
  const { data, loading, error, reload } = useAsync(() => permitsApi.list(), [])
  const list = data ?? []

  const groups = useMemo<BusinessGroup[]>(() => {
    const map = new Map<number, BusinessGroup>()
    for (const permit of list) {
      /*
       * `business` is typed non-nullable and is not. A soft-deleted business
       * leaves its issued permits on the register, and the default scope drops
       * it from the eager load, so this comes back null — the same shape that
       * took the officer queue, the inspection list and the review sheet down
       * by reading `.name` straight off it (RemovedBusinessRenderingTest).
       *
       * Key 0 collects every orphaned permit into one group. They have no
       * business id left to tell them apart by, and one row saying the register
       * no longer holds the business is more useful than several.
       */
      const business = permit.business as Permit['business'] | null
      const key = business?.id ?? 0
      const g = map.get(key) ?? {
        id: key,
        name: businessName(business),
        permits: [],
        latestExpiry: null,
        soonestExpiry: null,
        nearing: false,
      }
      g.permits.push(permit)
      if (permit.valid_until && (!g.latestExpiry || permit.valid_until > g.latestExpiry)) {
        g.latestExpiry = permit.valid_until
      }
      if (permit.valid_until && (!g.soonestExpiry || permit.valid_until < g.soonestExpiry)) {
        g.soonestExpiry = permit.valid_until
      }
      if (permit.days_until_expiry !== null && permit.days_until_expiry <= 30) g.nearing = true
      map.set(key, g)
    }
    return [...map.values()]
  }, [list])

  const ownerName = user ? `${user.first_name} ${user.last_name}` : '—'

  return (
    <div>
      <PageTitle>Profile</PageTitle>

      {/* ── Owner card (p14) ───────────────────────────────────────────── */}
      <ProtoCard className="mb-10 px-8 py-8">
        <div className="flex flex-wrap items-start gap-7">
          <AvatarGlyph />
          <div className="min-w-0 flex-1 pt-2">
            <p className="truncate text-2xl font-bold text-ink">{ownerName}</p>
            <p className="mt-1 text-base text-ink-muted">
              Joined <span className="italic">{user?.email_verified_at ? formatDate(user.email_verified_at) : '—'}</span>
            </p>
          </div>
        </div>
        <div className="mt-2 flex items-center justify-end gap-3">
          <span className="display-serif text-xl text-ink">
            {groups.length} {groups.length === 1 ? 'business' : 'businesses'} total
          </span>
          <BriefcaseIcon />
        </div>
      </ProtoCard>

      {/* ── Approved Businesses (p15–16) ───────────────────────────────── */}
      <div className="mb-5 flex items-end justify-between gap-4 border-b border-ink/50 pb-2">
        <h2 className="display-serif text-2xl text-ink-secondary">Approved Businesses</h2>
        <SortFilter />
      </div>

      {loading ? (
        <SkeletonList rows={3} />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : groups.length === 0 ? (
        <EmptyState
          icon={ShieldCheckIcon}
          title="No approved businesses yet"
          description="When an application is approved and issued, your business and its permits appear here."
        />
      ) : (
        <ul className="space-y-4">
          {groups.map((g) => (
            <BusinessRow key={g.id} group={g} />
          ))}
        </ul>
      )}
    </div>
  )
}
