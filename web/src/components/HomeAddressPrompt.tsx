import { Link } from 'react-router-dom'
import { InfoCircleIcon } from './icons'

/**
 * "Your profile is missing your home address" — for an owner who registered
 * before sign-up asked for one [checklist 2026-09-28, Register 2].
 *
 * Blue, not red: nothing has gone wrong and nothing is refused. Red Means Stop
 * (DESIGN.md), and this is not a stop — the owner can still file, and the
 * prompt says so, because the first thing a person wonders on seeing a notice
 * like this is whether it is holding anything up. Whether filing SHOULD wait
 * for the address is a question for BPLO, not a decision taken here
 * (docs/questions-for-malabon.md, A27).
 *
 * Shown on Profile and on the owner's home page and nowhere else. Tester item
 * 99 had a banner removed for sitting over every screen (see AppShell); two
 * places the owner goes to look at their own account is where this belongs.
 *
 * The link opens Edit Profile directly (`?edit=profile` on Settings) so the
 * answer is one step away, not two.
 */
export function HomeAddressPrompt({ className = '' }: { className?: string }) {
  return (
    <section
      aria-labelledby="home-address-prompt-title"
      className={`flex gap-3 rounded-xl border border-blue-200 bg-blue-50 px-5 py-4 text-blue-900 ${className}`}
    >
      <InfoCircleIcon size={20} className="mt-0.5 shrink-0" />
      <div className="min-w-0">
        <h2 id="home-address-prompt-title" className="text-sm font-bold">
          Add your home address to complete your profile
        </h2>
        <p className="mt-1 text-sm">
          The City now asks every business owner for one. Your applications are not held up while it
          is missing.
        </p>
        <Link
          to="/settings?edit=profile"
          className="mt-2 inline-block text-sm font-semibold text-royal underline underline-offset-2 hover:text-royal-hover"
        >
          Add home address
        </Link>
      </div>
    </section>
  )
}
