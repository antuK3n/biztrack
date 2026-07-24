# BizTrack R Analytics Prototype

Implements the **R integration paper's** two headline features plus the shared
data generator they all depend on:

- **Feature 6 — Discrete-Event Simulation** (`R/des.R`): a `simmer` model of the
  permit pipeline that answers staffing questions ("+2 BFP inspectors → ?").
- **Feature 7 — Statistical Process Control** (`R/spc.R`): per-department X-bar
  control charts that catch a deliberately injected processing slowdown.
- **Shared synthetic generator** (`R/generate.R`): 36 months of believable
  permit history — the data every paper feature is built on.

**Synthetic mode is the default** and needs no database and no internet (after
package install). PostgreSQL readers against the real 35-table schema are
stubbed in `R/db.R` for the later integration phase.

## Run it

```bash
cd r
Rscript install.R      # one-shot; second run is a no-op
Rscript run_all.R      # the whole demo, < ~3 min on a laptop
```

`run_all.R` generates the data, runs the analytics trio, writes the SPC charts
and DES scenarios, and produces a standalone `outputs/summary.md`.

## Dashboard (Shiny)

An interactive dashboard over the results — the conventional R way to present an
analysis. Run `run_all.R` first (it produces the data the app reads), then:

```bash
Rscript run_app.R              # serves on http://localhost:8788
```

Tabs: **Overview** (headline figures + workload), **SPC** (live per-department
control chart + flagged weeks), **DES** (scenario comparison + utilisation),
**Analytics tables**. The SPC chart re-renders on department selection; the DES
results are read from the batch run (the heavy simulation stays offline).

Optional API (serves the precomputed results Laravel will consume):

```bash
Rscript run_api.R                          # starts Plumber on :8787
curl localhost:8787/health
curl localhost:8787/spc/flags
curl localhost:8787/des/scenarios
```

## What each output proves

| Artifact | Proves |
| --- | --- |
| `data/*.csv` (5 files) | schema-aligned synthetic history, reproducible from seed 1103 |
| `outputs/spc/CHO_control_chart.png` | the injected CHO slowdown is caught statistically (points beyond UCL) |
| `outputs/spc/{BPLO,BFP}_control_chart.png` | unaffected departments stay in control |
| `outputs/spc/flags.csv` | every weekly point classified in/out of control |
| `outputs/des/scenario_comparison.png` | staffing scenarios ranked by RA 11032 on-time compliance |
| `outputs/des/scenarios.csv` | per-scenario compliance, utilisation, queue, wait |
| `outputs/summary.md` | the whole run, self-documented and reproducible |

## Files

```
r/
├── install.R      # install-if-missing package installer (+ macOS C-std preflight)
├── run_all.R      # the one-command demo
├── app.R          # Shiny dashboard (interactive UI over the results)
├── run_app.R      # starts the Shiny dashboard on :8788
├── run_api.R      # starts Plumber on :8787
├── config.R       # mode switch + constants (seed, months, departments, deadlines)
├── R/
│   ├── generate.R # shared synthetic history generator
│   ├── db.R       # postgres readers (guarded stubs — future mode)
│   ├── analytics.R# shared functions named in the paper
│   ├── spc.R      # Feature 7 — statistical process control
│   └── des.R      # Feature 6 — discrete-event simulation
├── plumber.R      # API surface (serves precomputed artifacts)
├── data/          # generated CSVs (gitignored)
└── outputs/       # charts + result CSVs + summary (gitignored)
```

## Reproducibility & tuning

- Everything derives from `SEED <- 1103` in `config.R`; same seed → identical
  numbers, charts, and scenario results.
- `DES_REPS` (default 30) trades runtime for tighter confidence bands.
- `DES_ARRIVALS_PER_DAY` calibrates the simulated caseload (see the comment in
  `config.R` for why this is a representative rate, not raw peak volume).
- No `renv` in the prototype (speed); a lockfile comes with the integration phase.

## Notes

- macOS: if a Homebrew R build hard-codes `-std=gnu23` but your Apple clang is
  ≤ 16, `install.R` auto-pins `CC = clang -std=gnu17` in `~/.R/Makevars`.
- Out of scope for the prototype (see paper §J): live Postgres, seasonality in
  DES arrivals, RMarkdown, forecasting, `renv`, scheduling. (A Shiny dashboard
  was added for the progress report — see above.)
```
