import { useRef, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AuthLayout } from '../../components/AuthLayout'
import { Alert } from '../../components/ui/Alert'
import { PasswordInput } from '../../components/ui/PasswordInput'
import { FieldLabel, PillButton, inputCls } from '../../components/ui/Proto'
import { api, toApiError } from '../../lib/api'
import type { User } from '../../lib/types'
import {
  normalizeMobile,
  validateEmail,
  validateMobile,
  validatePassword,
  validatePasswordConfirmation,
  validateRequired,
} from '../../lib/validation'
import { useAuth } from '../../stores/auth'
import { PrivacyNoticeDialog } from './PrivacyNoticeDialog'

interface FormValues {
  first_name: string
  middle_name: string
  last_name: string
  suffix: string
  gender: '' | 'M' | 'F'
  email: string
  mobile_number: string
  password: string
  password_confirmation: string
  data_privacy_consent: boolean
}

type FieldName = keyof FormValues
type FormErrors = Partial<Record<FieldName, string>>

const initialValues: FormValues = {
  first_name: '',
  middle_name: '',
  last_name: '',
  suffix: '',
  gender: '',
  email: '',
  mobile_number: '',
  password: '',
  password_confirmation: '',
  data_privacy_consent: false,
}

const ALL_FIELDS = Object.keys(initialValues) as FieldName[]

function validateField(name: FieldName, values: FormValues): string | undefined {
  switch (name) {
    case 'first_name':
      return validateRequired(values.first_name, 'Enter your first name.')
    case 'last_name':
      return validateRequired(values.last_name, 'Enter your last name.')
    case 'gender':
      return values.gender ? undefined : 'Select your gender.'
    case 'email':
      return validateEmail(values.email)
    case 'mobile_number':
      return validateMobile(values.mobile_number)
    case 'password':
      return validatePassword(values.password)
    case 'password_confirmation':
      return validatePasswordConfirmation(values.password, values.password_confirmation)
    case 'data_privacy_consent':
      return values.data_privacy_consent ? undefined : 'You need to agree to the Data Privacy Notice to register.'
    default:
      return undefined
  }
}

/** Prototype field: label + filled input + red error line (PDF p3). */
function Field({
  label,
  required,
  error,
  errorId,
  controlId,
  children,
  className = '',
}: {
  label: string
  required?: boolean
  error?: string
  errorId: string
  /** id of the control this labels — without it the label is decoration. */
  controlId: string
  children: ReactNode
  className?: string
}) {
  return (
    <div className={className}>
      {/*
        FieldLabel renders a span, so every field on this form was previously
        announced by its placeholder alone — and the two password fields, which
        have a reveal button inside them, could not be wrapped in a label at
        all. htmlFor works for all of them (WCAG 2.1 AA 1.3.1 / 3.3.2).
      */}
      <label htmlFor={controlId} className="block">
        <FieldLabel required={required}>{label}</FieldLabel>
      </label>
      {children}
      {error && (
        <p id={errorId} className="mt-1.5 text-sm font-medium text-s-red">
          {error}
        </p>
      )}
    </div>
  )
}

