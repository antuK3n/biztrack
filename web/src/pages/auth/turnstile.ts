/*
 * The two facts about Turnstile that are not a component.
 *
 * Split out of TurnstileWidget.tsx rather than exported beside it: a module
 * that exports both a component and a constant breaks Vite's fast refresh, and
 * `react(only-export-components)` says so on every build. The sign-in form
 * needs `captchaEnabled()` without needing the widget, which is the same split.
 */

/** Trimmed, because an env var set to an empty string is set-but-blank. */
export const TURNSTILE_SITE_KEY = (import.meta.env.VITE_TURNSTILE_SITE_KEY ?? '').trim()

/**
 * Whether a sign-in has to carry a captcha token at all.
 *
 * NO KEY = NO CAPTCHA, deliberately, and the API agrees with it from the other
 * side: `App\Support\Turnstile::enabled()` is false when the secret is blank.
 * That is what keeps local development, the mock API and the Playwright suite
 * signing in without a Cloudflare account — and it is the honest failure mode
 * for a half-configured control on a government service, which should let
 * people in rather than lock the city out of its own permit system.
 */
export function captchaEnabled(): boolean {
  return TURNSTILE_SITE_KEY !== ''
}
