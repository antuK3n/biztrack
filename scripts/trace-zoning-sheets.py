#!/usr/bin/env python3
"""
Trace CPDO's raster zoning sheets into approximate zone polygons.

    python3 scripts/trace-zoning-sheets.py                 # all barangays
    python3 scripts/trace-zoning-sheets.py --only Tonsuya  # one
    python3 scripts/trace-zoning-sheets.py --preview DIR   # also write check PNGs

Dependencies: Python 3.10+, numpy, Pillow. Nothing else — no shapely, GDAL,
rasterio, scipy or OpenCV — so the pipeline runs on a stock machine and every
step below is visible in this file rather than inside a library call.

Reads (never writes):
  web/public/zoning-maps/<slug>.png   the sheets CPDO supplied
  web/src/lib/zoningSheets.data.ts    where each sheet sits on the ground
  web/src/lib/malabonGeo.data.ts      the barangay polygons to clip to
  api/database/database.sqlite        which classifications each barangay has,
                                      opened read-only (mode=ro)

Writes:
  web/src/lib/zoningLayers.data.ts    which barangays have a layer (full runs only)
  web/public/zoning/<slug>.geojson    one FeatureCollection per barangay, one
                                      MultiPolygon feature per zone, with
                                      properties {codes: [...], name}. `codes`
                                      has two entries only for R-2 Basic/Max.

─── What the output is, and what it is not ──────────────────────────────────

A TRACING of a picture, for drawing. Not survey data and not a verdict. The
sheet placement is about ±10 m (zoningSheets.data.ts), the barangay polygons
are simplified to ~100 m (malabonGeo.data.ts), and every step here rounds a
little more. The map labels every zone "approximate" and CPDO still decides
what covers a lot; nothing in the product may read a zone back out of these
files for a location. See BarangayZoningMap.tsx.

─── How a sheet becomes polygons ─────────────────────────────────────────────

1. Legend. Each sheet carries its own legend, and the colours are NOT the same
   from sheet to sheet (C-1 is #ff7f7f on Tonsuya and #e09072 on Acacia; I-1 is
   #df73ff on one and #c500ff on another). So the swatches are read off each
   sheet, in the legend's printed order, instead of trusting one table. The
   database's `legend_color` is what the MAP draws with, so that a zone looks
   the same on every barangay; it is never used to read the sheet.
2. Only this barangay. A sheet shows its neighbours too, washed to about 20%
   strength; the barangay itself is at full strength. Pixels are matched to
   the legend exactly (the PNGs are palette images, so a zone is one exact RGB
   value), which is what keeps the washed neighbours out. And a pixel may only
   become a zone the barangay has in `barangay_zoning_classification`.
3. Patterns. CMP is hatched, CBD is dotted over C-2's red. Each class is
   scored by how much of its colours sit in a 7 px window, with a bonus for
   colours only it has, so linework, street names and hatching wash out; a
   patterned class must show all its colours nearby, so a dotted red area
   reads as CBD and a plain one as C-2.
   R-2 Basic and R-2 Max share one fill and differ only by Max's yellow
   outline, which survives on the sheets as broken fragments along parcel
   lines. That is not enough to tell them apart honestly, so where a barangay
   has both, the patch is labelled with both codes ("R-2 Basic or R-2 Max").
4. Clean. Road symbols that match a zone colour (a PROPOSED ROAD is C-2 red
   cased in dark grey; road casings are Utilities grey) are removed by width
   and by their casing. Streets are then closed by letting zones grow ~6 m into
   unzoned, non-water ground. Patches under MIN_AREA_M2 merge into their
   surroundings, enclosed holes under MAX_HOLE_M2 are filled, and a small zone
   standing alone in unzoned ground is dropped.
5. Clip to the barangay polygon in malabonGeo.data.ts, in raster space, before
   tracing, so the zones never spill past the outline the map draws.
6. Trace and simplify with shared edges. Every boundary between two zones is
   one arc, simplified once, so neighbouring zones cannot open slivers or
   overlap where they meet. Douglas-Peucker to SIMPLIFY_M.
7. Project through the same three-corner affine the (removed) sheet overlay used, in
   Web Mercator, to WGS84.

A barangay whose result is not credible is left out (see OMIT). Better no
layer than a confident wrong one.
"""

from __future__ import annotations

import argparse
import json
import math
import re
import sqlite3
import sys
from collections import Counter, defaultdict
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parent.parent
SHEETS_TS = ROOT / 'web/src/lib/zoningSheets.data.ts'
GEO_TS = ROOT / 'web/src/lib/malabonGeo.data.ts'
PUBLIC = ROOT / 'web/public'
OUT_DIR = PUBLIC / 'zoning'
DB = ROOT / 'api/database/database.sqlite'
MANIFEST = ROOT / 'web/src/lib/zoningLayers.data.ts'

#: Patches smaller than this (m²) are absorbed into their surroundings.
MIN_AREA_M2 = 120.0
#: Enclosed unzoned holes smaller than this (m²) are filled from around them.
MAX_HOLE_M2 = 3000.0
#: A zone smaller than this (m²) bordered mostly by unzoned ground is dropped.
ISOLATED_M2 = 1500.0
#: Douglas-Peucker tolerance on the ground, in metres. The placement is ±10 m,
#: so a 2 m tolerance loses nothing a reader could trust in the first place.
SIMPLIFY_M = 2.0
#: Half-width of the scoring window, in pixels (7×7).
WINDOW = 3

