import { useRef, useState } from 'react'
import type { ChangeEvent, ReactNode, SVGProps } from 'react'
import { ChevronRightIcon } from '../components/icons'
import { PasswordInput } from '../components/ui/PasswordInput'
import { FieldLabel, PageTitle, ProtoModal, inputCls } from '../components/ui/Proto'
import { api, toApiError } from '../lib/api'
import { PHOTO_ACCEPT_ATTR, photoRejection, profilePhoto } from '../lib/resources'
import type { User } from '../lib/types'
import { useProfilePhoto } from '../lib/useProfilePhoto'
import { MOBILE_DIGITS, validateMobile } from '../lib/validation'
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

/**
 * Gray avatar circle with the royal ring, per the Edit Profile modal (PDF p12),
 * showing the uploaded photo once there is one.
 *
 * The glyph keeps `aria-hidden`: it is decoration beside the name it sits
 * under. A real photo gets an empty alt for the same reason — "profile photo"
 * read aloud next to the fields that spell out whose profile this is would be
 * the stacked restatement the copy rules warn against.
 */
function ProfileAvatar({ src }: { src?: string | null }) {
  if (src) {
    return (
      <img
        src={src}
        alt=""
        className="h-24 w-24 rounded-full border-4 border-royal object-cover"
      />
    )
  }
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
      // rounded-2xl to match the identical royal bar on Profile that links here,
      // and the shadow cards it sits among elsewhere.
      className="flex w-full items-center justify-between rounded-2xl bg-royal px-8 py-9 text-left shadow-card transition-colors hover:bg-royal-hover"
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

/*
 * One labelled control in the Edit Profile grid.
 *
 * FieldLabel renders a styled span, so on its own it captions a field without
 * naming it — a screen reader reaching the input announces "edit text" and
 * nothing else. Wrapping it in a real `label` with `htmlFor` is what gives each
 * input its accessible name (WCAG 2.1 AA, 3.3.2 Labels or Instructions).
 */
function ProfileField({
  id,
  label,
  error,
  hint,
  required,
  children,
}: {
  id: string
  label: string
  error?: string
  /** Shown under the control — used to explain why email is not editable. */
  hint?: string
  /*
   * Marks the label with the asterisk FieldLabel already draws for required
   * answers across the apply wizard and the office forms. It must agree with
   * two other things or it is a lie: `confirmDisabled` on the modal below, and
   * the `required` rules in AuthController::updateProfile. Gender is
   * deliberately NOT one of these — it is nullable on both sides so that
   * accounts predating the column can still save an unrelated name edit.
   */
  required?: boolean
  children: ReactNode
}) {
  return (
    <div>
      <label htmlFor={id}>
        <FieldLabel required={required}>{label}</FieldLabel>
      </label>
      {children}
      {hint && <p className="mt-1.5 text-xs text-ink-muted">{hint}</p>}
      <FieldError id={`${id}-error`} message={error} />
    </div>
  )
}

/** The pencil affordance the PDF puts inside each editable profile input. */
function InputPencil() {
  return (
    <PencilIcon
      size={16}
      className="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-royal"
    />
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

  /*
   * Every name part registration collects, because Profile prints all of them.
   * The screen renders first + middle + last + suffix; a modal that only edited
   * first and last showed the applicant a name they had no way to correct,
   * which is checklist item 74. Gender is here for the same reason: sign-up
   * requires it, so leaving it out made it the one answer nobody could revise.
   */
  const [firstName, setFirstName] = useState(user?.first_name ?? '')
  const [middleName, setMiddleName] = useState(user?.middle_name ?? '')
  const [lastName, setLastName] = useState(user?.last_name ?? '')
  const [suffix, setSuffix] = useState(user?.suffix ?? '')
  const [gender, setGender] = useState<string>(user?.gender ?? '')
  const [phone, setPhone] = useState(user?.mobile_number ?? '')
  /*
   * The mobile number complains on blur, not on every keystroke. Typing the
   * third digit of a number that will be eleven is not a mistake, and an error
   * that appears while someone is still answering trains them to ignore it.
   */
  const [phoneTouched, setPhoneTouched] = useState(false)
  /*
   * validateMobile is the same check registration runs, so one number is not
   * acceptable at sign-up and refused here. It also names the actual defect —
   * "that number is 9 digits long, but a mobile number needs 11" — rather than
   * restating the format and leaving the reader to spot the difference.
   */
  const phoneError = phoneTouched ? validateMobile(phone) : undefined
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')

  /*
   * The photo is its own small transaction, not part of Save Changes. It
   * uploads the moment a file is chosen and the modal's Cancel does not undo
   * it — which is why "Remove Photo" exists rather than being left to Cancel.
   * Bundling it into saveProfile instead would mean a name edit abandoned
   * halfway also silently discarded the picture.
   */
  const fileInput = useRef<HTMLInputElement>(null)
  const [photoVersion, setPhotoVersion] = useState(0)
  const [photoBusy, setPhotoBusy] = useState(false)
  const [photoError, setPhotoError] = useState<string | null>(null)
  const photoUrl = useProfilePhoto(user?.has_photo ?? false, photoVersion)

  async function choosePhoto(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]
    // Clear immediately: without this, picking the same file twice after a
    // failure fires no change event and the retry looks like a dead control.
    event.target.value = ''
    if (!file) return

    const rejection = photoRejection(file)
    if (rejection) {
      setPhotoError(rejection)
      return
    }

    setPhotoBusy(true)
    setPhotoError(null)
    try {
      setUser(await profilePhoto.upload(file))
      setPhotoVersion((v) => v + 1)
    } catch (error) {
      setPhotoError(toApiError(error).message)
    } finally {
      setPhotoBusy(false)
    }
  }

  async function removePhoto() {
    setPhotoBusy(true)
    setPhotoError(null)
    try {
      setUser(await profilePhoto.remove())
      setPhotoVersion((v) => v + 1)
    } catch (error) {
      setPhotoError(toApiError(error).message)
    } finally {
      setPhotoBusy(false)
    }
  }

  function openProfile() {
    setFirstName(user?.first_name ?? '')
    setMiddleName(user?.middle_name ?? '')
    setLastName(user?.last_name ?? '')
    setSuffix(user?.suffix ?? '')
    setGender(user?.gender ?? '')
    setPhone(user?.mobile_number ?? '')
    setPhoneTouched(false)
    setNote(null)
    setFormError(null)
    setPhotoError(null)
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
      /*
       * Optional parts are sent even when blank, on purpose. An empty string
       * reaches the API as null and clears the stored value — which is the only
       * way to take a middle name or suffix back off once it has been saved.
       */
      const { data } = await api.put<{ data: User }>('/auth/profile', {
        first_name: firstName.trim(),
        middle_name: middleName.trim(),
        last_name: lastName.trim(),
        suffix: suffix.trim(),
        gender,
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
          // Seven fields now rather than four; the narrow panel squeezed the
          // two-column grid to about 14 characters per input.
          wide
          cancelLabel="Cancel"
          confirmLabel={saving ? 'Saving…' : 'Save Changes'}
          onCancel={() => setOpen(null)}
          onConfirm={saveProfile}
          confirmDisabled={saving || !firstName.trim() || !lastName.trim() || !!validateMobile(phone)}
        >
          <div className="flex flex-col items-center gap-2">
            <ProfileAvatar src={photoUrl} />
            {/*
              The real control is this input; the button below is what the
              mockup draws. It stays in the DOM rather than being rendered on
              demand so the click handler always has something to open, and it
              is hidden with `sr-only` rather than `hidden` so a keyboard user
              tabbing through still meets a labelled file control.
            */}
            <input
              ref={fileInput}
              type="file"
              accept={PHOTO_ACCEPT_ATTR}
              onChange={choosePhoto}
              className="sr-only"
              aria-label="Choose a profile picture"
            />
            <button
              type="button"
              onClick={() => {
                if (photoBusy) return
                setPhotoError(null)
                fileInput.current?.click()
              }}
              // aria-disabled, never `disabled`: a disabled control drops out
              // of the tab order, taking the only explanation of why it cannot
              // be used with it.
              aria-disabled={photoBusy || undefined}
              className={`inline-flex items-center gap-2 text-base text-ink hover:underline ${
                photoBusy ? 'opacity-60' : ''
              }`}
            >
              {photoBusy ? 'Uploading…' : 'Edit Profile Picture'}
              <PencilIcon className="text-royal" />
            </button>
            {user?.has_photo && (
              <button
                type="button"
                onClick={() => {
                  if (!photoBusy) void removePhoto()
                }}
                aria-disabled={photoBusy || undefined}
                className="text-sm font-medium text-ink-muted hover:text-ink hover:underline"
              >
                Remove Photo
              </button>
            )}
            <p className="text-xs text-ink-muted">JPG or PNG, up to 5 MB.</p>
            {photoError && (
              <p role="alert" className="text-center text-sm font-medium text-s-red">
                {photoError}
              </p>
            )}
          </div>
          {formError && (
            <p role="alert" className="mt-4 text-center text-sm font-medium text-s-red">
              {formError}
            </p>
          )}
          <div className="mt-6 grid gap-5 sm:grid-cols-2">
            <ProfileField id="profile-first" label="First Name" required error={fieldErrors.first_name?.[0]}>
              <div className="relative">
                <input
                  id="profile-first"
                  value={firstName}
                  onChange={(e) => setFirstName(e.target.value)}
                  autoComplete="given-name"
                  /* aria-required, not the `required` attribute: the modal saves
                     through a button, not a form submit, so native validation
                     would never fire — but a screen reader still has to hear
                     what the asterisk shows. */
                  aria-required="true"
                  aria-invalid={fieldErrors.first_name ? true : undefined}
                  aria-describedby={fieldErrors.first_name ? 'profile-first-error' : undefined}
                  className={`${inputCls} pr-10`}
                />
                <InputPencil />
              </div>
            </ProfileField>
            <ProfileField id="profile-middle" label="Middle Name" error={fieldErrors.middle_name?.[0]}>
              <div className="relative">
                <input
                  id="profile-middle"
                  value={middleName}
                  onChange={(e) => setMiddleName(e.target.value)}
                  placeholder="Leave blank if none"
                  autoComplete="additional-name"
                  aria-invalid={fieldErrors.middle_name ? true : undefined}
                  aria-describedby={fieldErrors.middle_name ? 'profile-middle-error' : undefined}
                  className={`${inputCls} pr-10`}
                />
                <InputPencil />
              </div>
            </ProfileField>
            <ProfileField id="profile-last" label="Last Name" required error={fieldErrors.last_name?.[0]}>
              <div className="relative">
                <input
                  id="profile-last"
                  value={lastName}
                  onChange={(e) => setLastName(e.target.value)}
                  autoComplete="family-name"
                  aria-required="true"
                  aria-invalid={fieldErrors.last_name ? true : undefined}
                  aria-describedby={fieldErrors.last_name ? 'profile-last-error' : undefined}
                  className={`${inputCls} pr-10`}
                />
                <InputPencil />
              </div>
            </ProfileField>
            <ProfileField id="profile-suffix" label="Suffix" error={fieldErrors.suffix?.[0]}>
              <div className="relative">
                <input
                  id="profile-suffix"
                  value={suffix}
                  onChange={(e) => setSuffix(e.target.value)}
                  placeholder="Jr., Sr., III"
                  autoComplete="honorific-suffix"
                  aria-invalid={fieldErrors.suffix ? true : undefined}
                  aria-describedby={fieldErrors.suffix ? 'profile-suffix-error' : undefined}
                  className={`${inputCls} pr-10`}
                />
                <InputPencil />
              </div>
            </ProfileField>
            <ProfileField id="profile-gender" label="Gender" error={fieldErrors.gender?.[0]}>
              {/*
                Blank is a real option, not a prompt. The column is nullable and
                accounts created before sign-up asked for gender hold null —
                forcing one of the two here would make an unrelated name edit
                impossible to save on those accounts.
              */}
              <select
                id="profile-gender"
                value={gender}
                onChange={(e) => setGender(e.target.value)}
                aria-invalid={fieldErrors.gender ? true : undefined}
                aria-describedby={fieldErrors.gender ? 'profile-gender-error' : undefined}
                className={inputCls}
              >
                <option value="">Not specified</option>
                <option value="M">Male</option>
                <option value="F">Female</option>
              </select>
            </ProfileField>
            <ProfileField
              id="profile-phone"
              label="Mobile Number"
              required
              error={fieldErrors.mobile_number?.[0] ?? phoneError}
            >
              <div className="relative">
                <input
                  id="profile-phone"
                  value={phone}
                  /*
                   * Digits only, eleven at most, enforced as the character is
                   * typed rather than reported afterwards: a field that accepts
                   * a letter and then explains it should not have is a field
                   * that wasted the keystroke. This is what let "09d" reach the
                   * database through this very form.
                   *
                   * Paste is covered too — onChange sees the pasted value — so
                   * "0917 123 4567" off a contact card arrives as 09171234567
                   * instead of being rejected for its spaces.
                   */
                  onChange={(e) => setPhone(e.target.value.replace(/\D/g, '').slice(0, MOBILE_DIGITS))}
                  onBlur={() => setPhoneTouched(true)}
                  // The shape of an answer, never an answer: a specimen number
                  // reads as real and gets submitted as one.
                  placeholder="11 digits, starting 09"
                  maxLength={MOBILE_DIGITS}
                  // numeric, not tel: tel offers + * # on a phone keypad, and
                  // none of them can be entered here.
                  inputMode="numeric"
                  autoComplete="tel"
                  aria-required="true"
                  aria-invalid={fieldErrors.mobile_number || phoneError ? true : undefined}
                  aria-describedby={
                    fieldErrors.mobile_number || phoneError ? 'profile-phone-error' : undefined
                  }
                  className={`${inputCls} pr-10`}
                />
                <InputPencil />
              </div>
            </ProfileField>
            <ProfileField
              id="profile-email"
              label="Email"
              hint="Your email is your sign-in ID and can't be changed here. Contact the City BPLO to update it."
            >
              {/* readOnly, not disabled: a disabled input drops out of the tab
                  order, so a keyboard user can never reach the address to read
                  or copy it. */}
              <input
                id="profile-email"
                value={user?.email ?? ''}
                readOnly
                aria-readonly="true"
                className={`${inputCls} bg-canvas text-ink-muted`}
              />
            </ProfileField>
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
              <label htmlFor="settings-current">
                <FieldLabel required>Current Password</FieldLabel>
              </label>
              <PasswordInput
                id="settings-current"
                placeholder="Current Password"
                value={currentPassword}
                onChange={setCurrentPassword}
                autoComplete="current-password"
                required
                invalid={!!fieldErrors.current_password}
                describedBy={fieldErrors.current_password ? 'settings-current-error' : undefined}
                iconSize={18}
              />
              <FieldError id="settings-current-error" message={fieldErrors.current_password?.[0]} />
            </div>
            <div>
              <label htmlFor="settings-password">
                <FieldLabel required>Enter New Password</FieldLabel>
              </label>
              <PasswordInput
                id="settings-password"
                value={password}
                onChange={setPassword}
                required
                invalid={!!fieldErrors.password}
                describedBy={fieldErrors.password ? 'settings-password-error' : undefined}
                iconSize={18}
              />
              <FieldError id="settings-password-error" message={fieldErrors.password?.[0]} />
            </div>
            <div>
              <label htmlFor="settings-confirm">
                <FieldLabel required>Confirm New Password</FieldLabel>
              </label>
              <PasswordInput
                id="settings-confirm"
                value={confirm}
                onChange={setConfirm}
                required
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
