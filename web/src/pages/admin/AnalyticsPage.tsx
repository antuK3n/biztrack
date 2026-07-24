import { useMemo, useState } from 'react'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { CircleMarker, MapContainer, Popup, TileLayer } from 'react-leaflet'
import 'leaflet/dist/leaflet.css'
import { ErrorState, Skeleton, SkeletonCards } from '../../components/ui/primitives'
import { PageTitle, ProtoCard } from '../../components/ui/Proto'
import { toApiError } from '../../lib/api'
import { applicationTypeLabel } from '../../lib/format'
import { analytics, inspections, permits } from '../../lib/resources'
import { useAsync } from '../../lib/useAsync'
import type { AnalyticsSummary } from '../../lib/types'

/*
 * Analytics Dashboard (PDF p84–86): 4 royal KPI cards, royal line chart,
 * multicolor bar chart, and the Malabon Leaflet map with green/red business
 * markers. Data = /analytics/summary + coordinates already embedded in the
 * existing /inspections and /permits feeds (no new endpoints).
 */

const ROYAL = '#3242ca'
const GRID = '#c5cfe0'
const AXIS_TICK = { fontSize: 12, fill: '#5b6472' } as const
/** Prototype bar palette (p84): blue / purple / orange / yellow. */
const BAR_COLORS = ['#6b7ff2', '#8f6bf2', '#f2a33c', '#f5d54a']

const MALABON: [number, number] = [14.669, 120.957]

function SliderGlyph() {
  return (
    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" className="text-royal" aria-hidden="true">
      <path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
      <circle cx="15" cy="7" r="2.2" fill="#d1dbeb" stroke="currentColor" strokeWidth="2" />
      <circle cx="9" cy="12" r="2.2" fill="#d1dbeb" stroke="currentColor" strokeWidth="2" />
      <circle cx="13" cy="17" r="2.2" fill="#d1dbeb" stroke="currentColor" strokeWidth="2" />
    </svg>
  )
}

function Kpi({ value, label }: { value: string; label: string }) {
  return (
    <div className="rounded-xl bg-white px-4 py-7 text-center shadow-card">
      <p className="tnum text-[30px] font-bold leading-none text-royal">{value}</p>
      <p className="mt-2.5 text-[13px] text-ink-muted">{label}</p>
    </div>
  )
}

interface BusinessMarker {
  key: string
  name: string
  lat: number
  lng: number
  healthy: boolean
}

/** Coordinates from the inspections feed + permit health from the permits feed. */
function useBusinessMarkers() {
  return useAsync(async (): Promise<BusinessMarker[]> => {
    const [insp, perm] = await Promise.allSettled([inspections.list(), permits.list()])
    const permitHealth = new Map<string, boolean>()
    if (perm.status === 'fulfilled') {
      for (const p of perm.value) {
        const active = p.status === 'active' && (p.days_until_expiry === null || p.days_until_expiry > 30)
        permitHealth.set(p.business.name, (permitHealth.get(p.business.name) ?? false) || active)
      }
    }
    const markers = new Map<string, BusinessMarker>()
    if (insp.status === 'fulfilled') {
      for (const i of insp.value) {
        const addr = i.application.address
        const name = i.application.business.name
        if (addr.latitude === null || addr.longitude === null || markers.has(name)) continue
        markers.set(name, {
          key: name,
          name,
          lat: addr.latitude,
          lng: addr.longitude,
          healthy: permitHealth.get(name) ?? i.result === 'passed',
        })
      }
    }
    return [...markers.values()]
  }, [])
}

function Charts({ summary }: { summary: AnalyticsSummary }) {
  const byMonth = summary.applications_by_month
  const byType = Object.entries(summary.applications_by_type).map(([type, count]) => ({
    type: applicationTypeLabel(type),
    count,
  }))

  return (
    <div className="mt-6 grid gap-6 lg:grid-cols-2">
      <ProtoCard className="rounded-xl p-5">
        <h2 className="mb-3 text-sm font-semibold text-ink-secondary">Applications by month</h2>
        <ResponsiveContainer width="100%" height={250}>
          <LineChart data={byMonth} margin={{ top: 8, right: 16, left: -8, bottom: 4 }}>
            <CartesianGrid stroke={GRID} strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="month" tick={AXIS_TICK} tickLine={false} axisLine={{ stroke: GRID }} />
            <YAxis tick={AXIS_TICK} tickLine={false} axisLine={false} allowDecimals={false} width={32} />
            <Tooltip />
            <Line
              type="monotone"
              dataKey="count"
              name="Applications"
              stroke={ROYAL}
              strokeWidth={2.5}
              dot={{ r: 3.5, fill: ROYAL }}
              activeDot={{ r: 5 }}
            />
          </LineChart>
        </ResponsiveContainer>
      </ProtoCard>

      <ProtoCard className="rounded-xl p-5">
        <h2 className="mb-3 text-sm font-semibold text-ink-secondary">Applications by type</h2>
        <ResponsiveContainer width="100%" height={250}>
          <BarChart data={byType} margin={{ top: 8, right: 16, left: -8, bottom: 4 }}>
            <CartesianGrid stroke={GRID} strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="type" tick={AXIS_TICK} tickLine={false} axisLine={{ stroke: GRID }} interval={0} />
            <YAxis tick={AXIS_TICK} tickLine={false} axisLine={false} allowDecimals={false} width={32} />
            <Tooltip cursor={{ fill: '#eef2fc' }} />
            <Bar dataKey="count" name="Applications" radius={[6, 6, 0, 0]}>
              {byType.map((entry, i) => (
                <Cell key={entry.type} fill={BAR_COLORS[i % BAR_COLORS.length]} />
              ))}
            </Bar>
          </BarChart>
        </ResponsiveContainer>
      </ProtoCard>
    </div>
  )
}