#: The legend's printed order on the 1825×1243 sheets.
LEGEND_ORDER = [
    'R-1', 'R-2-BASIC', 'R-2-MAX', 'R-3-BASIC', 'R-3-MAX', 'CMP', 'C-1', 'C-2', 'C-3', 'CBD',
    'GENERAL-COMMERCIAL', 'I-1', 'I-2', 'INSTITUTIONAL', 'FISHPOND', 'PARKS', 'MANGROVE',
    'UTILITIES', 'CEMETERY',
]
#: Sheets whose legend is printed in another order, read off each one by eye
#: (2026-09-24). PUD and ECO-TOURISM are not classifications this system holds;
#: they are listed so the rows line up, and never become zones. A replaced
#: sheet needs its legend checked again: a wrong order here would silently
#: relabel every zone below the first mismatch.
LEGEND_ORDERS = {
    'Dampalit': [
        'R-1', 'R-2-BASIC', 'R-2-MAX', 'R-3-BASIC', 'R-3-MAX', 'C-1', 'C-2', 'C-3', 'CBD',
        'GENERAL-COMMERCIAL', 'I-1', 'I-2', 'CMP', 'INSTITUTIONAL', 'FISHPOND', 'PARKS', 'MANGROVE',
        'UTILITIES', 'CEMETERY',
    ],
    'Hulong Duhat': [
        'R-1', 'R-2-BASIC', 'R-2-MAX', 'R-3-BASIC', 'R-3-MAX', 'CMP', 'C-1', 'C-2', 'C-3', 'CBD',
        'GENERAL-COMMERCIAL', 'I-1', 'I-2', 'INSTITUTIONAL', 'FISHPOND', 'ECO-TOURISM', 'PARKS',
        'MANGROVE', 'CEMETERY', 'UTILITIES',
    ],
    'Potrero': [
        'R-1', 'R-2-BASIC', 'C-1', 'C-2', 'C-3', 'CBD', 'GENERAL-COMMERCIAL', 'R-2-MAX', 'R-3-BASIC',
        'R-3-MAX', 'CMP', 'I-1', 'I-2', 'INSTITUTIONAL', 'FISHPOND', 'PARKS', 'UTILITIES', 'CEMETERY',
    ],
    'Panghulo': [c for c in LEGEND_ORDER if c != 'MANGROVE'],
    'San Agustin': [
        'R-1', 'R-2-BASIC', 'R-2-MAX', 'R-3-BASIC', 'R-3-MAX', 'CMP', 'C-1', 'C-2', 'C-3', 'CBD',
        'GENERAL-COMMERCIAL', 'I-1', 'I-2', 'INSTITUTIONAL', 'FISHPOND', 'PARKS', 'CEMETERY',
        'UTILITIES',
    ],
    'Tañong': [
        'FISHPOND', 'INSTITUTIONAL', 'C-1', 'C-2', 'C-3', 'CBD', 'GENERAL-COMMERCIAL', 'PUD', 'I-1',
        'I-2', 'R-1', 'R-2-BASIC', 'R-2-MAX', 'R-3-BASIC', 'R-3-MAX', 'CMP', 'ECO-TOURISM', 'PARKS',
        'MANGROVE', 'CEMETERY', 'UTILITIES',
    ],
}

#: Santulan's sheet is a 960×720 re-export whose colours were smeared by lossy
#: compression and whose legend is illegible at that size, so its colours are
#: sampled from the map face instead and matched with a tolerance.
SANTULAN_COLOURS = {
    'R-2-BASIC': [(0xfb, 0xfc, 0xb8)],
    'C-1': [(0xf1, 0x84, 0x7c)],
    'I-1': [(0xc5, 0x01, 0xff)],
}

#: Barangays traced but judged not credible on inspection, with the reason.
#: They get no file, and the map simply shows no zoning layer for them.
OMIT: dict[str, str] = {}


# ─── inputs ──────────────────────────────────────────────────────────────────

def slugify(name: str) -> str:
    s = name.lower().replace('ñ', 'n')
    return re.sub(r'[^a-z0-9]+', '-', s).strip('-')


def load_sheets() -> dict[str, dict]:
    text = SHEETS_TS.read_text()
    out = {}
    pat = re.compile(
        r'"([^"]+)":\s*\{\s*url:\s*\'([^\']+)\',\s*size:\s*(\[[^\]]+\]),\s*frame:\s*(\[[^\]]+\]),'
        r'\s*corners:\s*(\[\[[^\]]+\],\s*\[[^\]]+\],\s*\[[^\]]+\]\])\s*\}'
    )
    for m in pat.finditer(text):
        out[m.group(1)] = {
            'url': m.group(2),
            'size': json.loads(m.group(3)),
            'frame': json.loads(m.group(4)),
            'corners': json.loads(m.group(5)),
        }
    return out


def load_polygons() -> dict[str, list]:
    text = GEO_TS.read_text()
    out = {}
    for m in re.finditer(r'\{\s*name:\s*"([^"]+)",\s*psgc:\s*"[^"]+",\s*rings:\s*(\[\[\[.*?\]\]\])\s*\}', text):
        out[m.group(1)] = json.loads(m.group(2))
    return out


def load_classes() -> tuple[dict[str, dict], dict[str, list[str]]]:
    con = sqlite3.connect(f'file:{DB}?mode=ro', uri=True)
    classes = {
        code: {'code': code, 'name': name, 'color': color, 'order': order}
        for code, name, color, order in con.execute(
            'select code, name, legend_color, sort_order from zoning_classifications'
        )
    }
    allowed: dict[str, list[str]] = defaultdict(list)
    for bname, code in con.execute(
        'select b.name, z.code from barangay_zoning_classification bz '
        'join barangays b on b.id = bz.barangay_id '
        'join zoning_classifications z on z.id = bz.zoning_classification_id'
    ):
        allowed[bname].append(code)
    con.close()
    return classes, allowed


# ─── geometry: sheet pixels <-> WGS84 ─────────────────────────────────────────

def merc_y(lat: float) -> float:
    return math.log(math.tan(math.pi / 4 + lat * math.pi / 360)) * 180 / math.pi


