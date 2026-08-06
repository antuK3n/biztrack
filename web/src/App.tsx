import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import {
  BrowserRouter,
  Link,
  Navigate,
  Route,
  Routes,
  useLocation,
  useNavigate,
  useParams,
} from 'react-router-dom'
import { AppShell } from './components/AppShell'
import { Spinner } from './components/icons'
import { DashboardPage } from './pages/DashboardPage'
import { MessagesPage } from './pages/MessagesPage'
import { NotificationsPage } from './pages/NotificationsPage'
import { VerifyPage } from './pages/VerifyPage'
import { ForgotPasswordPage } from './pages/auth/ForgotPasswordPage'
import { LoginPage } from './pages/auth/LoginPage'
import { RegisterPage } from './pages/auth/RegisterPage'
import { ResetPasswordPage } from './pages/auth/ResetPasswordPage'
import { VerifyEmailPage } from './pages/auth/VerifyEmailPage'
import { ApplicationsPage } from './pages/applicant/ApplicationsPage'
import { ApplicationDetailPage } from './pages/applicant/ApplicationDetailPage'
import { ApplyWizard } from './pages/applicant/ApplyWizard'
import { ClearanceStagePage } from './pages/applicant/ClearanceStagePage'
import { DraftsPage } from './pages/applicant/DraftsPage'
import { PaymentsPage } from './pages/applicant/PaymentsPage'
import { PayPage } from './pages/applicant/PayPage'
import { PermitsPage } from './pages/applicant/PermitsPage'
import { PermitDetailPage } from './pages/applicant/PermitDetailPage'
import { QueuePage } from './pages/officer/QueuePage'
import { ReviewPage } from './pages/officer/ReviewPage'
import { AnalyticsPage } from './pages/admin/AnalyticsPage'
import { ProcessingTimePage } from './pages/admin/ProcessingTimePage'
import { BusinessGrowthPage } from './pages/admin/BusinessGrowthPage'
import { RenewalRiskPage } from './pages/admin/RenewalRiskPage'
import { UsersPage } from './pages/admin/UsersPage'
import { AuditLogsPage } from './pages/admin/AuditLogsPage'
import { OwnersPage } from './pages/admin/OwnersPage'
import { ProfilePage } from './pages/ProfilePage'
import { RequestsPage } from './pages/RequestsPage'
import { SettingsPage } from './pages/SettingsPage'
import { activePortal, homePathFor, loginPathFor, portalPath } from './lib/api'
import { assignments, inspections } from './lib/resources'
import { useAuth } from './stores/auth'

function FullPageSpinner() {
  return (
    <div className="flex min-h-dvh items-center justify-center" role="status" aria-label="Loading BizTrack">
      <Spinner size={28} className="text-blue-600" />
    </div>
  )
}

function RequireAuth({ children }: { children: ReactNode }) {
  const { user, portal, bootstrapped } = useAuth()
  const location = useLocation()
  if (!bootstrapped) return <FullPageSpinner />
  if (!user) return <Navigate to={loginPathFor(portal)} state={{ from: location.pathname }} replace />
  return children
}

/**
 * Routes the API would 403 anyway, hidden at the router so nobody lands on an
 * officer or admin screen they can't use. Defence in depth, not the defence.
 */
function RequirePermission({ permission, children }: { permission: string; children: ReactNode }) {
  const { user, portal } = useAuth()
  if (!user?.permissions.includes(permission)) return <Navigate to={homePathFor(portal)} replace />
  return children
}

/** Signed-in users skip the auth screens — of the site they are signed into. */
function RedirectIfAuthed({ children }: { children: ReactNode }) {
  const { user, portal, bootstrapped } = useAuth()
  if (!bootstrapped) return <FullPageSpinner />
  if (user) return <Navigate to={homePathFor(portal)} replace />
  return children
}

/**
 * Where an unmatched path lands: the sign-in page of whichever site it was on.
 *
 * `/staff/nonsense` must not fall through to the citizen login. That would put
 * an officer at the wrong door — and at one whose session is a different token
 * entirely, so they would appear to be signed out of an account that is fine.
 */
function NotFoundRedirect() {
  return <Navigate to={loginPathFor(activePortal())} replace />
}

/**
 * A path that used to live at the root and is now under /staff.
 *
 * Officer notifications already in the database carry the old address —
 * NotificationService wrote `/queue/{id}` before the split — and testers have
 * bookmarks. Both keep working. The id is carried across so a notification
 * still opens the filing it was about rather than dumping the officer on the
 * queue list to find it again.
 */
function MovedToStaff({ path }: { path: string }) {
  const { id } = useParams()
  return <Navigate to={portalPath('staff', id ? `${path}/${id}` : path)} replace />
}

