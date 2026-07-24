/* Client-side mirrors of the sprint 1 §E1 validation rules, with plain-language messages. */

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/
export const MOBILE_PATTERN = /^(09\d{9}|\+639\d{9})$/

export function validateEmail(value: string): string | undefined {
  if (!value.trim()) return 'Enter your email address.'
  if (!EMAIL_PATTERN.test(value.trim())) return 'Enter an email address in the format name@example.com.'
}

export function validateMobile(value: string): string | undefined {
  const cleaned = value.replace(/[\s-]/g, '')
  if (!cleaned) return 'Enter your mobile number.'
  if (!MOBILE_PATTERN.test(cleaned)) return 'Enter a Philippine mobile number, like 09171234567.'
}

/** Strip spaces and dashes so "0917 123 4567" passes as 09171234567. */
export function normalizeMobile(value: string): string {
  return value.replace(/[\s-]/g, '')
}

export function validatePassword(value: string): string | undefined {
  if (!value) return 'Enter a password.'
  if (value.length < 8) return 'Your password needs at least 8 characters.'
}

export function validatePasswordConfirmation(password: string, confirmation: string): string | undefined {
  if (!confirmation) return 'Retype your password to confirm it.'
  if (password !== confirmation) return "These passwords don't match. Retype them to be sure."
}

export function validateRequired(value: string, message: string): string | undefined {
  if (!value.trim()) return message
}