def unmerc_y(y: float) -> float:
    return math.atan(math.exp(y * math.pi / 180)) * 360 / math.pi - 90


class Placement:
    """The same affine ZoningSheetOverlay applied: exact in Web Mercator."""

    def __init__(self, sheet: dict):
        (tl, tr, bl) = [(lng, merc_y(lat)) for lat, lng in sheet['corners']]
        fx0, fy0, fx1, fy1 = sheet['frame']
        self.o = np.array(tl)
        self.fx0, self.fy0 = fx0, fy0
        self.ex = (np.array(tr) - self.o) / (fx1 - fx0)
        self.ey = (np.array(bl) - self.o) / (fy1 - fy0)
        self.inv = np.linalg.inv(np.column_stack([self.ex, self.ey]))
        # Metres per pixel along the frame's x axis, for area and tolerance.
        lat0 = sheet['corners'][0][0]
        self.m_per_px = math.hypot(self.ex[0], self.ex[1] * math.cos(math.radians(lat0))) * 111_320

    def to_lnglat(self, px: float, py: float) -> tuple[float, float]:
        x, y = self.o + self.ex * (px - self.fx0) + self.ey * (py - self.fy0)
        return x, unmerc_y(y)

    def to_px(self, lng: float, lat: float) -> tuple[float, float]:
        d = self.inv @ (np.array([lng, merc_y(lat)]) - self.o)
        return d[0] + self.fx0, d[1] + self.fy0


# ─── legend ──────────────────────────────────────────────────────────────────

def pack(rgb: np.ndarray) -> np.ndarray:
    rgb = rgb.astype(np.int32)
    return (rgb[..., 0] << 16) | (rgb[..., 1] << 8) | rgb[..., 2]


WHITE = 0xFFFFFF


def read_legend(a: np.ndarray, order: list[str], x0: int, x1: int, y0: int, y1: int) -> dict[str, dict]:
    """Swatch rows in the legend, each reduced to fill, pattern and outline colours.

    The legend block sits a few pixels left or right from sheet to sheet, so the
    swatch column is found first (the columns inked on most rows), then rows are
    the inked runs within it. Hatched swatches have gaps, hence the low bar per
    row; two swatches printed touching come back as one tall run and are split
    evenly by the typical swatch height."""
    ink_all = (a[y0:y1, x0:x1] != 255).any(axis=2)
    col_on = np.flatnonzero(ink_all.mean(axis=0) >= 0.5)
    if len(col_on) < 8:
        raise ValueError('no legend swatch column found')
    cx0, cx1 = x0 + int(col_on.min()), x0 + int(col_on.max()) + 1
    region = a[y0:y1, cx0:cx1]
    ink = (region != 255).any(axis=2)
    rows_on = ink[:, 3:-3].mean(axis=1) >= 0.3
    runs, start = [], None
    for i, on in enumerate(list(rows_on) + [False]):
        if on and start is None:
            start = i
        elif not on and start is not None:
            if i - start >= 5:
                runs.append((start, i))
            start = None
    if runs:
        typical = float(np.median([e - s for s, e in runs]))
        split = []
        for s, e in runs:
            n = max(1, round((e - s + 3) / (typical + 3)))
            step = (e - s) / n
            split += [(int(s + i * step), int(s + (i + 1) * step)) for i in range(n)]
        runs = split
    if order[0] == 'R-1':
        # A "LEGEND :" heading can reach into the window; the list starts at
        # the pale R-1 swatch, so anything inked above it is dropped.
        def pale_yellow(run):
            c = Counter(pack(region[run[0] + 2: run[1] - 2, 3:-3]).ravel().tolist()).most_common(1)[0][0]
            r, g, b = c >> 16, (c >> 8) & 255, c & 255
            return r >= 0xf0 and g >= 0xf0 and 0xc0 <= b < 0xf0
        while runs and not pale_yellow(runs[0]):
            runs.pop(0)
    if len(runs) != len(order):
        raise ValueError(f'legend has {len(runs)} swatch rows, expected {len(order)}')
    legend = {}
    for code, (s, e) in zip(order, runs):
        sw = region[s:e]
        k = pack(sw)
        inner = Counter(k[2:-2, 2:-2].ravel().tolist())
        edge = Counter(np.concatenate([k[0], k[-1], k[:, 0], k[:, -1]]).tolist())
        total = sum(inner.values())
        fill = inner.most_common(1)[0][0]
        pattern = [c for c, n in inner.items() if c != fill and n / total >= 0.08 and c != WHITE]
        e_top, e_n = edge.most_common(1)[0]
        outline = e_top if e_top != fill and e_n / sum(edge.values()) >= 0.4 and e_top not in pattern else None
        legend[code] = {'fill': fill, 'pattern': pattern, 'outline': outline}
    return legend


def legend_for(name: str, a: np.ndarray) -> dict[str, dict]:
    if name == 'Santulan':
        return {
            code: {'fill': int(pack(np.array(c[0]))), 'pattern': [], 'outline': None}
            for code, c in SANTULAN_COLOURS.items()
        }
    if name == 'Tañong':
        return read_legend(a, LEGEND_ORDERS[name], 1375, 1410, 695, 965)
    return read_legend(a, LEGEND_ORDERS.get(name, LEGEND_ORDER), 1352, 1400, 675, 970)


# ─── raster helpers ──────────────────────────────────────────────────────────

def box_mean(m: np.ndarray, r: int) -> np.ndarray:
    """Mean of a boolean/float image over a (2r+1)² window, edges clamped."""
    p = np.pad(m.astype(np.float32), r + 1, mode='edge')
    c = p.cumsum(0).cumsum(1)
    n = 2 * r + 1
    s = c[n:, n:] - c[:-n, n:] - c[n:, :-n] + c[:-n, :-n]
    return s[: m.shape[0], : m.shape[1]] / (n * n)


