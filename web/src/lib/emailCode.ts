import { useEffect, useState } from 'react'

/*
 * Helpers for the six-digit e-mail codes [checklist 2026-09-27]. Kept out of
 * components/EmailCode.tsx so that file exports components only and fast
 * refresh keeps working on it.
 */

/** Six digits once spaces and dashes are dropped — the API drops them too. */
export function isSixDigits(value: string): boolean {
  return /^\d{6}$/.test(value.replace(/[\s-]/g, ''))
}

/**
 * Seconds until a resend button works again, counting down once a second.
 * The server enforces the wait (it answers 429 with `retry_after`); this only
 * keeps the button honest about it.
 */
export function useCooldown(initial = 0) {
  const [left, setLeft] = useState(initial)
  useEffect(() => {
    if (left <= 0) return
    const t = setTimeout(() => setLeft((n) => n - 1), 1000)
    return () => clearTimeout(t)
  }, [left])
  return [left, setLeft] as const
}
