/*
 * Where each CPDO zoning sheet sits on the ground.
 *
 * ## Who reads this
 *
 * Only `scripts/trace-zoning-sheets.py`, which uses these placements to turn
 * each sheet's coloured areas into approximate zone polygons
 * (web/public/zoning/<slug>.geojson) that the picker map draws. Nothing in the
 * app imports this file any more: the raw sheet used to be laid over the map
 * through the affine below (ZoningSheetOverlay, removed 2026-09-24 when the
 * traced layer replaced it). If a sheet overlay is ever wanted back, the
 * placement is here and the component is in git history.
 *
 * ## What this is, and what it is not
 *
 * A placement for a PICTURE, and so a placement for whatever is traced from
 * it. Tracing makes zones drawable and nameable on hover, as "approximate"; it
 * does not make a verdict honest. `BarangayZoningMap.tsx` sets out why no
 * verdict can be derived, and a better placement changes none of it.
 *
 * ## How each placement was derived (2026-09-23)
 *
 * The sheets are NOT cropped to the barangay. Each is an ArcGIS layout: a map
 * frame showing the barangay and its neighbours, plus a title block, legend,
 * scale bar and location map to its right. Only the frame is georeferenced;
 * `frame` is its pixel box and everything outside it is clipped off.
 *
 * 1. Scale. Every sheet states a ratio (1:3,000 to 1:12,000) and was exported
 *    at 96 dpi, so metres per pixel = ratio × 0.0254 / 96. Checked against the
 *    drawn scale bar on each sheet, and against Dampalit and Potrero, which
 *    carry two grid ticks per axis: 630 px apart = 2,000 m at 1:12,000.
 * 2. Position. Frame ticks label a 2 km grid (e.g. E 280000, N 1622000). The
 *    numbers are UTM zone 51N, whatever the sheet's "Luzon 1911 / Philippine
 *    Zone III" caption says; Zone III would put Malabon near E 494000, not
 *    280000. Eleven sheets carry a tick on both axes, five on one, four on
 *    neither. The missing axes were placed by cross-correlating the sheet's
 *    linework against already-placed neighbours (translation only; scale is
 *    known). On the five one-tick sheets the correlation also recovered the
 *    ticked axis, to within 2 m of the tick.
 * 3. Consistency. Every overlapping pair of tick-placed sheets was
 *    cross-correlated too: they agree to within 4 m. There is no rotation and
 *    no scale drift between sheets.
 * 4. Datum. Read as WGS84, the grid lands every sheet about 102 m too far south
 *    of OpenStreetMap's roads. Read as Luzon 1911 it is worse (about 225 m, and
 *    diagonal). So the offset was measured, not assumed: OSM road centrelines
 *    were fitted to each sheet's road casings, and all 21 sheets gave the same
 *    answer, 98 to 108 m north and within 6 m east–west. One correction, +102 m
 *    northing, is applied to all of them. A per-sheet fit would chase noise.
 * 5. Santulan's sheet is a 960×720 re-export, not the 1825×1243 original. Its
 *    scale (3.135 m/px) and northing were fitted against Panghulo and Maysilo;
 *    its E 280000 tick agreed, and the OSM fit gave the same +102 m.
 *
 * `corners` are the frame's top-left, top-right and bottom-left in WGS84
 * [lat, lng], from UTM 51N after the correction. Three, because the frame is a
 * rectangle in UTM, and UTM grid north here is about 0.5° off true north: an
 * axis-aligned latitude/longitude box would misplace a frame corner by up to
 * 28 m on the 1:12,000 sheets. The tracing script applies the affine these
 * three fix, in Web Mercator, exactly as the removed overlay did.
 *
 * What remains: after the one correction, the per-sheet road fits scatter
 * about ±6 m, so call it ±10 m against OSM.
 * That is a lot on a street of 6 m lots, which is why the map says so.
 *
 * If a sheet is replaced, its entry is wrong. A new export with a different
 * size or layout invalidates `frame`, `size` and `corners`; delete the entry
 * until it is placed again rather than letting the old numbers stretch the new
 * picture. The scratch scripts that produced these are not kept; the steps
 * above are enough to repeat it with any image library and a UTM routine.
 */

export interface ZoningSheetPlacement {
  /** Same file `BarangayZoningMap` links to; placement is tied to these pixels. */
  url: string
  /** Image size in pixels, [width, height]. */
  size: [number, number]
  /** Map frame in image pixels: [left, top, right, bottom]. */
  frame: [number, number, number, number]
  /** Frame top-left, top-right, bottom-left as WGS84 [lat, lng]. */
  corners: [[number, number], [number, number], [number, number]]
}