def components(lab: np.ndarray) -> tuple[np.ndarray, int]:
    """4-connected components of equal value. Run-length union-find."""
    h, w = lab.shape
    parent: list[int] = []

    def find(i: int) -> int:
        while parent[i] != i:
            parent[i] = parent[parent[i]]
            i = parent[i]
        return i

    run_rows = []
    prev: list[tuple[int, int, int, int]] = []
    for y in range(h):
        row = lab[y]
        cuts = np.flatnonzero(np.diff(row)) + 1
        starts = np.concatenate([[0], cuts])
        ends = np.concatenate([cuts, [w]])
        cur = []
        j = 0
        for s, e in zip(starts.tolist(), ends.tolist()):
            v = int(row[s])
            rid = len(parent)
            parent.append(rid)
            while j < len(prev) and prev[j][1] <= s:
                j += 1
            k = j
            while k < len(prev) and prev[k][0] < e:
                if prev[k][2] == v:
                    a, b = find(prev[k][3]), find(rid)
                    if a != b:
                        parent[max(a, b)] = min(a, b)
                k += 1
            cur.append((s, e, v, rid))
        run_rows.append(cur)
        prev = cur
    roots = {}
    out = np.empty((h, w), dtype=np.int32)
    for y, cur in enumerate(run_rows):
        for s, e, _v, rid in cur:
            r = find(rid)
            if r not in roots:
                roots[r] = len(roots)
            out[y, s:e] = roots[r]
    return out, len(roots)


def neighbour_counts(comp: np.ndarray, lab: np.ndarray) -> dict[int, Counter]:
    """For each component, how many 4-neighbour pixel contacts it has with each label."""
    pairs = []
    for a, b, la, lb in (
        (comp[:, :-1], comp[:, 1:], lab[:, :-1], lab[:, 1:]),
        (comp[:-1, :], comp[1:, :], lab[:-1, :], lab[1:, :]),
    ):
        d = a != b
        pairs.append(np.stack([a[d], lb[d]], 1))
        pairs.append(np.stack([b[d], la[d]], 1))
    p = np.concatenate(pairs)
    keys, n = np.unique(p, axis=0, return_counts=True)
    out: dict[int, Counter] = defaultdict(Counter)
    for (c, l), k in zip(keys.tolist(), n.tolist()):
        out[c][l] += k
    return out


# ─── classification ──────────────────────────────────────────────────────────

OUTSIDE = -1
NONE = 0
#: Classes drawn as a pattern of two colours rather than a flat fill.
PATTERNED = {'CMP', 'CBD', 'GENERAL-COMMERCIAL'}


def classify(frame: np.ndarray, legend: dict, codes: list[str], tol: int) -> tuple[np.ndarray, list[str]]:
    """Label image: 0 = no zone, i+1 = labels[i]. R-2 Basic/Max come back merged as 'R-2'."""
    k = pack(frame)
    rgb = frame.astype(np.int16)

    def match(c: int) -> np.ndarray:
        if tol == 0:
            return k == c
        cr, cg, cb = (c >> 16) & 255, (c >> 8) & 255, c & 255
        return (np.abs(rgb[..., 0] - cr) <= tol) & (np.abs(rgb[..., 1] - cg) <= tol) & (np.abs(rgb[..., 2] - cb) <= tol)

    # R-2 Basic and R-2 Max share a fill; they are one class until the outline
    # splits them.
    groups: dict[str, list[int]] = {}
    for code in codes:
        if code not in legend:
            continue
        sig = legend[code]
        key = 'R-2' if code in ('R-2-BASIC', 'R-2-MAX') else code
        cols = [sig['fill'], *sig['pattern']]
        groups.setdefault(key, [])
        for c in cols:
            if c not in groups[key]:
                groups[key].append(c)
    labels = list(groups)
    owners = Counter(c for cols in groups.values() for c in set(cols))
    scores = []
    for key in labels:
        cols = groups[key]
        m = np.zeros(k.shape, bool)
        for c in cols:
            m |= match(c)
        distinct = np.zeros(k.shape, bool)
        for c in cols:
            if owners[c] == 1 and c != groups[key][0]:
                distinct |= match(c)
        s = box_mean(m, WINDOW)
        if distinct.any():
            s = s + 2 * box_mean(distinct, WINDOW)
        if key in PATTERNED:
            # A hatch or dot pattern is only that class where ALL its colours
            # are present nearby. Otherwise the yellow outline R-2 Max draws
            # along a road reads as a strip of CMP hatching.
            for c in cols:
                s = np.where(box_mean(match(c), 2 * WINDOW) >= 0.02, s, 0)
        # A class that is only a pattern over a shared fill (CBD over C-2's red)
        # must show its pattern; without this a plain C-2 block ties with CBD.
        s = s - 0.01 * len(cols)
        scores.append(s)
    if not scores:
        return np.zeros(k.shape, np.int32), labels
    st = np.stack(scores)
    best = st.argmax(0)
    lab = np.where(st.max(0) >= 0.25, best + 1, NONE).astype(np.int32)
    return lab, labels


def close_roads(frame: np.ndarray, lab: np.ndarray, steps: int) -> np.ndarray:
    """Let zones grow a few pixels into unzoned ground that is not water.

    Streets are printed over the zones as white and grey bands, so the traced
    zones came out cut into blocks by hairline gaps: a pin on the road outside
    a shop fell in no zone, and the layer looked cracked. Growing each zone
    `steps` pixels closes streets up to twice that wide, meeting at the middle
    between two zones. Rivers (the sheets' lavender blue) and wide unzoned
    ground stay open; only their edges take the margin.
    """
    r, g, b = (frame[..., i].astype(np.int16) for i in range(3))
    water = (b - r > 25) & (b - g > 10) & (r > 60)
    open_ = (lab == NONE) & ~water
    for _ in range(steps):
        grow = np.zeros_like(lab)
        for dy, dx in ((0, 1), (0, -1), (1, 0), (-1, 0)):
            src = np.roll(lab, (dy, dx), axis=(0, 1))
            take = (grow == 0) & (src > 0)
            grow = np.where(take, src, grow)
        fill = open_ & (grow > 0)
        if not fill.any():
            break
        lab = np.where(fill, grow, lab)
        open_ &= ~fill
    return lab