function BusinessMap() {
  const { data: markers, loading } = useBusinessMarkers()

  return (
    <ProtoCard className="mt-6 rounded-xl p-4">
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-sm font-semibold text-ink-secondary">Businesses across Malabon</h2>
        <p className="flex items-center gap-4 text-xs text-ink-muted">
          <span className="flex items-center gap-1.5">
            <span className="h-2.5 w-2.5 rounded-full bg-s-green" aria-hidden="true" /> Active permit
          </span>
          <span className="flex items-center gap-1.5">
            <span className="h-2.5 w-2.5 rounded-full bg-s-red" aria-hidden="true" /> Expiring / none
          </span>
        </p>
      </div>
      <div className="overflow-hidden rounded-lg">
        <MapContainer
          center={MALABON}
          zoom={13}
          scrollWheelZoom={false}
          style={{ height: 380, width: '100%' }}
          aria-label="Map of businesses across Malabon"
        >
          <TileLayer
            attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
          />
          {(markers ?? []).map((m) => (
            <CircleMarker
              key={m.key}
              center={[m.lat, m.lng]}
              radius={9}
              pathOptions={{
                color: '#ffffff',
                weight: 1.5,
                fillColor: m.healthy ? '#22b573' : '#c11212',
                fillOpacity: 1,
              }}
            >
              <Popup>{m.name}</Popup>
            </CircleMarker>
          ))}
        </MapContainer>
      </div>
      {!loading && (markers ?? []).length === 0 && (
        <p className="mt-3 text-center text-sm text-ink-muted">
          No mapped business locations yet. Pins appear as applications with map coordinates come in.
        </p>
      )}
    </ProtoCard>
  )
}

function LoadingState() {
  return (
    <div className="space-y-6">
      <SkeletonCards count={4} />
      <div className="grid gap-6 lg:grid-cols-2">
        {Array.from({ length: 2 }).map((_, i) => (
          <div key={i} className="rounded-xl bg-white p-5 shadow-card">
            <Skeleton className="h-4 w-40" />
            <Skeleton className="mt-4 h-56 w-full" />
          </div>
        ))}
      </div>
    </div>
  )
}

export function AnalyticsPage() {
  const { data: summary, loading, error, reload } = useAsync(() => analytics.summary(), [])
  const [exporting, setExporting] = useState(false)
  const [exportError, setExportError] = useState<string | null>(null)

  async function generateReport() {
    setExporting(true)
    setExportError(null)
    try {
      await analytics.export('biztrack-analytics.csv')
    } catch (err) {
      setExportError(toApiError(err).message)
    } finally {
      setExporting(false)
    }
  }

  const kpis = useMemo(() => {
    if (!summary) return null
    const months = summary.applications_by_month
    const yearly = months.reduce((sum, m) => sum + m.count, 0)
    const monthly = months.length > 0 ? months[months.length - 1].count : 0
    return {
      active: summary.active_permits.toLocaleString(),
      yearly: yearly.toLocaleString(),
      monthly: monthly.toLocaleString(),
      compliance: `${Math.round(summary.approval_rate * 100)}%`,
    }
  }, [summary])

  return (
    <div>
      <PageTitle
        right={
          <span className="flex items-center gap-4 pb-1">
            <SliderGlyph />
            <button
              type="button"
              onClick={() => window.print()}
              className="rounded-lg border border-royal bg-white px-5 py-2.5 text-sm font-semibold text-royal shadow-card hover:bg-royal-tint"
            >
              Print
            </button>
            <button
              type="button"
              onClick={generateReport}
              disabled={exporting}
              className="rounded-lg bg-royal px-6 py-2.5 text-sm font-semibold text-white shadow-card hover:bg-royal-hover disabled:opacity-60"
            >
              {exporting ? 'Generating…' : 'Generate Report'}
            </button>
          </span>
        }
      >
        Analytics Dashboard
      </PageTitle>

      {exportError && (
        <p className="mb-4 rounded-lg bg-s-red-tint px-4 py-3 text-sm font-medium text-s-red">
          {exportError}
        </p>
      )}

      {loading ? (
        <LoadingState />
      ) : error ? (
        <ErrorState error={error} onRetry={reload} />
      ) : summary && kpis ? (
        <>
          <div className="grid grid-cols-2 gap-5 lg:grid-cols-4">
            <Kpi value={kpis.active} label="Active Businesses" />
            <Kpi value={kpis.yearly} label="Yearly Applications" />
            <Kpi value={kpis.monthly} label="Monthly Applications" />
            <Kpi value={kpis.compliance} label="Compliance Rate" />
          </div>
          <Charts summary={summary} />
          <BusinessMap />
        </>
      ) : null}
    </div>
  )
}
