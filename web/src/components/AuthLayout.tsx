import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Logo } from './Logo'

/** Official BPLO Citizen's Charter (Business One-Stop Shop edition), malabon.gov.ph. */
const CITIZEN_CHARTER_URL =
  'https://malabon.gov.ph/Content/uploads/2023/2024%20-%20Citizens%20Charter%20-%20Business%20One%20Stop%20Shop.pdf'

interface AuthLayoutProps {
  title: string
  /** One line under the title — who this is for, or what happens next. */
  lede?: ReactNode
  children: ReactNode
  /** Rendered under the form: cross-links like "Don't have an account?" */
  footer?: ReactNode
  /**
   * 'split' (default): photo panel beside the form, per the prototype login (PDF p1).
   * 'card': a centered white card floating over the full-bleed photo, per sign-up (PDF p3).
   */
  variant?: 'split' | 'card'
  /** The logo is the header (prototype); keep the h1 for screen readers only. */
  titleHidden?: boolean
}

/** City-hall photo with the prototype's #7796c5 blue wash. */
function CityHallPhoto({ className }: { className: string }) {
  return (
    <>
      <img src="/malaboncityhall.jpg" alt="" className={className} />
      <div aria-hidden="true" className="absolute inset-0 bg-[#7796c5]/60" />
    </>
  )
}

function LogoHeader({ height = 44 }: { height?: number }) {
  return (
    <Link to="/login" aria-label="BizTrack home" className="mx-auto mb-7 inline-flex self-center rounded-md">
      <Logo height={height} alt="" />
    </Link>
  )
}

export function AuthLayout({
  title,
  lede,
  children,
  footer,
  variant = 'split',
  titleHidden = false,
}: AuthLayoutProps) {
  const heading = (
    <div className={titleHidden ? 'sr-only' : 'mb-6 text-center'}>
      <h1 id="auth-title" className="text-2xl font-semibold text-ink">
        {title}
      </h1>
      {lede && <p className="mt-1.5 text-sm text-ink-secondary">{lede}</p>}
    </div>
  )

  if (variant === 'card') {
    return (
      <div className="relative min-h-dvh">
        <CityHallPhoto className="absolute inset-0 h-full w-full object-cover object-[center_30%]" />
        <main className="relative mx-auto flex min-h-dvh w-full max-w-2xl flex-col justify-center px-4 py-10">
          <section
            aria-labelledby="auth-title"
            className="flex flex-col rounded-2xl bg-white px-5 py-8 shadow-overlay sm:px-10 sm:py-9"
          >
            <LogoHeader />
            {heading}
            <div className="auth-form">{children}</div>
            {footer && <div className="mt-6 text-center text-sm text-ink-secondary">{footer}</div>}
          </section>
        </main>
      </div>
    )
  }

  /* Split login (PDF p1): photo left ~55%, white panel right. */
  return (
    <div className="min-h-dvh bg-white lg:grid lg:grid-cols-[1.2fr_minmax(26rem,45%)]">
      <aside className="relative h-36 sm:h-44 lg:sticky lg:top-0 lg:h-dvh">
        <CityHallPhoto className="absolute inset-0 h-full w-full object-cover object-[center_27%] lg:object-[center_30%]" />
      </aside>

      <div className="flex flex-col">
        <main className="mx-auto flex w-full max-w-md grow flex-col justify-center px-5 py-10 sm:px-8">
          <LogoHeader />
          <section aria-labelledby="auth-title">
            {heading}
            <div className="auth-form">{children}</div>
          </section>
          {footer && <div className="mt-7 text-center text-sm text-ink-secondary">{footer}</div>}
        </main>
        <footer className="pb-7 text-center">
          <a
            href={CITIZEN_CHARTER_URL}
            target="_blank"
            rel="noopener noreferrer"
            className="text-sm font-medium text-royal underline-offset-2 hover:underline"
          >
            BPLO Citizen Charter
          </a>
        </footer>
      </div>
    </div>
  )
}