def clean(lab: np.ndarray, min_px: int, hole_px: int, isolated_px: int) -> np.ndarray:
    """Absorb small patches into their surroundings: speckle below `min_px`,
    and enclosed unzoned holes below `hole_px` — lettering, and blocks of
    parcel linework so dense no colour won the window. A hole that reaches the
    barangay's edge is open ground, not a hole, and stays."""
    for _ in range(4):
        comp, n = components(lab)
        area = np.bincount(comp.ravel(), minlength=n)
        comp_label = np.zeros(n, np.int32)
        comp_label[comp.ravel()] = lab.ravel()
        small = np.where(comp_label == NONE, area < hole_px, area < min_px) & (comp_label != OUTSIDE)
        nb = neighbour_counts(comp, lab)
        # A small zone standing alone in unzoned ground is a road remnant or
        # a casing that matched a legend grey, not a zone: real small zones
        # (a chapel, a pumping station) sit inside other zones.
        for c in np.flatnonzero((comp_label > 0) & (area < isolated_px) & ~small).tolist():
            cnt = nb.get(c, Counter())
            tot = sum(cnt.values())
            if tot and (cnt.get(NONE, 0) + cnt.get(OUTSIDE, 0)) / tot >= 0.7:
                comp_label[c] = NONE
        if not small.any():
            lab = comp_label[comp]
            break

        new_label = comp_label.copy()
        changed = False
        for c in np.flatnonzero(small).tolist():
            cnt = nb.get(c)
            if not cnt:
                continue
            # A hole touching the outside of the barangay is not a hole.
            if comp_label[c] == NONE and cnt.get(OUTSIDE, 0) > 0:
                continue
            cand = [(n_, l) for l, n_ in cnt.items() if l != OUTSIDE and l != comp_label[c]]
            if not cand:
                continue
            new_label[c] = max(cand)[1]
            changed = True
        lab = new_label[comp]
        if not changed:
            break

    return lab


# ─── removing road symbols ───────────────────────────────────────────────────

def open_labels(lab: np.ndarray, r: int) -> np.ndarray:
    """Morphological opening per zone: parts narrower than 2r+1 pixels go.

    Catches a road symbol that touches a real zone of the same colour, which
    the per-patch tests below cannot, because joined to the zone it is no
    longer a thin patch of its own (Potrero's cased red boundary road runs
    straight into its C-2 strip).
    """
    out = lab.copy()
    for v in np.unique(lab):
        if v <= 0:
            continue
        m = lab == v
        eroded = box_mean(m, r) > 0.999
        kept = box_mean(eroded, r) > 0
        out[m & ~kept] = NONE
    return out


def drop_cased_bands(frame: np.ndarray, lab: np.ndarray) -> np.ndarray:
    """Unzone pixels that sit in a narrow band between dark casings.

    In a 9×9 window, a road symbol's own colour is the minority and dark
    casing is plentiful on both sides; inside a zone, the zone's colour wins.
    Block edges beside a casing are trimmed too, and grow back when roads
    are closed.
    """
    rgb = frame.astype(np.int16)
    dark = (rgb.max(axis=2) < 0x90) & (rgb.max(axis=2) - rgb.min(axis=2) < 16)
    dark_share = box_mean(dark, 4)
    out = lab.copy()
    for v in np.unique(lab):
        if v <= 0:
            continue
        m = lab == v
        band = m & (box_mean(m, 4) <= 0.6) & (dark_share >= 0.3)
        out[band] = NONE
    return out


def drop_cased(frame: np.ndarray, lab: np.ndarray, max_width_px: float) -> np.ndarray:
    """Unzone patches that are really a road symbol.

    Tonsuya, Catmon and Santulan print a PROPOSED ROAD as a red band cased in
    dark grey, and its red is C-2's red. A zone is bordered by other zones,
    roads and parcel lines; only the road symbol is bordered almost entirely
    by that dark casing, and only it is also a band a few metres wide.
    Both are required: Acacia draws its parcel lines in the same dark grey, and
    without the width test every walled block there reads as a road.
    """
    rgb = frame.astype(np.int16)
    dark = (rgb.max(axis=2) < 0x90) & (rgb.max(axis=2) - rgb.min(axis=2) < 16)
    comp, n = components(lab)
    comp_label = np.zeros(n, np.int32)
    comp_label[comp.ravel()] = lab.ravel()
    area = np.bincount(comp.ravel(), minlength=n)
    nb = neighbour_counts(comp, np.where(dark, 1, 0))
    cased = np.zeros(n, bool)
    for c, cnt in nb.items():
        tot = sum(cnt.values())
        width = 2 * area[c] / max(tot, 1)
        cased[c] = comp_label[c] > 0 and tot > 0 and cnt[1] / tot >= 0.6 and width < max_width_px
    return np.where(cased[comp], NONE, lab)


