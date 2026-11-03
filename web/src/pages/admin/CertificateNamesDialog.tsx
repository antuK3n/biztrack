import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { Modal } from '../../components/ui/Modal'
import { toApiError } from '../../lib/api'
import { admin } from '../../lib/resources'
import type { CertificateSignatories } from '../../lib/types'

/*
 * ── The names an office prints on its certificate ──────────────────────────
 *
 * [Client, 3 November 2026: "kada office admin nababago nila yung mga name na
 * nasa permit sa office na yon … button sa Permits page".] One input per
 * caption the office's certificate prints a name under — the Mayor and the
 * OIC-BPLO, the Zoning Administrator, the two BFP signatures, the Building
 * Official and the OBO's evaluators, the Chief, CENRO. Saving writes the
 * office's rows and an audit entry per changed name.
 *
 * The dialog says what the change reaches: permits issued from now on. A
 * certificate already issued keeps the names it was signed with
 * (CertificateSignatories on the server), so nobody's name is rewritten on
 * a document they have already handed out.
 *
 * The health office gets no inputs: its two names are the inspector who went
 * and the officer who approved, read off the visit and the permit.
 */
export function CertificateNamesDialog({ onClose }: { onClose: () => void }) {
  const [data, setData] = useState<CertificateSignatories | null>(null)
  const [names, setNames] = useState<Record<string, string>>({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [fail, setFail] = useState<string | null>(null)
  const [done, setDone] = useState<string | null>(null)

  useEffect(() => {
    let live = true
    admin
      .certificateSignatories()
      .then((next) => {
        if (!live) return
        setData(next)
        setNames(Object.fromEntries(next.rows.map((r) => [r.role, r.name ?? ''])))
      })
      .catch((err) => live && setFail(toApiError(err).message))
      .finally(() => live && setLoading(false))
    return () => {
      live = false
    }
  }, [])

  const dirty = data?.rows.some((r) => (r.name ?? '') !== (names[r.role] ?? '').trim()) ?? false

  async function submit(e: FormEvent) {
    e.preventDefault()
    if (!data || saving || !dirty) return
    setSaving(true)
    setFail(null)
    setDone(null)
    try {
      const next = await admin.saveCertificateSignatories(
        data.rows.map((r) => ({ role: r.role, name: (names[r.role] ?? '').trim() || null })),
      )
      setData(next)
      setNames(Object.fromEntries(next.rows.map((r) => [r.role, r.name ?? ''])))
      setDone(
        next.changed
          ? `${next.changed} name${next.changed === 1 ? '' : 's'} saved. Permits issued from now on will carry ${next.changed === 1 ? 'it' : 'them'}; permits already issued keep the names they were signed with.`
          : 'Nothing changed.',
      )
    } catch (err) {
      setFail(toApiError(err).message)
    } finally {
      setSaving(false)
    }
  }

  const formId = 'certificate-names'
  const automatic = data !== null && data.rows.length === 0

  return (
    <Modal
      open
      onClose={onClose}
      title="Certificate names"
      description={data?.certificate ? `Who signs the ${data.certificate}` : undefined}
      size="lg"
      footer={
        <>
          <button
            type="button"
            onClick={onClose}
            className="rounded-full border border-line bg-white px-4 py-2 text-sm font-semibold text-ink-secondary hover:bg-canvas"
          >
            Close
          </button>
          {!automatic && (
            <button
              type="submit"
              form={formId}
              aria-disabled={saving || !dirty}
              className="rounded-full bg-royal px-4 py-2 text-sm font-semibold text-white hover:bg-royal-hover aria-disabled:opacity-60"
            >
              {saving ? 'Saving…' : 'Save names'}
            </button>
          )}
        </>
      }
    >
      {loading ? (
        <p className="text-sm text-ink-muted">Loading…</p>
      ) : automatic ? (
        <p className="text-sm leading-relaxed text-ink-secondary">
          Your office&rsquo;s certificate names itself: <b className="text-ink">Recommending Approval</b> is the
          inspector assigned to the visit that passed, and <b className="text-ink">Approved</b> is the officer who
          approved it. There is nothing to set here.
        </p>
      ) : (
        <form id={formId} onSubmit={submit} className="space-y-4">
          {fail && (
            <p role="alert" className="rounded-lg bg-s-red-tint px-3 py-2 text-sm text-s-red">
              {fail}
            </p>
          )}
          {done && (
            <p role="status" className="rounded-lg bg-s-green-tint px-3 py-2 text-sm text-s-green">
              {done}
            </p>
          )}
          <p className="text-xs leading-relaxed text-ink-muted">
            These print under the signature lines of every {data?.certificate ?? 'certificate'} your office issues from
            now on. A blank prints as a ruled line. Permits already issued keep the names they were signed with, and
            every change is recorded in the audit log.
          </p>
          <div className="grid gap-3 sm:grid-cols-2">
            {data?.rows.map((r) => {
              const id = `sig-${r.role.replace(/\W+/g, '-')}`
              return (
                <div key={r.role} className={r.role.includes('—') ? '' : 'sm:col-span-2'}>
                  <label htmlFor={id} className="block text-sm text-ink-muted">
                    {r.role}
                  </label>
                  <input
                    id={id}
                    value={names[r.role] ?? ''}
                    onChange={(e) => setNames((prev) => ({ ...prev, [r.role]: e.target.value }))}
                    maxLength={120}
                    placeholder="Full name, with title"
                    className="mt-1 w-full rounded-lg border border-input-border bg-input px-3 py-2 text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:ring-2 focus:ring-royal"
                  />
                </div>
              )
            })}
          </div>
        </form>
      )}
    </Modal>
  )
}
