/*
 * Client-side mirrors of the sprint 1 §E1 validation rules.
 *
 * Every message names the actual defect ("that number has 10 digits, it needs
 * 11") instead of restating the format and leaving the user to spot the
 * difference. WCAG 2.1 AA asks for error messages that say how to fix the
 * problem; PRODUCT.md asks us never to make someone feel foolish for guessing.
 */

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/
export const MOBILE_PATTERN = /^(09\d{9}|\+639\d{9})$/

/**
 * Philippine mobile numbers are 11 digits in local form: 09 + 9 more.
 *
 * Exported because the Edit Profile field caps typing at this length. A second
 * 11 written into a component is one that stays 11 after this one changes.
 */
export const MOBILE_DIGITS = 11

export function validateEmail(value: string): string | undefined {
  const trimmed = value.trim()
  if (!trimmed) return 'Enter your email address.'
  if (/\s/.test(trimmed)) return 'Remove the spaces from your email address.'

  const at = trimmed.split('@')
  if (at.length === 1) return 'Add an @ sign, as in name@example.com.'
  if (at.length > 2) return 'An email address can have only one @ sign.'

  const [local, domain] = at
  if (!local) return 'Add your username before the @ sign, as in name@example.com.'
  if (!domain) return 'Add the domain after the @ sign, as in name@example.com.'
  if (!domain.includes('.')) return 'The domain needs a dot in it, as in example.com.'
  if (!EMAIL_PATTERN.test(trimmed)) return 'That email address has a character that is not allowed.'
}

export function validateMobile(value: string): string | undefined {
  const cleaned = normalizeMobile(value)
  if (!cleaned) return 'Enter your mobile number.'
  if (MOBILE_PATTERN.test(cleaned)) return

  // Failed. Work out which part is wrong rather than repeating the format.
  if (/[^\d]/.test(cleaned.replace(/^\+/, ''))) {
    return 'Use digits only, with no letters or symbols.'
  }
  // Compare in local 09 form so +639 and 09 report the same digit counts.
  const local = cleaned.startsWith('+63') ? `0${cleaned.slice(3)}` : cleaned
  if (!local.startsWith('09')) {
    return 'A Philippine mobile number starts with 09, as in 09171234567.'
  }
  if (local.length < MOBILE_DIGITS) {
    return `That number is ${local.length} digits long, but a mobile number needs ${MOBILE_DIGITS}.`
  }
  return `That number is ${local.length} digits long, but a mobile number has only ${MOBILE_DIGITS}.`
}

/** Strip spaces, dashes and brackets so "(0917) 123-4567" passes as 09171234567. */
export function normalizeMobile(value: string): string {
  return value.replace(/[\s\-()]/g, '')
}

export function validatePassword(value: string): string | undefined {
  if (!value) return 'Enter a password.'
  if (value.length < 8) {
    return `That password is ${value.length} character${value.length === 1 ? '' : 's'} long, but it needs at least 8.`
  }
}

export function validatePasswordConfirmation(password: string, confirmation: string): string | undefined {
  if (!confirmation) return 'Retype your password to confirm it.'
  if (password !== confirmation) return "This doesn't match the password you entered above."
}

export function validateRequired(value: string, message: string): string | undefined {
  if (!value.trim()) return message
}
