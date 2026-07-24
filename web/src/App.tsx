import { useEffect } from 'react'
import type { ReactNode } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { AppShell } from './components/AppShell'
import { Spinner } from './components/icons'
import { DashboardPage } from './pages/DashboardPage'
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
import { UsersPage } from './pages/admin/UsersPage'
import { AuditLogsPage } from './pages/admin/AuditLogsPage'
import { OwnersPage } from './pages/admin/OwnersPage'
import { RequestsPage } from './pages/RequestsPage'
import { SettingsPage } from './pages/SettingsPage'
import { useAuth } from './stores/auth'

function FullPageSpinner() {
  return (
    <div className="flex min-h-dvh items-center justify-center" role="status" aria-label="Loading BizTrack">
      <Spinner size={28} className="text-blue-600" />
    </div>
  )
}

function RequireAuth({ children }: { children: ReactNode }) {
  const { user, bootstrapped } = useAuth()
  const location = useLocation()
  if (!bootstrapped) return <FullPageSpinner />
  if (!user) return <Navigate to="/login" state={{ from: location.pathname }} replace />
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
          <Route path="/notifications" element={<NotificationsPage />} />
          <Route path="/settings" element={<SettingsPage />} />
          <Route path="/requests" element={<RequestsPage />} />
          {/* Officer */}
          <Route path="/queue" element={<QueuePage />} />
          <Route path="/queue/:id" element={<ReviewPage />} />
          <Route path="/inspections" element={<InspectionsPage />} />
          <Route path="/inspections/:id" element={<InspectionDetailPage />} />
          {/* Admin */}
          <Route path="/analytics" element={<AnalyticsPage />} />
          <Route path="/admin/users" element={<UsersPage />} />
          <Route path="/admin/owners" element={<OwnersPage />} />
          <Route path="/admin/audit-logs" element={<AuditLogsPage />} />
        </Route>
        <Route path="*" element={<Navigate to="/login" replace />} />
      </Routes>
    </BrowserRouter>
  )
}