/**
 * The analytics shim, which unlike the others has subpaths under it.
 *
 * It used to be a bare `<Navigate to="/staff/analytics">`, so every deep link
 * — /analytics/renewal-risk, /analytics/processing-time — landed on the
 * Overview instead. That is not just a stale-bookmark problem: it is how the
 * tab strip's own broken links stayed invisible, because the tabs pointed here
 * and the redirect quietly answered every one of them with the dashboard.
 *
 * Carrying the rest of the path across means a wrong link now lands on the
 * right screen, and a genuinely wrong one reaches the 404 instead of being
 * absorbed.
 */
function MovedAnalytics() {
  const rest = useParams()['*'] ?? ''
  return <Navigate to={portalPath('staff', rest ? `/analytics/${rest}` : '/analytics')} replace />
}

/**
 * /staff/inspections/{id} — the screen that used to decide a site visit.
 *
 * The Inspections page is gone (see the routes below, and the note at the top
 * of components/InspectionDecision.tsx). Notifications already sent and
 * bookmarks already made point at these addresses, and an inspection deep link
 * names ONE visit on ONE filing, so it has to land on that filing rather than
 * on the queue in general.
 *
 * The analytics shim two comments up is the cautionary tale: it exists because
 * a redirect that dropped its subpath answered every deep link with the wrong
 * screen, silently and plausibly. Dropping the id here would be the same
 * mistake, and so — importantly — would carrying it across blindly:
 * `/staff/queue/{n}` takes an ASSIGNMENT id, and an inspection id that happens
 * to match an unrelated assignment would open a different business's filing
 * with no sign anything had gone wrong. That is worse than a 404.
 *
 * So the id is RESOLVED rather than reused:
 *
 *   GET /inspections/{id} → which filing the visit belongs to. Answers 403 for
 *                           another department's visit, which is the right
 *                           answer to give this reader anyway.
 *   GET /assignments      → this office's own queue, read a page at a time
 *                           until the assignment on that filing turns up.
 *
 * The queue is newest-first and a link worth following is nearly always to a
 * live filing, so this stops on the first page in practice. It is capped at
 * `MAX_PAGES` regardless: an office holds well over a thousand assignments, and
 * a visit on a filing that was never routed here — reachable when the reader is
 * the named inspector rather than the department — would otherwise page through
 * every one of them to conclude nothing. A filing older than the cap gets the
 * explanation below rather than the wrong screen, which is the trade this whole
 * component exists to make.
 *
 * (`q` on /assignments would make this one request and is what the queue screen
 * itself searches with — AssignmentFilters does not declare it, and that file
 * belongs to another change. Worth folding in when it does.)
 */
function MovedInspection() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const [unresolved, setUnresolved] = useState(false)

  useEffect(() => {
    const inspectionId = Number(id)
    if (!Number.isInteger(inspectionId) || inspectionId <= 0) {
      setUnresolved(true)
      return
    }

    const MAX_PAGES = 5
    const PER_PAGE = 100

    let cancelled = false
    void (async () => {
      try {
        const visit = await inspections.get(inspectionId)
        // Null when the response did not eager-load the filing. GET
        // /inspections/{id} always does, but the type is honest and so is this.
        const filing = visit.application
        if (!filing) throw new Error('inspection carries no filing')

        for (let page = 1; page <= MAX_PAGES; page++) {
          const queue = await assignments.page({ page, per_page: PER_PAGE })
          if (cancelled) return

          const match = queue.data.find((a) => a.application.id === filing.id)
          if (match) {
            navigate(`/staff/queue/${match.id}`, { replace: true })
            return
          }
          if (page >= queue.meta.last_page) break
        }
        setUnresolved(true)
      } catch {
        if (!cancelled) setUnresolved(true)
      }
    })()

    return () => {
      cancelled = true
    }
  }, [id, navigate])

  return (
    <div className="rounded-lg bg-white px-5 py-6 shadow-card">
      <h1 className="text-base font-bold text-ink">
        {unresolved ? 'This inspection is not on your queue' : 'Opening this inspection…'}
      </h1>
      <p className="mt-1.5 max-w-prose text-sm text-ink-secondary">
        {unresolved
          ? 'Inspections are decided on the filing itself now, under Track → For Inspection. This link points at a visit belonging to another office, or to a filing that has since been decided or removed.'
          : 'Site visits are decided on the filing now. Taking you to it.'}
      </p>
      {unresolved && (
        <Link
          to="/staff/queue"
          className="mt-4 inline-flex rounded-md bg-royal px-5 py-2 text-sm font-semibold text-white hover:bg-royal-hover"
        >
          Go to Track
        </Link>
      )}
    </div>
  )
}

