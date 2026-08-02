import { useEffect } from 'react'
import type { ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom'
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
import { loginPathFor } from './lib/api'
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
  const { user } = useAuth()
  if (!user?.permissions.includes(permission)) return <Navigate to="/dashboard" replace />
  return children
}

/** Signed-in users skip the auth screens. */
function RedirectIfAuthed({ children }: { children: ReactNode }) {
  const { user, bootstrapped } = useAuth()
  if (!bootstrapped) return <FullPageSpinner />
  if (user) return <Navigate to="/dashboard" replace />
  return children
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
          <Route path="/drafts" element={<DraftsPage />} />
          <Route path="/payments" element={<PaymentsPage />} />
          <Route path="/permits" element={<PermitsPage />} />
          <Route path="/permits/:id" element={<PermitDetailPage />} />
          <Route path="/messages" element={<MessagesPage />} />
          <Route path="/notifications" element={<NotificationsPage />} />
          <Route path="/profile" element={<ProfilePage />} />
          <Route path="/settings" element={<SettingsPage />} />
          <Route path="/requests" element={<RequestsPage />} />
          {/* Officer */}
          <Route
            path="/queue"
            element={
              <RequirePermission permission="application.review">
                <QueuePage />
              </RequirePermission>
            }
          />
          <Route
            path="/queue/:id"
            element={
              <RequirePermission permission="application.review">
                <ReviewPage />
              </RequirePermission>
            }
          />
          <Route
            path="/inspections"
            element={
              <RequirePermission permission="inspection.manage">
                <InspectionsPage />
              </RequirePermission>
            }
          />
          <Route
            path="/inspections/:id"
            element={
              <RequirePermission permission="inspection.manage">
                <InspectionDetailPage />
              </RequirePermission>
            }
          />
          {/* Admin */}
          <Route
            path="/analytics"
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
            path="/analytics/processing-time"
            element={
              <RequirePermission permission="analytics.view">
                <ProcessingTimePage />
              </RequirePermission>
            }
          />
          <Route
            path="/analytics/business-growth"
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
            path="/analytics/renewal-risk"
            element={
              <RequirePermission permission="analytics.view">
                <RenewalRiskPage />
              </RequirePermission>
            }
          />
          <Route
            path="/admin/users"
            element={
              <RequirePermission permission="user.manage">
                <UsersPage />
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
            path="/admin/audit-logs"
            element={
              <RequirePermission permission="audit.view">
                <AuditLogsPage />
              </RequirePermission>
            }
          />
        </Route>
        <Route path="*" element={<Navigate to="/login" replace />} />
      </Routes>
    </BrowserRouter>
  )
}
