import { useState } from 'react'
import type { SVGProps } from 'react'
import { ChevronRightIcon } from '../components/icons'
import { PasswordInput } from '../components/ui/PasswordInput'
import { FieldLabel, PageTitle, ProtoModal, inputCls } from '../components/ui/Proto'
import { api, toApiError } from '../lib/api'
import type { User } from '../lib/types'
import { useAuth } from '../stores/auth'

/* Settings — PDF p11–13: two royal bars opening the Edit Profile / Change Password modals. */

function PencilIcon({ size = 18, ...props }: SVGProps<SVGSVGElement> & { size?: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.75}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      {...props}
    >
      <path d="M11 4.5H6A1.5 1.5 0 0 0 4.5 6v12A1.5 1.5 0 0 0 6 19.5h12a1.5 1.5 0 0 0 1.5-1.5v-5" />
      <path d="M17.8 3.7a2 2 0 0 1 2.8 2.8L13 14.1l-3.7.7.7-3.6 7.8-7.5Z" />
    </svg>
  )
}

/** Gray avatar circle with the royal ring, per the Edit Profile modal (PDF p12). */
function ProfileAvatar() {
  return (
    <span
      aria-hidden="true"
      className="flex h-24 w-24 items-center justify-center overflow-hidden rounded-full border-4 border-royal bg-line"
    >
      <svg viewBox="0 0 24 24" className="mt-4 h-20 w-20 fill-ink-muted">
        <circle cx="12" cy="8" r="4" />
        <path d="M12 13.5c-4.4 0-7 2.6-7 6.5h14c0-3.9-2.6-6.5-7-6.5Z" />
      </svg>
    </span>
  )
}

/** Full-width royal bar row (PDF p11). */
function SettingsBar({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="flex w-full items-center justify-between rounded-md bg-royal px-8 py-9 text-left shadow-card transition-colors hover:bg-royal-hover"
    >
      <span className="text-xl font-bold text-white">{label}</span>
      <ChevronRightIcon size={30} className="text-white" strokeWidth={2.25} />
    </button>
  )
}

function FieldError({ id, message }: { id: string; message?: string }) {
  if (!message) return null
  return (
    <p id={id} className="mt-1.5 text-sm font-medium text-s-red">
      {message}
    </p>
  )
}

type OpenModal = 'profile' | 'password' | null