/** Keyed by barangay name as `malabonGeo.data.ts` spells it. */
export const ZONING_SHEETS: Record<string, ZoningSheetPlacement> = {
  // 1:3,000 — E and N registered against Tinajeros
  "Acacia": { url: '/zoning-maps/acacia.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.671939, 120.965645], [14.672014, 120.974309], [14.664551, 120.965713]] },
  // 1:5,000 — E and N registered against Hulong Duhat and Maysilo
  "Baritan": { url: '/zoning-maps/baritan.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.677785, 120.940283], [14.677913, 120.954724], [14.665473, 120.940398]] },
  // 1:3,000 — E and N registered against Hulong Duhat
  "Bayan-bayanan": { url: '/zoning-maps/bayan-bayanan.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.679128, 120.940884], [14.679204, 120.949549], [14.671741, 120.940954]] },
  // 1:6,000 — E 280000 tick; N 1622000 tick
  "Catmon": { url: '/zoning-maps/catmon.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.675674, 120.950927], [14.675826, 120.968256], [14.660899, 120.951065]] },
  // 1:4,000 — N 1622000 tick; E registered against Niugan and Baritan
  "Concepcion": { url: '/zoning-maps/concepcion.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.673063, 120.943302], [14.673165, 120.954854], [14.663213, 120.943394]] },
  // 1:12,000 — E 276000 tick; N 1626000 tick
  "Dampalit": { url: '/zoning-maps/dampalit.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.706063, 120.916722], [14.706372, 120.951383], [14.676515, 120.917002]] },
  // 1:3,000 — E and N registered against Hulong Duhat, Baritan and Bayan-bayanan
  "Flores": { url: '/zoning-maps/flores.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.677566, 120.938492], [14.677643, 120.947156], [14.670179, 120.938561]] },
  // 1:6,000 — E 278000 tick; N 1624000 tick
  "Hulong Duhat": { url: '/zoning-maps/hulong-duhat.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.687199, 120.932446], [14.687353, 120.949775], [14.672425, 120.932584]] },
  // 1:4,000 — N 1622000 tick; E registered against Niugan, San Agustin and Concepcion
  "Ibaba": { url: '/zoning-maps/ibaba.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.669250, 120.944703], [14.669352, 120.956254], [14.659400, 120.944795]] },
  // 1:7,000 — E 280000 tick; N 1620000 tick
  "Longos": { url: '/zoning-maps/longos.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.661490, 120.950279], [14.661668, 120.970494], [14.644253, 120.950439]] },
  // 1:8,000 — E 280000 tick; N 1624000 tick
  "Maysilo": { url: '/zoning-maps/maysilo.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.689424, 120.946555], [14.689627, 120.969661], [14.669724, 120.946739]] },
  // 1:6,000 — N 1624000 tick; E registered against seven neighbouring sheets
  "Muzon": { url: '/zoning-maps/muzon.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.684480, 120.939613], [14.684633, 120.956942], [14.669706, 120.939752]] },
  // 1:4,000 — E 280000 tick; N 1622000 tick
  "Niugan": { url: '/zoning-maps/niugan.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.671622, 120.949099], [14.671724, 120.960651], [14.661772, 120.949190]] },
  // 1:8,000 — E 280000 tick; N 1624000 tick
  "Panghulo": { url: '/zoning-maps/panghulo.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.694688, 120.940532], [14.694892, 120.963639], [14.674988, 120.940717]] },
  // 1:12,000 — E 282000 tick; N 1624000 tick
  "Potrero": { url: '/zoning-maps/potrero.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.687880, 120.968938], [14.688181, 121.003597], [14.658331, 120.969211]] },
  // 1:4,000 — E 280000 tick; N 1622000 tick
  "San Agustin": { url: '/zoning-maps/san-agustin.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.665704, 120.946079], [14.665806, 120.957631], [14.655855, 120.946171]] },
  // 1:6,000 — 960×720 re-export: E 280000 tick; scale (3.135 m/px) and N fitted against Panghulo and Maysilo
  "Santulan": { url: '/zoning-maps/santulan.png', size: [960, 720], frame: [80, 80, 693, 643], corners: [[14.697385, 120.948911], [14.697543, 120.966750], [14.681438, 120.949059]] },
  // 1:4,000 — E 280000 tick; N registered against Longos and San Agustin
  "Tañong": { url: '/zoning-maps/tanong.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.660979, 120.947439], [14.661081, 120.958991], [14.651130, 120.947531]] },
  // 1:6,000 — E 282000 tick; N registered against Catmon, Maysilo and Potrero
  "Tinajeros": { url: '/zoning-maps/tinajeros.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.680222, 120.959091], [14.680374, 120.976420], [14.665448, 120.959228]] },
  // 1:5,000 — E 280000 tick; N 1622000 tick
  "Tonsuya": { url: '/zoning-maps/tonsuya.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.669568, 120.953173], [14.669695, 120.967613], [14.657256, 120.953287]] },
  // 1:5,000 — E 282000 tick; N 1622000 tick
  "Tugatog": { url: '/zoning-maps/tugatog.png', size: [1825, 1243], frame: [102, 93, 1278, 1123], corners: [[14.669893, 120.961604], [14.670020, 120.976044], [14.657581, 120.961717]] },
}
