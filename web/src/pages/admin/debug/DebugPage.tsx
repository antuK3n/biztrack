import type { ReactNode } from 'react'
import { Navigate } from 'react-router-dom'
import { PageTitle } from '../../../components/ui/Proto'
import { homePathFor } from '../../../lib/api'
import { formatDateTime } from '../../../lib/format'
import { useAsync } from '../../../lib/useAsync'
import { useAuth } from '../../../stores/auth'
import { canUseDebug } from './access'
import { debugPanel } from './api'
import { PaymentsSection } from './PaymentsSection'

/*
 * Debug — the super admin's on-the-fly controls for the thesis defense
 * [Ken, 2026-10-04].
 *
 * One page with a section per thing that may need changing while the panel
 * watches, rather than a screen per switch: during a presentation the super
 * admin should know exactly where to go, and the rail stays one entry long.
 *
 * ── Adding a section ─────────────────────────────────────────────────────
 *
 * Write it as its own component in this folder (`<Name>Section.tsx`), give
 * its API calls their own object in `./api.ts`, and add one row to SECTIONS.
 * The heading, the anchor (`/admin/debug#<id>`) and the jump list above the
 * sections come from the row; nothing else here changes. A section loads its
 * own data and shows its own errors, so one that cannot load does not take
 * the others down with it. Its endpoints go in api/routes/debug.php, inside
 * the one `debug.panel` group, and each change it makes is written with
 * DebugPanel::audit().
 *
 * ── Who may open it ──────────────────────────────────────────────────────
 *
 * The server decides (App\Support\DebugPanel: the super admin, while the
 * panel is opened from the server with `php artisan biztrack:debug-panel on
 * --hours=N`). This app reads the verdict in access.ts and nowhere else.
 */

interface DebugSection {
  /** The anchor, and the `id` of the section's heading. */
  id: string
  title: string
  /** One sentence: what changing things here affects. */
  summary: string
  body: () => ReactNode
}

const SECTIONS: DebugSection[] = [
  {
    id: 'payments',
    title: 'Payments',
    summary:
      'How owners pay, and what KwikPay collects. Changes apply to the next payment; none touches a payment already started.',
    body: () => <PaymentsSection />,
  },
]

export function DebugPage() {
  return (
    <div>
      <PageTitle>Debug</PageTitle>
      <PanelWindow />

      {/* A jump list once there is somewhere to jump to. */}
      {SECTIONS.length > 1 && (
        <nav aria-label="Debug sections" className="mb-6 flex flex-wrap gap-x-4 gap-y-2 text-sm">
          {SECTIONS.map((section) => (
            <a key={section.id} href={`#${section.id}`} className="font-semibold text-royal hover:underline">
              {section.title}
            </a>
          ))}
        </nav>
      )}

      <div className="space-y-10">
        {SECTIONS.map((section) => (
          <section key={section.id} aria-labelledby={section.id} className="scroll-mt-6">
            <h2 id={section.id} className="text-xl font-bold text-ink">
              {section.title}
            </h2>
            <p className="mb-4 mt-1 max-w-[70ch] text-sm text-ink-secondary">{section.summary}</p>
            {section.body()}
          </section>
        ))}
      </div>
    </div>
  )
}

/*
 * How long the panel stays open, said once at the top so the super admin
 * knows it will shut by itself — and that nothing on this page can keep it
 * open longer. Silent while it loads or if it cannot: the sections below are
 * what the page is for.
 */
function PanelWindow() {
  const { data } = useAsync(() => debugPanel.window(), [])
  if (!data) return null

  return (
    <p className="-mt-3 mb-6 max-w-[70ch] text-sm text-ink-secondary">
      {data.local
        ? 'Open because this server runs in local development, where the panel needs no flag.'
        : data.open_until
          ? `Open until ${formatDateTime(data.open_until)}, then it closes by itself. Changes here apply at once.`
          : 'Changes here apply at once.'}
    </p>
  )
}

/**
 * The route guard for both addresses of the page. Anyone canUseDebug refuses
 * — everybody but the super admin, and the super admin too while the panel is
 * closed — is sent to their own home, as for an address that does not exist.
 */
export function RequireDebugAccess({ children }: { children: ReactNode }) {
  const { user, portal } = useAuth()
  if (!canUseDebug(user)) return <Navigate to={homePathFor(portal)} replace />
  return children
}