def drop_slivers(lab: np.ndarray, min_width_px: float, strict: set[int] = frozenset(), strict_px: float = 0) -> np.ndarray:
    """Unzone patches narrower on average than `min_width_px`.

    Some sheets print a proposed road as a red band and draw road casings in
    the same grey as Utilities, so a street comes out as a long thin C-2 or
    Utilities zone. No zone on these sheets is that thin; a street is. Mean
    width is 2 × area / perimeter, which a strip of any length measures
    honestly and a compact block of the same area passes easily.

    `strict` labels (Utilities, whose grey is also the road-casing grey on
    every sheet) must be wider still: Catmon's proposed road left its casing
    behind as two grey ribbons that would otherwise read as utility zones.
    """
    comp, n = components(lab)
    area = np.bincount(comp.ravel(), minlength=n)
    comp_label = np.zeros(n, np.int32)
    comp_label[comp.ravel()] = lab.ravel()
    perim = np.zeros(n, np.int64)
    for a_, b_ in ((comp[:, :-1], comp[:, 1:]), (comp[:-1, :], comp[1:, :])):
        d = a_ != b_
        np.add.at(perim, a_[d], 1)
        np.add.at(perim, b_[d], 1)
    width = 2 * area / np.maximum(perim, 1)
    need = np.where(np.isin(comp_label, list(strict)), max(min_width_px, strict_px), min_width_px)
    thin = (comp_label > 0) & (width < need)
    return np.where(thin[comp], NONE, lab)


# ─── tracing with shared arcs ────────────────────────────────────────────────

def trace(lab: np.ndarray, value: int) -> list[tuple[list[tuple[int, int]], int]]:
    """Closed rings around pixels == value, as lattice vertices, each with the
    component id of the pixel on its inside. Outers run clockwise on screen,
    holes anticlockwise; pinch points turn right, so rings follow 4-connectivity."""
    h, w = lab.shape
    m = np.pad(lab == value, 1)
    inside = m[1:-1, 1:-1]
    nxt: dict[tuple[int, int], list[tuple[int, int]]] = defaultdict(list)
    owner: dict[tuple[tuple[int, int], tuple[int, int]], tuple[int, int]] = {}
    ys, xs = np.nonzero(inside & ~m[:-2, 1:-1])
    for y, x in zip(ys.tolist(), xs.tolist()):
        nxt[(x, y)].append((x + 1, y)); owner[((x, y), (x + 1, y))] = (x, y)
    ys, xs = np.nonzero(inside & ~m[1:-1, 2:])
    for y, x in zip(ys.tolist(), xs.tolist()):
        nxt[(x + 1, y)].append((x + 1, y + 1)); owner[((x + 1, y), (x + 1, y + 1))] = (x, y)
    ys, xs = np.nonzero(inside & ~m[2:, 1:-1])
    for y, x in zip(ys.tolist(), xs.tolist()):
        nxt[(x + 1, y + 1)].append((x, y + 1)); owner[((x + 1, y + 1), (x, y + 1))] = (x, y)
    ys, xs = np.nonzero(inside & ~m[1:-1, :-2])
    for y, x in zip(ys.tolist(), xs.tolist()):
        nxt[(x, y + 1)].append((x, y)); owner[((x, y + 1), (x, y))] = (x, y)

    comp, _ = components(np.where(lab == value, 1, 0))
    rings = []
    while nxt:
        start = next(iter(nxt))
        first = nxt[start].pop()
        if not nxt[start]:
            del nxt[start]
        px = owner[(start, first)]
        ring = [start]
        a, b = start, first
        while True:
            ring.append(b)
            outs = list(nxt.get(b, []))
            if b == start:
                outs.append(first)
            d = (b[0] - a[0], b[1] - a[1])
            chosen = None
            for p in ((-d[1], d[0]), d, (d[1], -d[0])):  # right, straight, left
                cand = (b[0] + p[0], b[1] + p[1])
                if cand in outs:
                    chosen = cand
                    break
            if chosen is None:
                raise RuntimeError('open boundary while tracing')
            if b == start and chosen == first:
                break
            nxt[b].remove(chosen)
            if not nxt[b]:
                del nxt[b]
            a, b = b, chosen
        rings.append((ring[:-1], int(comp[px[1], px[0]])))
    return rings


def drop_collinear(ring: list[tuple[int, int]], fixed: set) -> list[tuple[int, int]]:
    out = []
    n = len(ring)
    for i in range(n):
        p, c, q = ring[i - 1], ring[i], ring[(i + 1) % n]
        if c in fixed or (c[0] - p[0]) * (q[1] - c[1]) != (c[1] - p[1]) * (q[0] - c[0]):
            out.append(c)
    return out


def dp(pts: list[tuple[int, int]], eps: float) -> list[tuple[int, int]]:
    if len(pts) < 3:
        return pts
    a, b = np.array(pts[0], float), np.array(pts[-1], float)
    arr = np.array(pts, float)
    ab = b - a
    L = math.hypot(*ab)
    if L == 0:
        d = np.hypot(*(arr - a).T)
    else:
        d = np.abs(ab[0] * (arr[:, 1] - a[1]) - ab[1] * (arr[:, 0] - a[0])) / L
    i = int(d.argmax())
    if d[i] <= eps:
        return [pts[0], pts[-1]]
    left = dp(pts[: i + 1], eps)
    return left[:-1] + dp(pts[i:], eps)


