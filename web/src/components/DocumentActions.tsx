import { useState } from 'react'
import { DownloadIcon, EyeIcon } from './icons'
import { toApiError } from '../lib/api'
import { documents } from '../lib/resources'

/*
 * ── Opening an uploaded requirement, in one place ─────────────────────────
 *
 * Checklist item 96 ("it cannot be viewed or downloaded now") and item 55
 * before it are the same bug seen from two seats, and the reason it came back
 * is that only one seat was ever fixed.
 *
 * Item 55 was fixed in the officer's Review sheet: a plain <a href> pointed at
 * /api/v1/documents/{id}/download sends no bearer token, so the new tab showed
 * the 401 JSON envelope instead of the file. That fix lived as a local
 * component inside ReviewPage.tsx, which meant the applicant's side of the
 * house never inherited it — and in fact never grew a view or download control
 * at all. An owner could upload a scan and then had no way to check what they
 * had actually sent.
 *
 * So the control moved out of ReviewPage rather than being copied into the
 * wizard. One implementation is also the only way the accessible names stay
 * right: a column of buttons all reading "View" tells a screen-reader user
 * nothing about which of six documents they are about to open (WCAG 2.1 AA,
 * 2.4.4 Link Purpose / 4.1.2 Name, Role, Value). The caller passes the
 * requirement's name and every button here is named after it.
 *
 * Both actions fetch the file through the api client so the Authorization
 * header is attached, then hand the browser a blob: URL — never the API URL.
 */

interface DocumentActionsProps {
  /** application_documents.id — what the download endpoint is keyed on. */
  id: number
  /** The file as the applicant named it, used for save-to-disk. */
  filename: string
  /**
   * What this document IS, for the accessible name: "Barangay Business
   * Clearance", not "scan_003.pdf". Falls back to the filename when a caller
   * genuinely has nothing better (the repeatable "Other Requirements", where
   * the filename is the only thing distinguishing one row from the next).
   */
  label?: string
}

export function DocumentActions({ id, filename, label }: DocumentActionsProps) {
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState<'view' | 'download' | null>(null)
  const name = label || filename

  async function view() {
    /*
     * The tab has to be opened inside the click, before any await, or the
     * popup blocker eats it — by the time the fetch resolves the gesture has
     * expired and window.open() silently returns null.
     */
    const tab = window.open('', '_blank')
    setBusy('view')
    setError(null)
    try {
      await documents.view(id, tab)
    } catch (err) {
      // Close the blank tab we opened, or a failure leaves the user staring
      // at an empty window with the error message on the page behind it.
      tab?.close()
      setError(toApiError(err).message)
    } finally {
      setBusy(null)
    }
  }

  async function save() {
    setBusy('download')
    setError(null)
    try {
      await documents.download(id, filename)
    } catch (err) {
      setError(toApiError(err).message)
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="flex shrink-0 flex-col items-end gap-1.5">
      <div className="flex shrink-0 items-center gap-2">
        <button
          type="button"
          onClick={view}
          disabled={busy !== null}
          aria-label={`View ${name}`}
          className="inline-flex items-center gap-1.5 rounded-md border border-line px-3 py-1.5 text-xs font-semibold text-ink-secondary hover:bg-canvas disabled:opacity-60"
        >
          <EyeIcon size={14} />
          {busy === 'view' ? 'Opening…' : 'View'}
        </button>
        <button
          type="button"
          onClick={save}
          disabled={busy !== null}
          aria-label={`Download ${name}`}
          className="inline-flex items-center gap-1.5 rounded-md bg-royal px-3 py-1.5 text-xs font-semibold text-white hover:bg-royal-hover disabled:opacity-60"
        >
          <DownloadIcon size={14} />
          {busy === 'download' ? 'Saving…' : 'Download'}
        </button>
      </div>
      {/*
       * role="alert" because the failure follows a click the user has already
       * made and has no other announcement — without it a screen reader user
       * presses View, nothing opens, and nothing says why.
       */}
      {error && (
        <p role="alert" className="text-xs font-medium text-s-red">
          {error}
        </p>
      )}
    </div>
  )
}
