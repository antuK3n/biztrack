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
import { PayPage } from './pages/applicant/PayPage'
import { PermitsPage } from './pages/applicant/PermitsPage'
import { PermitDetailPage } from './pages/applicant/PermitDetailPage'
import { QueuePage } from './pages/officer/QueuePage'
import { ReviewPage } from './pages/officer/ReviewPage'
import { AnalyticsPage } from './pages/admin/AnalyticsPage'
import { ReportsPage } from './pages/admin/ReportsPage'
import { ProcessingTimePage } from './pages/admin/ProcessingTimePage'
import { OfficePerformancePage } from './pages/admin/OfficePerformancePage'
import { UsersPage } from './pages/admin/UsersPage'
import { OfficerCaseloadPage } from './pages/admin/OfficerCaseloadPage'
import { AuditLogsPage } from './pages/admin/AuditLogsPage'
import { OicPage } from './pages/admin/OicPage'
import { OwnersPage } from './pages/admin/OwnersPage'
import { StaffMessagesPage } from './pages/admin/StaffMessagesPage'
import { RecordsPage } from './pages/admin/RecordsPage'
import { PermitsPage as AdminPermitsPage } from './pages/admin/PermitsPage'
import { BusinessMapPage } from './pages/admin/BusinessMapPage'
import { ImportPage } from './pages/admin/ImportPage'
import { DebugPage, RequireDebugAccess } from './pages/admin/debug/DebugPage'
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
 * — /analytics/processing-time, /analytics/offices — landed on the
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
        {/* The six offices and BPLO sign in here (see AuthController). */}
        <Route
          path="/staff/login"
          element={
            <RedirectIfAuthed>
              <LoginPage portal="staff" />
            </RedirectIfAuthed>
          }
        />
        {/*
          And the super admin here — the third door [checklist item #107].

          `admin` used to be one of AuthController::STAFF_ROLES, so the person
          who creates every officer account signed in at the same address as the
          officers and shared their `biztrack.token.staff` key. The API now
          answers 409 to an administrator at /staff/login and to an officer
          here, and each door mints its own token under its own key.
        */}
        <Route
          path="/admin/login"
          element={
            <RedirectIfAuthed>
              <LoginPage portal="admin" />
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
          {/*
            One office's sheet, on a page of its own.

            Same component: the code names which of the six is open, and
            the route without one shows the cards. The client asked for
            this on 30 September 2026 after Fix and resubmit dropped them
            at the card grid rather than at the form they had been asked
            to correct.
          */}
          <Route
            path="/applications/:id/clearances/:code"
            element={<ClearanceStagePage />}
          />
          <Route path="/drafts" element={<DraftsPage />} />
          {/* Payment History moved onto Profile [checklist 2026-09-27]. The
              old address still lands on it. */}
          <Route path="/payments" element={<Navigate to="/profile?tab=payments" replace />} />
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
            Processing Time is not BPLO's, nor any office's.

            The dashboard above is every office's since checklist 2026-09-27
            item 1 (`analytics.view` on every office admin, BPLO and the super
            admin, scoped server-side). This screen stays on
            `analytics.processing_time`, which only the super admin holds: it
            measures the departments, BPLO among them, and the office being
            measured does not hold the measuring screen. That half of the old
            split is the half that survives.
          */}
          <Route
            path="/staff/analytics/processing-time"
            element={
              <RequirePermission permission="analytics.processing_time">
                <ProcessingTimePage />
              </RequirePermission>
            }
          />
          {/*
            Office Performance (issue #102): the same six offices Processing Time
            charts, but compared against each other and against RA 11032 rather
            than each against its own past.

            Same permission as the route above, and for the same reason rather
            than by proximity — it ranks the departments, BPLO among them, so it
            belongs to the office doing the oversight. `analytics.view` would put
            BPLO in front of a league table it appears in.

            This permission is repeated in three places that must agree: here,
            the rail entry in lib/nav.ts, and the tab in AnalyticsTabs. Nothing
            derives one from another, so changing one without the others turns a
            visible link into a bounce home. e2e/office-performance.spec.ts and
            e2e/analytics.spec.ts are what catch that.
          */}
          <Route
            path="/staff/analytics/offices"
            element={
              <RequirePermission permission="analytics.processing_time">
                <OfficePerformancePage />
              </RequirePermission>
            }
          />
          {/*
            Report Generation (checklist 2026-09-27, item 7). Same permission as
            the dashboard, and the same server-side office boundary.
          */}
          <Route
            path="/staff/analytics/reports"
            element={
              <RequirePermission permission="analytics.view">
                <ReportsPage />
              </RequirePermission>
            }
          />
          {/*
            /staff/analytics/business-growth and /staff/analytics/renewal-risk
            were here. Both screens were removed (checklist 2026-09-27, item 6);
            their useful panels moved onto the dashboard above. An old link to
            either now reaches the 404, which is the honest answer.
          */}
          <Route
            path="/staff/admin/users"
            element={
              <RequirePermission permission="user.manage">
                <UsersPage />
              </RequirePermission>
            }
          />
          <Route
            path="/staff/admin/users/:userId/reassign"
            element={
              /*
                One officer's caseload, in the Officer in Charge format.

                Guarded on `oic.assign` rather than the `user.manage` that
                opens the directory it is reached from: moving a filing
                between officers is the OIC act, and the two permissions come
                apart — the Reassign link itself is already hidden without it
                (see UsersPage), and a route that trusted the link would be a
                guard that only exists in the markup.
              */
              <RequirePermission permission="oic.assign">
                <OfficerCaseloadPage />
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
          {/*
            * Where office accounts reach the System Administrator.
            *
            * `user.manage`, not `message.participate`: the super admin does
            * not hold the latter and should not — it opens every filing
            * conversation in the city, and what this seat needs is the handful
            * about accounts. The permission that already means "administers
            * the other accounts" is the one this screen exercises.
            *
            * ── `office-messages`, not `messages` ──────────────────────
            *
            * `/admin/messages` was already taken — by the ordinary Messages
            * page, mounted per-prefix like Profile and Settings. The rail
            * entry pointed straight at it, so pressing "Office Messages" as
            * the super admin opened the applicant-and-officer inbox instead,
            * which for an account without `message.participate` renders
            * nothing at all. It looked exactly like a feature that did not
            * work.
            */}
          <Route
            path="/staff/admin/office-messages"
            element={
              <RequirePermission permission="user.manage">
                <StaffMessagesPage />
              </RequirePermission>
            }
          />
          {/*
            * `oic.assign`, the same permission the endpoints behind this screen
            * are gated on — not `user.manage`. Naming who handles a case and
            * correcting an officer's surname are different powers, and the
            * route must ask for the one the page actually exercises or the
            * screen would render for an account the API then refuses.
            */}
          <Route
            path="/staff/admin/oic"
            element={
              <RequirePermission permission="oic.assign">
                <OicPage />
              </RequirePermission>
            }
          />
          {/*
            The read-only register console, matching the rail entry's gate in
            nav.ts — see the note there for why it is `user.manage` and not the
            `application.view_all` this was first written against, which six
            offices also hold.

            The route and the rail must carry the SAME permission or one of them
            lies: a rail that hides the entry while the route still admits a
            typed URL is a screen nobody can find and anybody can reach.
          */}
          <Route
            path="/staff/admin/records"
            element={
              <RequirePermission permission="user.manage">
                <RecordsPage />
              </RequirePermission>
            }
          />
          {/*
            Every issued certificate, as one table (issue #103).

            `permit.view_all` is the permission the endpoint behind this screen
            is already read through — PermitController::scopeToReader — so the
            route asks for exactly the power the page exercises, and the rail
            entry in nav.ts carries the same claim. The route and the rail must
            never disagree: a rail that hides the entry while the route still
            admits a typed URL is a screen nobody can find and anybody can
            reach.

            Unlike Records next door, this is deliberately NOT narrowed to the
            super admin. `permit.view_all` is held by BPLO and the five
            clearance offices too, and each of them lands on a table scoped to
            the certificates their own office issued — which is a screen those
            offices have a real use for, not a console they were handed by
            accident. The Records comment argues the opposite for Records, and
            the difference is the payload: that screen's three tabs reach
            registers an office has no business browsing, whereas this one
            cannot show a reader a permit they could not already open.
          */}
          <Route
            path="/staff/admin/permits"
            element={
              <RequirePermission permission="permit.view_all">
                <AdminPermitsPage />
              </RequirePermission>
            }
          />
          {/*
            The register on a map, coloured by permit state (issue #104).

            `user.manage` — the same claim its rail entry in nav.ts carries, and
            the same stand-in Records next door makes for "this is the super
            admin". `permit.view_all` reads like the better fit and is the wrong
            gate here for the reason the Permits route above is right to use it
            and this one is not: that table shows a reader only certificates
            they could already open, scoped to their own office, while this map
            plots EVERY business in the city at once. There is no per-office
            version of a city-wide plot, so the office-separability boundary
            (AGENTS.md §10) says it belongs to the one role that is allowed to
            see across all of them.
          */}
          <Route
            path="/staff/admin/business-map"
            element={
              <RequirePermission permission="user.manage">
                <BusinessMapPage />
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
          <Route
            path="/staff/admin/import"
            element={
              <RequirePermission permission="data.import">
                <ImportPage />
              </RequirePermission>
            }
          />
          {/* The Debug page's staff-tree twin; see the admin route below. */}
          <Route
            path="/staff/admin/debug"
            element={
              <RequireDebugAccess>
                <DebugPage />
              </RequireDebugAccess>
            }
          />
        </Route>

        {/*
          ── The super admin's site [checklist item #107] ────────────────────
          The third portal, at /admin. Its own sign-in door above, its own
          token key (`biztrack.token.admin`), its own tree here — so an
          administrator tab and an officer tab can be open at once in one
          browser, which sharing the staff key made impossible.

          `/admin/*` used to be a shim redirecting to /staff/dashboard, for
          links made before the portal split. It is gone: every address it
          caught — /admin/users, /admin/records, /admin/audit-logs — is now a
          real screen at that exact path, so an old link lands on the screen it
          named rather than on the dashboard.

          ── Why the /staff/admin/* routes above are NOT removed ─────────────

          They are not all the super admin's. /staff/admin/permits is gated on
          `permit.view_all`, which BPLO and the five clearance offices hold, and
          it shows each of them their own office's certificates — a staff screen
          that happens to sit under an "admin" segment of the path. Deleting the
          branch would take that screen away from six offices to tidy a prefix.

          The consequence, stated so it is not discovered: a screen only the
          super admin can reach needs a route in BOTH trees until the
          /staff/admin/* segment is renamed. A new one added only above is
          invisible to the administrator — their rail addresses /admin/… and
          nothing would match. `navItemsFor` builds those hrefs from the same
          list for all three portals, so the rail is the thing to check.
        */}
        <Route
          element={
            <RequireAuth>
              <AppShell />
            </RequireAuth>
          }
        >
          {/* The chrome every portal carries: the rail's Home, the bell, and
              the avatar flyout's two entries. Mounted per-prefix for the reason
              given on the staff tree — a single shared copy would have to sit
              outside the prefix, which the portal split cannot allow. */}
          <Route path="/admin/dashboard" element={<DashboardPage />} />
          {/*
            * ── /admin/messages is not a screen the super admin can open ────
            *
            * It was `<MessagesPage />`, mounted per-prefix alongside Profile,
            * Settings and Notifications. Only the super admin lives under
            * `/admin`, and the super admin does not hold `message.participate`
            * — so this route could only ever render "We couldn't load this.
            * You do not have permission to perform this action." Every time.
            *
            * Redirected rather than deleted, because links to it already
            * exist: the rail pointed here until this was found, notification
            * rows written before the fix still carry the path, and a reader
            * may well have bookmarked it. `replace` keeps it out of the
            * history so Back does not bounce between the two.
            */}
          <Route path="/admin/messages" element={<Navigate to="/admin/office-messages" replace />} />
          <Route path="/admin/notifications" element={<NotificationsPage />} />
          <Route path="/admin/profile" element={<ProfilePage />} />
          <Route path="/admin/settings" element={<SettingsPage />} />
          {/*
            The analytics dashboard, in the admin tree. The super admin holds
            `analytics.view` since checklist 2026-09-27 item 1 and, like BPLO,
            may switch office or view all. It used to be absent here because
            the admin was forbidden from it; that reason is gone.
          */}
          <Route
            path="/admin/analytics"
            element={
              <RequirePermission permission="analytics.view">
                <AnalyticsPage />
              </RequirePermission>
            }
          />
          <Route
            path="/admin/analytics/reports"
            element={
              <RequirePermission permission="analytics.view">
                <ReportsPage />
              </RequirePermission>
            }
          />
          <Route
            path="/admin/analytics/processing-time"
            element={
              <RequirePermission permission="analytics.processing_time">
                <ProcessingTimePage />
              </RequirePermission>
            }
          />
          {/*
            * Office Performance, in the admin tree. The rail sends the super
            * admin here (nav.ts toByPermission → /analytics/offices), and until
            * this route existed the admin site answered it with NotFound — the
            * staff tree had it, the admin tree did not, which is the exact trap
            * the comment above this block warns about.
            */}
          <Route
            path="/admin/analytics/offices"
            element={
              <RequirePermission permission="analytics.processing_time">
                <OfficePerformancePage />
              </RequirePermission>
            }
          />
          {/* Each route carries the SAME permission as its twin in the staff
              tree above, and the same one as its rail entry in nav.ts. See the
              notes there for why each is the permission it is — they are not
              obvious, and Records in particular is `user.manage` standing in
              for a "this is the super admin" check the table cannot express. */}
          <Route
            path="/admin/users"
            element={
              <RequirePermission permission="user.manage">
                <UsersPage />
              </RequirePermission>
            }
          />
          <Route
            path="/admin/users/:userId/reassign"
            element={
              /*
                One officer's caseload, in the Officer in Charge format.

                Guarded on `oic.assign` rather than the `user.manage` that
                opens the directory it is reached from: moving a filing
                between officers is the OIC act, and the two permissions come
                apart — the Reassign link itself is already hidden without it
                (see UsersPage), and a route that trusted the link would be a
                guard that only exists in the markup.
              */
              <RequirePermission permission="oic.assign">
                <OfficerCaseloadPage />
              </RequirePermission>
            }
          />
          {/* The one the super admin actually reaches; see the note on the
              /staff copy above for why it is not called `messages`. */}
          <Route
            path="/admin/office-messages"
            element={
              <RequirePermission permission="user.manage">
                <StaffMessagesPage />
              </RequirePermission>
            }
          />
          <Route
            path="/admin/owners"
            element={
              <RequirePermission permission="owner.manage_status">
                <OwnersPage />
              </RequirePermission>
            }
          />
          <Route
            path="/admin/oic"
            element={
              <RequirePermission permission="oic.assign">
                <OicPage />
              </RequirePermission>
            }
          />
          <Route
            path="/admin/records"
            element={
              <RequirePermission permission="user.manage">
                <RecordsPage />
              </RequirePermission>
            }
          />
          <Route
            path="/admin/permits"
            element={
              <RequirePermission permission="permit.view_all">
                <AdminPermitsPage />
              </RequirePermission>
            }
          />
          <Route
            path="/admin/business-map"
            element={
              <RequirePermission permission="user.manage">
                <BusinessMapPage />
              </RequirePermission>
            }
          />
          <Route
            path="/admin/audit-logs"
            element={
              <RequirePermission permission="audit.view">
                <AuditLogsPage />
              </RequirePermission>
            }
          />
          {/* Importing the old register — `data.import`, the super admin's alone. */}
          <Route
            path="/admin/import"
            element={
              <RequirePermission permission="data.import">
                <ImportPage />
              </RequirePermission>
            }
          />
          {/*
            Debug: the super admin's on-the-fly controls for the defense
            [Ken, 2026-10-04]. Its own guard rather than RequirePermission,
            because no permission says it: the server opens it to the super
            admin for a few hours at a time, and pages/admin/debug/access.ts
            reads that verdict for this route and the rail entry alike.
          */}
          <Route
            path="/admin/debug"
            element={
              <RequireDebugAccess>
                <DebugPage />
              </RequireDebugAccess>
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
        {/*
          The address Ken asked for. Bare paths are the owners' site, which the
          super admin is never signed into, so this hands the address to the
          admin site instead of to the citizen sign-in page.
        */}
        <Route path="/debug" element={<Navigate to="/admin/debug" replace />} />

        <Route path="*" element={<NotFoundRedirect />} />
      </Routes>
    </BrowserRouter>
  )
}