class ArcSimplifier:
    """Simplify each shared stretch of boundary once, so both sides agree."""

    def __init__(self, eps: float, junctions: set):
        self.eps = eps
        self.junctions = junctions
        self.cache: dict[tuple, list] = {}

    def arc(self, seq: list[tuple[int, int]]) -> list[tuple[int, int]]:
        t = tuple(seq)
        r = t[::-1]
        if r < t:
            return self.arc_canon(r)[::-1]
        return self.arc_canon(t)

    def arc_canon(self, t: tuple) -> list:
        if t not in self.cache:
            self.cache[t] = dp(list(t), self.eps)
        return self.cache[t]

    def ring(self, ring: list[tuple[int, int]]) -> list[tuple[int, int]]:
        ring = drop_collinear(ring, self.junctions)
        n = len(ring)
        cuts = [i for i, v in enumerate(ring) if v in self.junctions]
        if not cuts:
            # No junction: cut at the smallest vertex and the vertex farthest
            # from it, both independent of direction and starting point.
            i0 = min(range(n), key=lambda i: ring[i])
            p0 = ring[i0]
            i1 = max(range(n), key=lambda i: ((ring[i][0] - p0[0]) ** 2 + (ring[i][1] - p0[1]) ** 2, ring[i]))
            cuts = sorted({i0, i1})
        out: list[tuple[int, int]] = []
        for j, c in enumerate(cuts):
            d = cuts[(j + 1) % len(cuts)]
            seq = ring[c: d + 1] if d > c else ring[c:] + ring[: d + 1]
            s = self.arc(seq)
            out.extend(s[:-1])
        return out


def junction_vertices(lab: np.ndarray) -> set:
    """Lattice vertices where three or more labels meet, or two meet diagonally."""
    p = np.pad(lab, 1, constant_values=OUTSIDE)
    a, b, c, d = p[:-1, :-1], p[:-1, 1:], p[1:, :-1], p[1:, 1:]
    distinct = 1 + (b != a) + ((c != a) & (c != b)) + ((d != a) & (d != b) & (d != c))
    diag = (a == d) & (b == c) & (a != b)
    ys, xs = np.nonzero((distinct >= 3) | diag)
    # p is padded by one, so vertex (x, y) of lab is p index (x, y) directly.
    return set(zip(xs.tolist(), ys.tolist()))


def ring_area(r) -> float:
    s = 0.0
    for i in range(len(r)):
        x1, y1 = r[i - 1]
        x2, y2 = r[i]
        s += x1 * y2 - x2 * y1
    return s / 2


# ─── one barangay ────────────────────────────────────────────────────────────

def process(name: str, sheet: dict, rings_ll: list, classes: dict, allowed: list[str], preview: Path | None):
    img = Image.open(PUBLIC / sheet['url'].lstrip('/')).convert('RGB')
    a = np.array(img)
    place = Placement(sheet)
    fx0, fy0, fx1, fy1 = sheet['frame']
    frame = a[fy0:fy1, fx0:fx1]

    legend = legend_for(name, a)
    tol = 40 if name == 'Santulan' else 0
    lab, labels = classify(frame, legend, allowed, tol)

    # Clip to the barangay polygon, in sheet pixels.
    mask = Image.new('L', (fx1 - fx0, fy1 - fy0), 0)
    draw = ImageDraw.Draw(mask)
    for i, ring in enumerate(rings_ll):
        pts = [place.to_px(lng, lat) for lng, lat in ring]
        draw.polygon([(x - fx0, y - fy0) for x, y in pts], fill=255 if i == 0 else 0)
    inside = np.array(mask) > 0
    raw_zone = lab > 0
    lab = np.where(inside, lab, OUTSIDE)

    # Final numbering. R-2 Basic and R-2 Max share one fill, and the only
    # printed difference, Max's yellow outline, survives on the sheets as
    # broken fragments along parcel lines rather than closed rings (checked on
    # Bayan-bayanan and Concepcion). Splitting on it would be a guess, so where
    # a barangay has both, the patch is labelled as both and the map says so.
    groups = []
    for key in labels:
        if key == 'R-2':
            groups.append([c for c in ('R-2-BASIC', 'R-2-MAX') if c in allowed])
        else:
            groups.append([key])
    final = drop_cased(frame, open_labels(drop_cased_bands(frame, lab), 2), 14.0 / place.m_per_px)
    steps = max(1, round(6.0 / place.m_per_px))
    final = np.where(inside, close_roads(frame, np.where(inside, final, OUTSIDE), steps), OUTSIDE)
    id_codes = {i + 1: g for i, g in enumerate(groups)}

    min_px = max(8, int(MIN_AREA_M2 / place.m_per_px ** 2))
    hole_px = int(MAX_HOLE_M2 / place.m_per_px ** 2)
    isolated_px = int(ISOLATED_M2 / place.m_per_px ** 2)
    final = clean(final, min_px, hole_px, isolated_px)
    grey = {v for v, g in id_codes.items() if 'UTILITIES' in g}
    final = drop_slivers(final, max(3.0, 5.0 / place.m_per_px), grey, 14.0 / place.m_per_px)
    final = clean(final, min_px, hole_px, isolated_px)

    px_area = place.m_per_px ** 2
    stats = {
        'inside_m2': float(inside.sum() * px_area),
        'traced_m2': float((final > 0).sum() * px_area),
        'lost_to_clip_m2': float((raw_zone & ~inside).sum() * px_area),
        'zones': {'|'.join(id_codes[v]): round(float((final == v).sum() * px_area)) for v in np.unique(final) if v > 0},
    }

    eps = max(0.8, min(2.5, SIMPLIFY_M / place.m_per_px))
    junctions = junction_vertices(final)
    simp = ArcSimplifier(eps, junctions)
    features = []
    for v in sorted(id_codes):
        if not (final == v).any():
            continue
        rings = trace(final, v)
        by_comp: dict[int, list] = defaultdict(list)
        for ring, comp in rings:
            by_comp[comp].append(ring)
        polys = []
        for comp, rs in by_comp.items():
            outers = [r for r in rs if ring_area(r) > 0]
            holes = [r for r in rs if ring_area(r) < 0]
            if len(outers) != 1:
                continue
            poly = []
            for r in [outers[0], *holes]:
                s = simp.ring(r)
                if len(s) < 3 or abs(ring_area(s)) * px_area < MIN_AREA_M2 / 2:
                    if r is outers[0]:
                        poly = []
                        break
                    continue
                ll = [place.to_lnglat(x + fx0, y + fy0) for x, y in s]
                ll = [[round(x, 6), round(y, 6)] for x, y in ll]
                # GeoJSON: outer anticlockwise, holes clockwise (RFC 7946 §3.1.6).
                # Screen-clockwise is map-anticlockwise once y points north.
                ll.append(ll[0])
                poly.append(ll)
            if poly:
                polys.append(poly)
        if polys:
            codes = id_codes[v]
            features.append({
                'type': 'Feature',
                'properties': {
                    'codes': codes,
                    'name': ' or '.join(classes[c]['name'] for c in codes),
                    # The database's legend colour, so the map and the
                    # classification list beside it draw the same swatch.
                    'color': classes[codes[0]]['color'],
                },
                'geometry': {'type': 'MultiPolygon', 'coordinates': polys},
            })

    features.sort(key=lambda f: min(classes[c]['order'] for c in f['properties']['codes']))
    fc = {'type': 'FeatureCollection', 'features': features}
    if preview is not None:
        render_preview(name, a, sheet, place, rings_ll, features, classes, preview)
    return fc, stats, legend


