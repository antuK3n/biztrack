import axios from 'axios'
import { useState } from 'react'
import { api, toApiError } from '../lib/api'
import { isSixDigits, useCooldown } from '../lib/emailCode'
import { emailCodes } from '../lib/resources'
import { CodeField } from './EmailCode'
import { PasswordInput } from './ui/PasswordInput'
import { FieldLabel, ProtoModal } from './ui/Proto'

/*
 * Change Password, from Settings (PDF p13).
 *
 * Moved out of SettingsPage when it grew a second step [checklist 2026-09-27,
 * Edit Settings; closes View Profile 3]. With mail on, the API asks for a
 * six-digit code by e-mail as well as the current password, so a session left
 * open plus a password seen over a shoulder is no longer enough to change it
 * and sign the owner out everywhere else. With mail off — the demo today —
 * the API never asks, `password_change_code_required` is false, and this
 * dialog behaves exactly as it did: three fields, Save Changes.
 *
 * If a stale page thinks no code is needed and the API disagrees (mail was
 * switched on after this tab loaded /auth/me), the refusal carries a `code`
 * error and the dialog moves itself to the code step instead of printing an
 * error about a box that is not on screen.
 */

/** Where the code went. `email` is absent when the API refused a resend: a code is already out. */
type Sent = { email?: string; minutes?: number }

/** Seconds the API says to wait, from a 429 on the code endpoint. */
function retryAfter(error: unknown): number {
  if (axios.isAxiosError(error)) {
    const value = (error.response?.data as { retry_after?: unknown } | undefined)?.retry_after
    if (typeof value === 'number' && value > 0) return value
  }
  return 0
}

function FieldError({ id, message }: { id: string; message?: string }) {
  if (!message) return null
  return (
    <p id={id} className="mt-1.5 text-sm font-medium text-s-red">
      {message}
    </p>
  )
}

export function ChangePasswordModal({
  codeRequired,
  onCancel,
  onChanged,
}: {
  codeRequired: boolean
  onCancel: () => void
  onChanged: () => void
}) {
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [needsCode, setNeedsCode] = useState(codeRequired)
  const [sent, setSent] = useState<Sent | null>(null)
  const [code, setCode] = useState('')
  const [codeNote, setCodeNote] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const [sending, setSending] = useState(false)
  const [cooldown, setCooldown] = useCooldown(0)
  const [formError, setFormError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({})

  const passwordsReady = !!currentPassword && password.length >= 8 && password === confirm

  function showError(error: unknown) {
    const apiError = toApiError(error)
    setFieldErrors(apiError.errors)
    if (Object.keys(apiError.errors).length === 0) setFormError(apiError.message)
  }

  /** The first code and every resend: the API decides which it is. */
  async function sendCode() {
    if (sending || cooldown > 0) return
    setSending(true)
    setFormError(null)
    setFieldErrors({})
    setCodeNote(null)
    try {
      const result = await emailCodes.requestPasswordCode(currentPassword)
      if (!result.code_required) {
        // Mail went off since this tab loaded; the password alone will do.
        setNeedsCode(false)
        return
      }
      if (sent) setCodeNote('We sent a new code. Use the newest one.')
      setSent({ email: result.email, minutes: result.expires_in_minutes })
      setCode('')
      setCooldown(result.resend_after)
    } catch (error) {
      const wait = retryAfter(error)
      if (wait > 0) {
        setCooldown(wait)
        /*
         * Too soon for another: a code from a moment ago is still good (the
         * dialog was closed and reopened, say). Show the box for it rather
         * than leaving the reader at a button that cannot help yet.
         */
        if (!sent) {
          setSent({})
          return
        }
      }
      showError(error)
    } finally {
      setSending(false)
    }
  }

  async function save() {
    if (saving) return
    if (needsCode && !isSixDigits(code)) {
      setFieldErrors({ code: ['Enter the 6 digits from the e-mail.'] })
      return
    }
    setSaving(true)
    setFormError(null)
    setFieldErrors({})
    try {
      await api.put('/auth/password', {
        current_password: currentPassword,
        password,
        password_confirmation: confirm,
        ...(needsCode ? { code } : {}),
      })
      onChanged()
    } catch (error) {
      const apiError = toApiError(error)
      if (!needsCode && apiError.errors.code) {
        setNeedsCode(true)
        setFormError('Changing your password now needs a code by e-mail. Press Send Code.')
        return
      }
      showError(error)
    } finally {
      setSaving(false)
    }
  }

  const askingForCode = needsCode && !sent

  return (
    <ProtoModal
      title="Change Password"
      cancelLabel="Cancel"
      confirmLabel={
        askingForCode
          ? sending
            ? 'Sending…'
            : 'Send Code'
          : saving
            ? 'Saving…'
            : needsCode
              ? 'Change Password'
              : 'Save Changes'
      }
      onCancel={onCancel}
      onConfirm={askingForCode ? sendCode : save}
      confirmDisabled={(askingForCode ? sending : saving) || !passwordsReady}
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
          <PasswordInput id="settings-confirm" value={confirm} onChange={setConfirm} required iconSize={18} />
        </div>
        {sent && (
          <div className="flex flex-col gap-3 sm:col-span-2">
            {/* A status, so a screen reader hears where the code went when
                this block appears after Send Code. */}
            <p role="status" className="text-sm text-ink-secondary">
              {sent.email ? (
                <>
                  We sent a 6-digit code to <span className="font-semibold break-all">{sent.email}</span>. It
                  works for {sent.minutes} minutes.
                </>
              ) : (
                'We already sent you a code. Use the newest one in your inbox.'
              )}
            </p>
            {codeNote && <p className="text-sm font-medium text-s-green">{codeNote}</p>}
            <CodeField
              id="settings-code"
              label="Code from the e-mail"
              value={code}
              onChange={setCode}
              error={fieldErrors.code?.[0]}
            />
            <div>
              <button
                type="button"
                onClick={sendCode}
                aria-disabled={sending || cooldown > 0}
                className="text-sm font-semibold text-royal underline-offset-2 hover:underline aria-disabled:cursor-default aria-disabled:text-ink-muted aria-disabled:no-underline"
              >
                {sending ? 'Sending…' : cooldown > 0 ? `Send a new code in ${cooldown}s` : 'Send a new code'}
              </button>
            </div>
          </div>
        )}
      </div>
      <p className="text-center text-xs text-ink-muted">
        {needsCode
          ? 'At least 8 characters, typed the same twice. We e-mail you a code to finish. Changing it signs out your other devices.'
          : 'At least 8 characters. Both fields must match to save. Saving signs out your other devices.'}
      </p>
    </ProtoModal>
  )
}