export function RegisterPage() {
  const navigate = useNavigate()
  const setSession = useAuth((s) => s.setSession)

  const [values, setValues] = useState<FormValues>(initialValues)
  const [errors, setErrors] = useState<FormErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [privacyOpen, setPrivacyOpen] = useState(false)
  /* Prototype's second checkbox row — notification consent (visual, not part of the API payload). */
  const [notifyConsent, setNotifyConsent] = useState(true)
  const formRef = useRef<HTMLFormElement>(null)

  function setValue<K extends FieldName>(name: K, value: FormValues[K]) {
    setValues((prev) => {
      const next = { ...prev, [name]: value }
      // Re-validate live only once a field already shows an error.
      setErrors((prevErrors) =>
        prevErrors[name] ? { ...prevErrors, [name]: validateField(name, next) } : prevErrors,
      )
      return next
    })
  }

  function blurValidate(name: FieldName) {
    // Don't scold users for tabbing through an empty field they haven't used;
    // required-field errors surface on submit instead.
    const value = values[name]
    const hasContent = typeof value === 'string' ? value.trim() !== '' : value
    setErrors((prev) => (hasContent || prev[name] ? { ...prev, [name]: validateField(name, values) } : prev))
  }

  function focusFirstInvalid() {
    requestAnimationFrame(() => {
      formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
    })
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault()
    const next: FormErrors = {}
    for (const field of ALL_FIELDS) next[field] = validateField(field, values)
    setErrors(next)
    if (ALL_FIELDS.some((field) => next[field])) {
      focusFirstInvalid()
      return
    }

    setLoading(true)
    setFormError(null)
    try {
      const { data } = await api.post<{ data: { token: string; user: User } }>('/auth/register', {
        first_name: values.first_name.trim(),
        middle_name: values.middle_name.trim() || undefined,
        last_name: values.last_name.trim(),
        suffix: values.suffix.trim() || undefined,
        gender: values.gender,
        email: values.email.trim(),
        mobile_number: normalizeMobile(values.mobile_number),
        password: values.password,
        password_confirmation: values.password_confirmation,
        data_privacy_consent: values.data_privacy_consent,
      })
      // Self-registration is always a business owner, so always the public portal.
      setSession(data.data.token, data.data.user, 'public')
      navigate('/dashboard', { replace: true })
    } catch (error) {
      const apiError = toApiError(error)
      const serverErrors: FormErrors = {}
      for (const [field, messages] of Object.entries(apiError.errors)) {
        if (field in initialValues) serverErrors[field as FieldName] = messages[0]
      }
      if (Object.keys(serverErrors).length > 0) {
        setErrors((prev) => ({ ...prev, ...serverErrors }))
        focusFirstInvalid()
      } else {
        setFormError(apiError.message)
      }
      setLoading(false)
    }
  }

  return (
    <AuthLayout
      title="Create your BizTrack account"
      titleHidden
      variant="card"
      footer={
        <>
          Already have an account?{' '}
          <Link to="/login" className="font-bold text-royal hover:underline">
            Sign In.
          </Link>
        </>
      }
    >
      <form ref={formRef} onSubmit={handleSubmit} noValidate className="flex flex-col gap-5">
        {formError && (
          <Alert variant="error" title="We couldn't create your account">
            {formError}
          </Alert>
        )}

        <div className="grid gap-x-5 gap-y-4 sm:grid-cols-2">
          <Field label="First Name" required error={errors.first_name} errorId="reg-first-error" controlId="reg-first">
            <input
              id="reg-first"
              autoComplete="given-name"
              placeholder="First Name"
              value={values.first_name}
              onChange={(e) => setValue('first_name', e.target.value)}
              onBlur={() => blurValidate('first_name')}
              aria-invalid={errors.first_name ? true : undefined}
              aria-describedby={errors.first_name ? 'reg-first-error' : undefined}
              className={inputCls}
            />
          </Field>
          <Field label="Last Name" required error={errors.last_name} errorId="reg-last-error" controlId="reg-last">
            <input
              id="reg-last"
              autoComplete="family-name"
              placeholder="Last Name"
              value={values.last_name}
              onChange={(e) => setValue('last_name', e.target.value)}
              onBlur={() => blurValidate('last_name')}
              aria-invalid={errors.last_name ? true : undefined}
              aria-describedby={errors.last_name ? 'reg-last-error' : undefined}
              className={inputCls}
            />
          </Field>

          {/* The prototype's "Home Address" band — our account record keys on
              middle name / suffix / gender instead, laid out in the same slot. */}
          <Field label="Middle Name" error={errors.middle_name} errorId="reg-middle-error" controlId="reg-middle">
            <input
              id="reg-middle"
              autoComplete="additional-name"
              placeholder="Middle Name (optional)"
              value={values.middle_name}
              onChange={(e) => setValue('middle_name', e.target.value)}
              className={inputCls}
            />
          </Field>
          <div className="grid grid-cols-2 gap-x-5">
            <Field label="Suffix" error={errors.suffix} errorId="reg-suffix-error" controlId="reg-suffix">
              <input
                id="reg-suffix"
                autoComplete="honorific-suffix"
                placeholder="Jr., III"
                maxLength={10}
                value={values.suffix}
                onChange={(e) => setValue('suffix', e.target.value)}
                className={inputCls}
              />
            </Field>
            <Field label="Gender" required error={errors.gender} errorId="reg-gender-error" controlId="reg-gender">
              <select
                id="reg-gender"
                value={values.gender}
                onChange={(e) => setValue('gender', e.target.value as FormValues['gender'])}
                onBlur={() => blurValidate('gender')}
                aria-invalid={errors.gender ? true : undefined}
                aria-describedby={errors.gender ? 'reg-gender-error' : undefined}
                className={`${inputCls} ${values.gender === '' ? 'text-ink-muted' : ''}`}
              >
                <option value="" disabled>
                  Select
                </option>
                <option value="M">Male</option>
                <option value="F">Female</option>
              </select>
            </Field>
          </div>

          <Field label="Email Address" required error={errors.email} errorId="reg-email-error" controlId="reg-email">
            <input
              id="reg-email"
              type="email"
              autoComplete="email"
              inputMode="email"
              placeholder="Email Address"
              value={values.email}
              onChange={(e) => setValue('email', e.target.value)}
              onBlur={() => blurValidate('email')}
              aria-invalid={errors.email ? true : undefined}
              aria-describedby={errors.email ? 'reg-email-error' : undefined}
              className={inputCls}
            />
          </Field>
          <Field label="Contact Number" required error={errors.mobile_number} errorId="reg-mobile-error" controlId="reg-mobile">
            <input
              id="reg-mobile"
              type="tel"
              autoComplete="tel-national"
              inputMode="tel"
              placeholder="Contact Number"
              value={values.mobile_number}
              onChange={(e) => setValue('mobile_number', e.target.value)}
              onBlur={() => blurValidate('mobile_number')}
              aria-invalid={errors.mobile_number ? true : undefined}
              aria-describedby={errors.mobile_number ? 'reg-mobile-error' : undefined}
              className={inputCls}
            />
          </Field>

          <Field label="Password" required error={errors.password} errorId="reg-password-error" controlId="reg-password">
            <PasswordInput
              id="reg-password"
              name="password"
              placeholder="Password"
              value={values.password}
              onChange={(v) => setValue('password', v)}
              onBlur={() => blurValidate('password')}
              invalid={!!errors.password}
              describedBy={errors.password ? 'reg-password-error' : undefined}
            />
          </Field>
          <Field label="Confirm Password" required error={errors.password_confirmation} errorId="reg-confirm-error" controlId="reg-confirm">
            <PasswordInput
              id="reg-confirm"
              name="password_confirmation"
              placeholder="Re-enter Password"
              value={values.password_confirmation}
              onChange={(v) => setValue('password_confirmation', v)}
              onBlur={() => blurValidate('password_confirmation')}
              invalid={!!errors.password_confirmation}
              describedBy={errors.password_confirmation ? 'reg-confirm-error' : undefined}
            />
          </Field>
        </div>

        {/* Checkbox rows (PDF p3): consent to the privacy notice + notification consent. */}
        <div className="mx-auto flex w-full max-w-lg flex-col items-center gap-1.5 text-center">
          <label className="flex items-start justify-center gap-2 text-xs text-ink">
            <input
              type="checkbox"
              checked={values.data_privacy_consent}
              onChange={(e) => setValue('data_privacy_consent', e.target.checked)}
              aria-invalid={errors.data_privacy_consent ? true : undefined}
              aria-describedby={errors.data_privacy_consent ? 'reg-consent-error' : undefined}
              className="mt-0.5 h-3.5 w-3.5 shrink-0 accent-royal"
            />
            <span>
              I agree to the{' '}
              <button
                type="button"
                onClick={() => setPrivacyOpen(true)}
                className="font-semibold text-royal hover:underline"
              >
                Terms of Use and Privacy Policy
              </button>
            </span>
          </label>
          {errors.data_privacy_consent && (
            <p id="reg-consent-error" className="text-sm font-medium text-s-red">
              {errors.data_privacy_consent}
            </p>
          )}
          <label className="flex items-start justify-center gap-2 text-xs text-ink">
            <input
              type="checkbox"
              checked={notifyConsent}
              onChange={(e) => setNotifyConsent(e.target.checked)}
              className="mt-0.5 h-3.5 w-3.5 shrink-0 accent-royal"
            />
            <span>I consent to receive email/SMS notifications about my application status</span>
          </label>
        </div>

        <PillButton type="submit" disabled={loading} className="w-full">
          {loading ? 'Signing Up…' : 'Sign Up'}
        </PillButton>
      </form>
      <PrivacyNoticeDialog open={privacyOpen} onClose={() => setPrivacyOpen(false)} />
    </AuthLayout>
  )
}