def render_preview(name, a, sheet, place, rings_ll, features, classes, out: Path):
    fx0, fy0, fx1, fy1 = sheet['frame']
    crop = Image.fromarray(a[fy0:fy1, fx0:fx1])
    W, H = crop.size
    base = Image.blend(crop, Image.new('RGB', crop.size, 'white'), 0.7)
    over = Image.new('RGBA', crop.size, (0, 0, 0, 0))
    d = ImageDraw.Draw(over)
    for f in features:
        col = classes[f['properties']['codes'][0]]['color'] or '#888888'
        rgb = tuple(int(col[i: i + 2], 16) for i in (1, 3, 5))
        for poly in f['geometry']['coordinates']:
            for i, ring in enumerate(poly):
                pts = [place.to_px(x, y) for x, y in ring]
                pts = [(x - fx0, y - fy0) for x, y in pts]
                d.polygon(pts, fill=(*rgb, 190) if i == 0 else (255, 255, 255, 200), outline=(40, 40, 40, 255))
    both = base.convert('RGBA')
    both.alpha_composite(over)
    for img in (crop, both):
        dd = ImageDraw.Draw(img)
        for ring in rings_ll:
            pts = [place.to_px(lng, lat) for lng, lat in ring]
            dd.line([(x - fx0, y - fy0) for x, y in pts] + [(pts[0][0] - fx0, pts[0][1] - fy0)], fill=(0, 37, 204), width=3)
    sheet_img = Image.new('RGB', (W * 2 + 20, H + 40), 'white')
    sheet_img.paste(crop, (0, 40))
    sheet_img.paste(both.convert('RGB'), (W + 20, 40))
    ImageDraw.Draw(sheet_img).text((10, 12), f'{name}: CPDO sheet (left) and traced zones (right). Blue = barangay polygon.', fill='black')
    out.mkdir(parents=True, exist_ok=True)
    sheet_img.save(out / f'{slugify(name)}.png')


def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    ap.add_argument('--only', action='append')
    ap.add_argument('--preview', type=Path)
    ap.add_argument('--dry-run', action='store_true', help='trace and report, write no GeoJSON')
    args = ap.parse_args()

    sheets = load_sheets()
    polys = load_polygons()
    classes, allowed = load_classes()
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    written: dict[str, str] = {}
    for name in sorted(sheets):
        if args.only and name not in args.only:
            continue
        slug = slugify(name)
        target = OUT_DIR / f'{slug}.geojson'
        try:
            fc, st, legend = process(name, sheets[name], polys[name], classes, allowed[name], args.preview)
        except ValueError as e:
            print(f'{name:14} FAILED  {e}')
            continue
        z = ', '.join(f'{k} {v / 1e4:.2f} ha' for k, v in sorted(st['zones'].items(), key=lambda kv: -kv[1]))
        verts = sum(len(r) for f in fc['features'] for p in f['geometry']['coordinates'] for r in p)
        cover = st['traced_m2'] / st['inside_m2'] if st['inside_m2'] else 0
        verdict = 'omitted: ' + OMIT[name] if name in OMIT else 'traced'
        print(f'{name:14} {verdict:8} cover {cover:4.0%}  clipped-off {st["lost_to_clip_m2"] / 1e4:5.2f} ha  '
              f'{verts:5} vertices  {z}')
        if args.dry_run:
            continue
        if name in OMIT:
            if target.exists():
                target.unlink()
            continue
        body = json.dumps(fc, separators=(',', ':'), ensure_ascii=False)
        target.write_text(body + '\n')
        written[name] = f'/zoning/{slug}.geojson'
    if not args.dry_run and not args.only:
        write_manifest(written)


def write_manifest(written: dict[str, str]) -> None:
    lines = [
        '/*',
        ' * GENERATED by scripts/trace-zoning-sheets.py. Do not edit; re-run the script.',
        ' *',
        ' * Which barangays have a traced zoning layer, and where it is. The layers',
        ' * themselves are fetched on demand from web/public/zoning/, never bundled:',
        ' * together they are ~0.5 MB and an applicant needs one.',
        ' *',
        ' * A barangay missing here has no credible tracing; the map shows no zoning',
        ' * layer for it rather than a wrong one. See the script for how and why.',
        ' */',
        '',
        '/** Keyed by barangay name as `malabonGeo.data.ts` spells it. */',
        'export const ZONING_LAYERS: Record<string, string> = {',
    ]
    lines += [f'  {json.dumps(n, ensure_ascii=False)}: {json.dumps(u)},' for n, u in sorted(written.items())]
    lines += ['}', '']
    MANIFEST.write_text('\n'.join(lines))


if __name__ == '__main__':
    sys.setrecursionlimit(100_000)
    sys.exit(main())
