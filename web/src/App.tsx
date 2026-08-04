import { useEffect } from 'react'
import type { ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation, useParams } from 'react-router-dom'
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
import { InspectionsPage, InspectionDetailPage } from './pages/officer/InspectionsPage'
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
          <Route
            path="/staff/inspections"
            element={
              <RequirePermission permission="inspection.manage">
                <InspectionsPage />
              </RequirePermission>
            }
          />
          <Route
            path="/staff/inspections/:id"
            element={
              <RequirePermission permission="inspection.manage">
                <InspectionDetailPage />
              </RequirePermission>
            }
          />
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
            Features 6/7 came out of the standalone R project and into the site.
            Same permission as the overview: analytics.view, held by the super
            admin and by BPLO (checklist #78). These aggregate every office's
            queue, which is why the other office roles still do not hold it.
          */}
          <Route
            path="/staff/analytics/processing-time"
            element={
              <RequirePermission permission="analytics.view">
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
            so it sits on the same permission as the rest of analytics rather
            than being visible to every office reviewer.
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
        <Route path="/inspections" element={<MovedToStaff path="/inspections" />} />
        <Route path="/inspections/:id" element={<MovedToStaff path="/inspections" />} />
        <Route path="/analytics/*" element={<Navigate to="/staff/analytics" replace />} />
        <Route path="/admin/*" element={<Navigate to="/staff/dashboard" replace />} />

        <Route path="*" element={<NotFoundRedirect />} />
      </Routes>
    </BrowserRouter>
  )
}
