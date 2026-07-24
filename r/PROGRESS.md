# BizTrack R Analytics — Progress Log

Progress-tracking record for the R integration prototype (Group 12). Each entry
lists what was created/modified and the screenshots that evidence it.

---

## Entry — 2026-07-21 · Feature 6 Anomaly Detection surfaced in the GUI

### What changed (code)

| File | Change | Purpose |
| --- | --- | --- |
| `app.R` | **Modified** | Rebuilt the SPC tab into a **Permit Processing Time Anomaly Detection** view. Every row of the paper's "Information Displayed" table now has a labeled, visible GUI element. |
| `biztrack-r.Rproj` | **Created** | Makes `r/` open as a first-class RStudio project (working dir pinned, UTF-8, no stale workspace restore). |

### The 5 paper-table rows → GUI elements (in `app.R`)

| Paper table row | GUI element | Server output |
| --- | --- | --- |
| Department Processing Time Chart | control chart card (weekly avg + normal range band) | `spc_plot` |
| Process Status Indicator | green/red value box: *Within / Outside Normal Range* | `spc_status_box` |
| Flagged Weeks List | table with `week_start`, `mean_days`, **`days_beyond_range`**, `rule_hit` | `tbl_flags` |
| Gradual Slowdown Detector | value box: *Drift detected / No drift* (EWMA) | `spc_gradual_box` |
| Slowdown Alert | red banner with the notification message text | `spc_alert` |

All five recompute reactively when the **Department** dropdown changes
(`spc_state()` reactive), so CHO shows the anomalous state and BPLO/BFP show the
normal state.

### Environment fix (this session)

- RStudio uses the CRAN **Framework R** (`/Library/Frameworks/R.framework`),
  whose package library was empty → `library(dplyr)` failed in RStudio.
  Ran `install.R` against that R; all 16 packages installed as CRAN binaries.
  (Homebrew `Rscript` retains its own copy — packages now exist in both.)

### Screenshots to capture for this entry

> Run first: `cd r && Rscript run_all.R` then `Rscript run_app.R`
> (use the Framework R for RStudio parity — see command block at bottom).

1. **Package install proof** — terminal showing `install.R OK — 16 packages ready.`
2. **Pipeline run** — terminal output of `run_all.R`: the `[1/4]…[4/4]` stages,
   the RA 11032 compliance table, the `>>> The INJECTED CHO slowdown was DETECTED`
   block, the DES scenario deltas, and `DONE in …s`.
3. **Generated artifacts** — `ls data/ outputs/spc/ outputs/des/` showing the CSVs
   + PNG charts (proves the offline batch produced real files).
4. **CHO control chart PNG** — `outputs/spc/CHO_control_chart.png` (red
   out-of-control points in the injected window).
5. **DES comparison PNG** — `outputs/des/scenario_comparison.png`.
6. **GUI · Anomaly Detection tab, Department = CHO** — the money shot: all five
   panels visible at once — red *Outside Normal Range* box, red *Slowdown Alert*
   banner, the chart, the *Gradual Slowdown Detector* box, and the *Flagged Weeks
   List*.
7. **GUI · Anomaly Detection tab, Department = BPLO** — the contrast case: green
   *Within Normal Range*, calm "no active slowdown" banner, empty/short flagged
   list. (Shows the monitor doesn't just cry wolf.)
8. **GUI · Overview tab** — the value boxes (compliance, avg processing, volume,
   SPC anomaly weeks) + department workload table.
9. **GUI · DES tab** — the staffing scenario bar chart + utilisation table.
10. **GUI · Analytics tables tab** — the compliance + processing-time tables.
11. *(optional)* **RStudio** — `biztrack-r.Rproj` open with `app.R` and the
    ▶ Run App button, to evidence the RStudio integration.

---

## Commands used this session (for reproducing the screenshots)

```bash
cd /Users/kenmondragon/Documents/GitHub/biztrack/r

# Packages into the R that RStudio uses (once):
/Library/Frameworks/R.framework/Resources/bin/Rscript install.R

# Batch pipeline → data/ + outputs/ (shots 1–5):
/Library/Frameworks/R.framework/Resources/bin/Rscript run_all.R

# Dashboard (shots 6–10) → http://localhost:8788
lsof -ti tcp:8788 | xargs kill -9 2>/dev/null    # clear stale instance
/Library/Frameworks/R.framework/Resources/bin/Rscript run_app.R
```
