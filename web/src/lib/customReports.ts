import axios from 'axios'
import { api } from './api'
import type { AnalyticsScope, ReportColumn, ReportRow } from './types'

/*
 * The Custom Report builder (client, 19 October 2026) — its types and its
 * calls, kept in their own module beside the screen rather than folded into
 * resources.ts and types.ts, so the feature can be read (or removed) as one
 * piece.
 *
 * The server owns every choice: what can be counted, which numbers, filters and
 * breakdowns a subject offers, and their labels (App\Support\CustomReports).
 * The screen draws the catalogue it is sent and sends back a DEFINITION naming
 * the choices; nothing here knows a column name.
 *
 * `office` follows the rest of analytics: a request, not a filter. The server
 * answers an office account with its own office and refuses any other.
 */

export type CustomSubject = 'businesses' | 'applications' | 'permits' | 'payments'

export interface CustomOption {
  value: string
  label: string
}

export interface CustomSubjectSpec {
  key: CustomSubject
  label: string
  /** "Filed" / "Issued" / "Paid", or null where the subject has no date. */
  date_label: string | null
  measures: { key: string; label: string; format: ReportColumn['format'] }[]
  filters: { key: string; label: string; options: CustomOption[] }[]
  breakdowns: { key: string; label: string }[]
}

export interface CustomDefinition {
  subject: CustomSubject
  measures: string[]
  filters: Record<string, string[]>
  period: { from: string | null; to: string | null } | null
  breakdown: string[]
}

export interface CustomStarter {
  key: string
  title: string
  definition: CustomDefinition
}

export interface CustomCatalogue {
  subjects: CustomSubjectSpec[]
  starters: CustomStarter[]
  limits: { measures: number; breakdown: number; rows: number }
}

export interface CustomReportResult {
  subject: CustomSubject
  title: string
  columns: ReportColumn[]
  rows: ReportRow[]
  total: ReportRow
  /** How many businesses / filings / permits / payments matched. */
  matched: number
  /** "No businesses match these filters." when nothing did, else null. */
  empty: string | null
  /** Rows past the cap that are counted in the total but not listed. */
  truncated: number
  filters_text: string[]
  period: { from: string | null; to: string | null } | null
  notes: string[]
  scope: AnalyticsScope
  generated_at: string
}

export interface SavedCustomReport {
  id: number
  name: string
  definition: CustomDefinition
  updated_at: string | null
}

export type CustomExportFormat = 'xlsx' | 'csv' | 'pdf'

const BASE = '/analytics/custom-reports'

function params(office?: string) {
  return office ? { office } : undefined
}

/** Today as YYYY-MM-DD on this device, for the file name. */
function today(): string {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/*
 * A failed blob download carries its JSON error as a Blob. Read it back so
 * toApiError can show the server's own sentence (the same move resources.ts
 * makes for the other downloads).
 */
async function rethrowBlobError(error: unknown): Promise<never> {
  const res = axios.isAxiosError(error) ? error.response : undefined
  if (res?.data instanceof Blob) {
    try {
      res.data = JSON.parse(await res.data.text())
    } catch {
      /* keep the original response */
    }
  }
  throw error
}

export const customReports = {
  catalogue: async (office?: string): Promise<{ data: CustomCatalogue; scope: AnalyticsScope }> => {
    const res = await api.get<{ data: CustomCatalogue; scope: AnalyticsScope }>(`${BASE}/catalogue`, {
      params: params(office),
    })
    return res.data
  },

  run: async (definition: CustomDefinition, office?: string): Promise<CustomReportResult> => {
    const res = await api.post<{ data: CustomReportResult }>(`${BASE}/run`, { definition }, { params: params(office) })
    return res.data.data
  },

  /** Download the result. The file is the server's rendering of the same figures. */
  download: async (
    format: CustomExportFormat,
    definition: CustomDefinition,
    scopeOffice: string | null,
    office?: string,
  ): Promise<void> => {
    const res = await api
      .post(`${BASE}/export/${format}`, { definition }, { params: params(office), responseType: 'blob' })
      .catch(rethrowBlobError)
    const suffix = scopeOffice ? `-${scopeOffice.toLowerCase()}` : ''
    const objectUrl = URL.createObjectURL(res.data as Blob)
    const anchor = document.createElement('a')
    anchor.href = objectUrl
    anchor.download = `custom-report-${definition.subject}${suffix}-${today()}.${format}`
    document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()
    URL.revokeObjectURL(objectUrl)
  },

  saved: async (): Promise<SavedCustomReport[]> => {
    const res = await api.get<{ data: SavedCustomReport[] }>(`${BASE}/saved`)
    return res.data.data
  },
  save: async (name: string, definition: CustomDefinition): Promise<SavedCustomReport> => {
    const res = await api.post<{ data: SavedCustomReport }>(`${BASE}/saved`, { name, definition })
    return res.data.data
  },
  update: async (
    id: number,
    changes: { name?: string; definition?: CustomDefinition },
  ): Promise<SavedCustomReport> => {
    const res = await api.patch<{ data: SavedCustomReport }>(`${BASE}/saved/${id}`, changes)
    return res.data.data
  },
  remove: async (id: number): Promise<void> => {
    await api.delete(`${BASE}/saved/${id}`)
  },
}
