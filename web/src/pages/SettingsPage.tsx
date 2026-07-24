import { useState } from 'react'
import type { SVGProps } from 'react'
import { ChevronRightIcon, EyeIcon, EyeOffIcon } from '../components/icons'
import { FieldLabel, PageTitle, ProtoModal, inputCls } from '../components/ui/Proto'
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

/** Filled password input with the prototype's eye toggle (PDF p13). */
function PasswordInput({ id, placeholder, value, onChange }: {
  id: string
  placeholder: string
  value: string
  onChange: (v: string) => void
}) {
  const [visible, setVisible] = useState(false)
  return (
    <div className="relative">
      <input
        id={id}
        type={visible ? 'text' : 'password'}
        autoComplete="new-password"
        placeholder={placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className={`${inputCls} pr-11`}
      />
      <button
        type="button"
        onClick={() => setVisible((v) => !v)}
        aria-label={visible ? 'Hide password' : 'Show password'}
        aria-pressed={visible}
        className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-ink-secondary hover:text-ink"
      >
        {visible ? <EyeOffIcon size={18} /> : <EyeIcon size={18} />}
      </button>
    </div>
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

type OpenModal = 'profile' | 'password' | null

export function SettingsPage() {
  const user = useAuth((s) => s.user)
  const [open, setOpen] = useState<OpenModal>(null)
  const [note, setNote] = useState<string | null>(null)

  const [firstName, setFirstName] = useState(user?.first_name ?? '')
  const [lastName, setLastName] = useState(user?.last_name ?? '')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')

  function openProfile() {
    setFirstName(user?.first_name ?? '')
    setLastName(user?.last_name ?? '')
    setNote(null)
    setOpen('profile')
  }

  function openPassword() {
    setPassword('')
    setConfirm('')
    setNote(null)
    setOpen('password')
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
          confirmLabel="Save Changes"
          onCancel={() => setOpen(null)}
          onConfirm={() => {
            setOpen(null)
            setNote('Profile changes saved.')
          }}
          confirmDisabled={!firstName.trim() || !lastName.trim()}
        >
          <div className="flex flex-col items-center gap-2">
            <ProfileAvatar />
            <button type="button" className="inline-flex items-center gap-2 text-base text-ink hover:underline">
              Edit Profile Picture <PencilIcon className="text-royal" />
            </button>
          </div>
          <div className="mt-6 grid gap-5 sm:grid-cols-2">
            <div>
              <FieldLabel>First Name</FieldLabel>
              <div className="relative">
                <input
                  value={firstName}
                  onChange={(e) => setFirstName(e.target.value)}
                  placeholder="First Name"
                  className={`${inputCls} pr-10`}
                />
                <PencilIcon size={16} className="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-royal" />
              </div>
            </div>
            <div>
              <FieldLabel>Last Name</FieldLabel>
              <div className="relative">
                <input
                  value={lastName}
                  onChange={(e) => setLastName(e.target.value)}
                  placeholder="Last Name"
                  className={`${inputCls} pr-10`}
                />
                <PencilIcon size={16} className="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-royal" />
              </div>
            </div>
          </div>
        </ProtoModal>
      )}

      {open === 'password' && (
        <ProtoModal
          title="Change Password"
          cancelLabel="Cancel"
          confirmLabel="Save Changes"
          onCancel={() => setOpen(null)}
          onConfirm={() => {
            setOpen(null)
            setNote('Password changes saved.')
          }}
          confirmDisabled={password.length < 8 || password !== confirm}
        >
          <div className="grid gap-5 py-4 sm:grid-cols-2">
            <div>
              <FieldLabel>Enter New Password</FieldLabel>
              <PasswordInput id="settings-password" placeholder="Password" value={password} onChange={setPassword} />
            </div>
            <div>
              <FieldLabel>Confirm New Password</FieldLabel>
              <PasswordInput id="settings-confirm" placeholder="Confirm Password" value={confirm} onChange={setConfirm} />
            </div>
          </div>
          <p className="text-center text-xs text-ink-muted">
            At least 8 characters. Both fields must match to save.
          </p>
        </ProtoModal>
      )}
    </div>
  )
}
