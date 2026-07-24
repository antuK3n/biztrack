import { useEffect, useRef } from 'react'

interface PrivacyNoticeDialogProps {
  open: boolean
  onClose: () => void
}

/** Native <dialog>: focus trap, Escape-to-close, and top-layer stacking for free. */
export function PrivacyNoticeDialog({ open, onClose }: PrivacyNoticeDialogProps) {
  const ref = useRef<HTMLDialogElement>(null)

  useEffect(() => {
    const dialog = ref.current
    if (!dialog) return
    if (open && !dialog.open) dialog.showModal()
    if (!open && dialog.open) dialog.close()
  }, [open])

  return (
    <dialog
      ref={ref}
      onClose={onClose}
      onClick={(e) => {
        if (e.target === ref.current) onClose() // light dismiss on backdrop click
      }}
      aria-labelledby="privacy-title"
      className="m-auto w-[calc(100%-2rem)] max-w-lg rounded-lg border border-line bg-surface p-0 text-ink shadow-overlay backdrop:bg-ink/40"
    >
      <div className="max-h-[80dvh] overflow-y-auto px-6 py-6 sm:px-8">
        <h2 id="privacy-title" className="text-lg font-semibold">
          BizTrack Data Privacy Notice
        </h2>
        <div className="mt-3 flex flex-col gap-3 text-sm leading-relaxed text-ink-secondary">
          <p>
            BizTrack is operated by the Business Permits and Licensing Office (BPLO) of the City of Malabon. We
            collect your personal information to process business permit applications, renewals, and amendments
            under the Local Government Code and city ordinances.
          </p>
          <div>
            <h3 className="mb-1 font-semibold text-ink">What we collect</h3>
            <p>
              Your name, email address, mobile number, and gender when you register; your business details and
              supporting documents when you apply for a permit.
            </p>
          </div>
          <div>
            <h3 className="mb-1 font-semibold text-ink">How we use it</h3>
            <p>
              To verify your identity, assess and route your applications to the right city departments, issue
              permits, and send you updates about your applications by email, text, and in-app notifications.
            </p>
          </div>
          <div>
            <h3 className="mb-1 font-semibold text-ink">How we protect it</h3>
            <p>
              Your information is processed in accordance with the Data Privacy Act of 2012 (Republic Act No.
              10173). It is stored on city government systems, shared only with the departments that process your
              permits, and never sold or disclosed for marketing.
            </p>
          </div>
          <div>
            <h3 className="mb-1 font-semibold text-ink">Your rights</h3>
            <p>
              You may access, correct, or request deletion of your personal data by contacting the BPLO or the
              city's Data Protection Officer at Malabon City Hall.
            </p>
          </div>
        </div>
        <div className="mt-5 text-right">
          <button
            type="button"
            onClick={onClose}
            className="inline-flex h-10 items-center rounded-md border border-line-strong bg-surface px-4 text-sm font-semibold text-ink transition-colors duration-150 hover:bg-shell active:bg-shell-deep"
          >
            Close
          </button>
        </div>
      </div>
    </dialog>
  )
}