export default function App() {
  const bootstrap = useAuth((s) => s.bootstrap)

  useEffect(() => {
    void bootstrap()
  }, [bootstrap])

  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<Navigate to="/login" replace />} />
        <Route
          path="/login"
          element={
            <RedirectIfAuthed>
              <LoginPage />
            </RedirectIfAuthed>
          }
        />
        {/* Staff and super admin sign in through their own door (see AuthController). */}
        <Route
          path="/staff/login"
          element={
            <RedirectIfAuthed>
              <LoginPage portal="staff" />
            </RedirectIfAuthed>
          }
        />
        <Route
          path="/register"
          element={
            <RedirectIfAuthed>
              <RegisterPage />
            </RedirectIfAuthed>
          }
        />
        <Route
          path="/forgot-password"
          element={
            <RedirectIfAuthed>
              <ForgotPasswordPage />
            </RedirectIfAuthed>
          }
        />
        <Route path="/reset-password" element={<ResetPasswordPage />} />
        <Route path="/verify-email" element={<VerifyEmailPage />} />
        {/* Public permit verification — standalone, no auth, no AppShell. */}
        <Route path="/verify/:permit_number" element={<VerifyPage />} />

        {/*
          ── The citizen site ────────────────────────────────────────────
          Everything a business owner does, at the root. The officer and
          admin screens are NOT here; they are their own site below.
        */}
        <Route
          element={
            <RequireAuth>
              <AppShell />
            </RequireAuth>
          }
        >
          <Route path="/dashboard" element={<DashboardPage />} />
          {/* Applicant */}
          <Route path="/apply" element={<ApplyWizard />} />
          <Route path="/applications" element={<ApplicationsPage />} />
          <Route path="/applications/:id" element={<ApplicationDetailPage />} />
          <Route path="/applications/:id/pay" element={<PayPage />} />
          {/*
            LGU Clearances gets a route of its own rather than a panel on the
            status page, for three reasons.

            It is a stage, not a detail: six independent transactions, each with
            its own office, fee and outcome, and applying for one opens a
            full-page office form sheet. Mounted inside the status page that
            sheet would have to fight the status card, the remarks, the history
            and the message thread for the same screen — the form sheets are the
            reason the wizard gave them steps of their own in the first place.

            It has an address. An officer chasing a missing sanitary clearance,
            or the notification that says one was refused, can point at the
            stage itself. A panel three sections down a page has nowhere to
            point.

            And Back works. Opening an office form and going back is a browser
            gesture here, not a piece of local state that a refresh loses.
          */}
          <Route path="/applications/:id/clearances" element={<ClearanceStagePage />} />
          <Route path="/drafts" element={<DraftsPage />} />
          <Route path="/payments" element={<PaymentsPage />} />
          <Route path="/permits" element={<PermitsPage />} />
          <Route path="/permits/:id" element={<PermitDetailPage />} />
          <Route path="/messages" element={<MessagesPage />} />
          <Route path="/notifications" element={<NotificationsPage />} />
          <Route path="/profile" element={<ProfilePage />} />
          <Route path="/settings" element={<SettingsPage />} />
          {/* Owners reach this from the "Other Requirements" home card, so it
              is a citizen route as well as a staff one. */}
          <Route path="/requests" element={<RequestsPage />} />
        </Route>

        {/*
          ── The LGU site ────────────────────────────────────────────────
          Officers and the administrator, on their own paths, with their own
          session. The prefix is not decoration: `activePortal()` reads it out
          of the address bar to pick which token to send, which is what lets a
          staff tab and an owner tab be signed in at once in one browser. See
          the note in lib/api.ts.

          The screens both sides share — Home, Messages, Notifications,
          Profile, Settings, Other Requirements — are mounted in both trees
          rather than being one shared branch. They render per-user anyway, and
          a single copy would have had to sit outside the prefix, which is the
          one thing the portal split cannot allow.
        */}
        <Route
          element={
            <RequireAuth>
              <AppShell />
            </RequireAuth>
          }
        >
          <Route path="/staff/dashboard" element={<DashboardPage />} />
          <Route path="/staff/messages" element={<MessagesPage />} />
          <Route path="/staff/notifications" element={<NotificationsPage />} />
          <Route path="/staff/profile" element={<ProfilePage />} />
          <Route path="/staff/settings" element={<SettingsPage />} />
          <Route path="/staff/requests" element={<RequestsPage />} />
          {/* Officer */}
          <Route
            path="/staff/queue"
            element={
              <RequirePermission permission="application.review">
                <QueuePage />
              </RequirePermission>
            }
          />
          <Route
            path="/staff/queue/:id"
            element={
              <RequirePermission permission="application.review">
                <ReviewPage />
              </RequirePermission>
            }
          />
          {/*
            ── Inspections, which is not a screen any more ──────────────────
            The Inspections page rendered a second, older copy of the decision
            an officer already makes on the filing. "The Track page -> For
            Inspection is redundant with the Inspections page. Remove the
            Inspections page. All inspections will happen in The Track page ->
            For Inspection." Both addresses stay, as redirects, because links
            to them are already in the wild.

            The LIST can only land on Track itself. Which tab Track opens on is
            component state inside QueuePage, not something the URL can say, so
            there is no honest way from here to put the officer on the For
            Inspection tab specifically — they arrive at Track and pick it. If
            those tabs ever become addressable, this is the line to change.
          */}
          <Route path="/staff/inspections" element={<Navigate to="/staff/queue" replace />} />
          {/*
            The deep link resolves to the filing it names — see MovedInspection.
            Ungated on purpose: it holds no inspection data of its own, and the
            two requests behind it are authorised by the API. `inspection.manage`
            here would only turn a resolvable link into a silent bounce home for
            BPLO, who can read the filing perfectly well.
          */}
          <Route path="/staff/inspections/:id" element={<MovedInspection />} />
          {/* Admin */}
          <Route
            path="/staff/analytics"
            element={
              <RequirePermission permission="analytics.view">
                <AnalyticsPage />
              </RequirePermission>
            }
          />
          {/*
            Processing Time is the ONE analytics screen that is not BPLO's.

            All four used to sit on `analytics.view`, which meant whoever could
            open the dashboard could open this too. The client drew the line the
            other way round: "BPLO side should only have the 3 dashboards
            (Processing Time should not exist here) — Super admin side should
            only have Processing Time dashboard". The R INTEGRATION DRAFTS
            headings say the same thing — §1/§2/§4 are "(Admin - BPLO)", §6 is
            "(Super Admin)" — so the permission was split to match: `analytics.view`
            for the three, `analytics.processing_time` for this one.

            The two are deliberately disjoint. Neither role holds both, so this
            route is unreachable for BPLO and the other three are unreachable for
            the super admin. Granting a role both permissions would quietly undo
            the separation the client asked for, and nothing here would fail.
          */}
          <Route
            path="/staff/analytics/processing-time"
            element={
              <RequirePermission permission="analytics.processing_time">
                <ProcessingTimePage />
              </RequirePermission>
            }
          />
          <Route
            path="/staff/analytics/business-growth"
            element={
              <RequirePermission permission="analytics.view">
                <BusinessGrowthPage />
              </RequirePermission>
            }
          />
          {/*
            Renewal Risk ranks every business's permits by a weighted rule score,
            so it sits on `analytics.view` alongside the other two BPLO screens
            rather than being visible to every office reviewer.
          */}
          <Route
            path="/staff/analytics/renewal-risk"
            element={
              <RequirePermission permission="analytics.view">
                <RenewalRiskPage />
              </RequirePermission>
            }
          />
          <Route
            path="/staff/admin/users"
            element={
              <RequirePermission permission="user.manage">
                <UsersPage />
              </RequirePermission>
            }
          />
          <Route
            path="/staff/admin/owners"
            element={
              <RequirePermission permission="owner.manage_status">
                <OwnersPage />
              </RequirePermission>
            }
          />
          <Route
            path="/staff/admin/audit-logs"
            element={
              <RequirePermission permission="audit.view">
                <AuditLogsPage />
              </RequirePermission>
            }
          />
        </Route>

        {/*
          Where the staff screens used to live. Kept so notifications already
          sent, and bookmarks already made, still land somewhere useful.
        */}
        <Route path="/queue" element={<MovedToStaff path="/queue" />} />
        <Route path="/queue/:id" element={<MovedToStaff path="/queue" />} />
        {/*
          Two hops for the oldest inspection links, deliberately. These forward
          to /staff/inspections/{id}, which is itself now a redirect onto the
          filing (MovedInspection). Collapsing the chain here would mean two
          copies of the resolution logic, and this one is the shim for a path
          that predates the portal split — it has one job, which is the prefix.
        */}
        <Route path="/inspections" element={<MovedToStaff path="/inspections" />} />
        <Route path="/inspections/:id" element={<MovedToStaff path="/inspections" />} />
        <Route path="/analytics/*" element={<MovedAnalytics />} />
        <Route path="/admin/*" element={<Navigate to="/staff/dashboard" replace />} />

        <Route path="*" element={<NotFoundRedirect />} />
      </Routes>
    </BrowserRouter>
  )
}