export function SettingsPage() {
  const user = useAuth((s) => s.user)
  const setUser = useAuth((s) => s.setUser)
  const [open, setOpen] = useState<OpenModal>(null)
  const [note, setNote] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  const [firstName, setFirstName] = useState(user?.first_name ?? '')
  const [lastName, setLastName] = useState(user?.last_name ?? '')
  const [phone, setPhone] = useState(user?.mobile_number ?? '')
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')

  function openProfile() {
    setFirstName(user?.first_name ?? '')
    setLastName(user?.last_name ?? '')
    setPhone(user?.mobile_number ?? '')
    setNote(null)
    setFormError(null)
    setFieldErrors({})
    setOpen('profile')
  }

  function openPassword() {
    setCurrentPassword('')
    setPassword('')
    setConfirm('')
    setNote(null)
    setFormError(null)
    setFieldErrors({})
    setOpen('password')
  }

  async function saveProfile() {
    setSaving(true)
    setFormError(null)
    setFieldErrors({})
    try {
      const { data } = await api.put<{ data: User }>('/auth/profile', {
        first_name: firstName.trim(),
        last_name: lastName.trim(),
        mobile_number: phone.trim(),
      })
      setUser(data.data)
      setOpen(null)
      setNote('Profile changes saved.')
    } catch (error) {
      const apiError = toApiError(error)
      setFieldErrors(apiError.errors)
      if (Object.keys(apiError.errors).length === 0) setFormError(apiError.message)
    } finally {
      setSaving(false)
    }
  }

  async function savePassword() {
    setSaving(true)
    setFormError(null)
    setFieldErrors({})
    try {
      await api.put('/auth/password', {
        current_password: currentPassword,
        password,
        password_confirmation: confirm,
      })
      setOpen(null)
      setNote('Password updated. Any other signed-in devices have been logged out.')
    } catch (error) {
      const apiError = toApiError(error)
      setFieldErrors(apiError.errors)
      if (Object.keys(apiError.errors).length === 0) setFormError(apiError.message)
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <PageTitle>Settings</PageTitle>

      {note && (
        <p role="status" className="mb-4 text-sm font-semibold text-s-green">
          {note}
        </p>
      )}

      <div className="flex flex-col gap-6">
        <SettingsBar label="Edit Profile" onClick={openProfile} />
        <SettingsBar label="Reset Password" onClick={openPassword} />
      </div>

      {open === 'profile' && (
        <ProtoModal
          title="Edit Profile"
          cancelLabel="Cancel"
          confirmLabel={saving ? 'Saving…' : 'Save Changes'}
          onCancel={() => setOpen(null)}
          onConfirm={saveProfile}
          confirmDisabled={saving || !firstName.trim() || !lastName.trim() || !phone.trim()}
        >
          <div className="flex flex-col items-center gap-2">
            <ProfileAvatar />
            <button type="button" className="inline-flex items-center gap-2 text-base text-ink hover:underline">
              Edit Profile Picture <PencilIcon className="text-royal" />
            </button>
          </div>
          {formError && (
            <p role="alert" className="mt-4 text-center text-sm font-medium text-s-red">
              {formError}
            </p>
          )}
          <div className="mt-6 grid gap-5 sm:grid-cols-2">
            <div>
              <FieldLabel>First Name</FieldLabel>
              <div className="relative">
                <input
                  value={firstName}
                  onChange={(e) => setFirstName(e.target.value)}
                  placeholder="First Name"
                  aria-invalid={fieldErrors.first_name ? true : undefined}
                  aria-describedby={fieldErrors.first_name ? 'profile-first-error' : undefined}
                  className={`${inputCls} pr-10`}
                />
                <PencilIcon size={16} className="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-royal" />
              </div>
              <FieldError id="profile-first-error" message={fieldErrors.first_name?.[0]} />
            </div>
            <div>
              <FieldLabel>Last Name</FieldLabel>
              <div className="relative">
                <input
                  value={lastName}
                  onChange={(e) => setLastName(e.target.value)}
                  placeholder="Last Name"
                  aria-invalid={fieldErrors.last_name ? true : undefined}
                  aria-describedby={fieldErrors.last_name ? 'profile-last-error' : undefined}
                  className={`${inputCls} pr-10`}
                />
                <PencilIcon size={16} className="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-royal" />
              </div>
              <FieldError id="profile-last-error" message={fieldErrors.last_name?.[0]} />
            </div>
            <div>
              <FieldLabel>Mobile Number</FieldLabel>
              <div className="relative">
                <input
                  value={phone}
                  onChange={(e) => setPhone(e.target.value)}
                  placeholder="09XX XXX XXXX"
                  inputMode="tel"
                  autoComplete="tel"
                  aria-invalid={fieldErrors.mobile_number ? true : undefined}
                  aria-describedby={fieldErrors.mobile_number ? 'profile-phone-error' : undefined}
                  className={`${inputCls} pr-10`}
                />
                <PencilIcon size={16} className="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-royal" />
              </div>
              <FieldError id="profile-phone-error" message={fieldErrors.mobile_number?.[0]} />
            </div>
            <div>
              <FieldLabel>Email</FieldLabel>
              <input value={user?.email ?? ''} readOnly aria-readonly="true" className={`${inputCls} bg-canvas text-ink-muted`} />
              <p className="mt-1.5 text-xs text-ink-muted">
                Your email is your sign-in ID and can't be changed here. Contact the City BPLO to update it.
              </p>
            </div>
          </div>
        </ProtoModal>
      )}

      {open === 'password' && (
        <ProtoModal
          title="Change Password"
          cancelLabel="Cancel"
          confirmLabel={saving ? 'Saving…' : 'Save Changes'}
          onCancel={() => setOpen(null)}
          onConfirm={savePassword}
          confirmDisabled={saving || !currentPassword || password.length < 8 || password !== confirm}
        >
          {formError && (
            <p role="alert" className="mb-4 text-center text-sm font-medium text-s-red">
              {formError}
            </p>
          )}
          <div className="grid gap-5 py-4 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <FieldLabel>Current Password</FieldLabel>
              <PasswordInput
                id="settings-current"
                placeholder="Current Password"
                value={currentPassword}
                onChange={setCurrentPassword}
                autoComplete="current-password"
                invalid={!!fieldErrors.current_password}
                describedBy={fieldErrors.current_password ? 'settings-current-error' : undefined}
                iconSize={18}
              />
              <FieldError id="settings-current-error" message={fieldErrors.current_password?.[0]} />
            </div>
            <div>
              <FieldLabel>Enter New Password</FieldLabel>
              <PasswordInput
                id="settings-password"
                placeholder="Password"
                value={password}
                onChange={setPassword}
                invalid={!!fieldErrors.password}
                describedBy={fieldErrors.password ? 'settings-password-error' : undefined}
                iconSize={18}
              />
              <FieldError id="settings-password-error" message={fieldErrors.password?.[0]} />
            </div>
            <div>
              <FieldLabel>Confirm New Password</FieldLabel>
              <PasswordInput
                id="settings-confirm"
                placeholder="Confirm Password"
                value={confirm}
                onChange={setConfirm}
                iconSize={18}
              />
            </div>
          </div>
          <p className="text-center text-xs text-ink-muted">
            At least 8 characters. Both fields must match to save. Saving signs out your other devices.
          </p>
        </ProtoModal>
      )}
    </div>
  )
}
