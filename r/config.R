# config.R — mode switch + constants for the BizTrack R analytics prototype.
# Everything downstream (generator, SPC, DES, plumber) reads from here so the
# whole run is reproducible from a single seed. No renv for the prototype
# (speed); a renv lockfile comes later once the feature set stabilises.

suppressWarnings(suppressMessages(library(tibble)))

# --- Mode -------------------------------------------------------------------
# "synthetic" is the default and the only mode exercised in the prototype.
# "postgres" is reserved: R/db.R holds the real-schema readers, guarded so they
# refuse to run until the integration phase.
MODE <- "synthetic"

# --- Reproducibility --------------------------------------------------------
SEED   <- 1103   # same seed => identical CSVs, charts, and scenario numbers
MONTHS <- 36     # months of synthetic permit history to generate

# Fixed anchor for the "now" of the dataset. Hard-coded (not Sys.Date()) so the
# 36-month window, the injected-anomaly window, and every timestamp are
# deterministic across machines and days.
ANCHOR_DATE   <- as.Date("2026-06-30")
ANOMALY_WEEKS <- 6   # the injected CHO slowdown covers the most recent N weeks

# --- Departments ------------------------------------------------------------
# code, human name, and reviewer headcount (the DES resource counts + the
# lognormal service-time means the generator targets, in working days).
DEPARTMENTS <- tibble::tibble(
  code       = c("BPLO", "CHO", "BFP"),
  name       = c("Business Permits & Licensing Office",
                 "City Health Office",
                 "Bureau of Fire Protection"),
  reviewers  = c(3L, 2L, 2L),
  mean_days  = c(2.0, 2.5, 3.0)   # target mean review turnaround per department
)

# Inspector pools (used by DES as seizable resources).
INSPECTORS <- list(sanitary = 2L, fire = 2L)

# RA 11032 (Ease of Doing Business Act) working-day processing deadlines.
DEADLINES <- list(simple = 3L, complex = 7L, highly_technical = 20L)

# --- Geography --------------------------------------------------------------
# The 21 barangays of the City of Malabon. Weighted so a handful dominate the
# business population (realistic: commercial density is uneven).
BARANGAYS <- c(
  "Baritan", "Bayan-bayanan", "Catmon", "Concepcion", "Dampalit",
  "Flores", "Hulong Duhat", "Ibaba", "Longos", "Maysilo", "Muzon",
  "Niugan", "Panghulo", "Potrero", "San Agustin", "Santolan", "Tanong",
  "Tinajeros", "Tonsuya", "Tugatog", "Acacia"
)
# Weights: Longos / Potrero / Tanong / Tinajeros / Concepcion carry the bulk.
BARANGAY_WEIGHTS <- c(
  3, 4, 3, 8, 3, 2, 3, 2, 12, 4, 3,
  3, 4, 10, 3, 2, 9, 9, 4, 3, 2
)

# --- Paths ------------------------------------------------------------------
# Resolved relative to the r/ directory (callers setwd there or source from it).
DATA_DIR    <- "data"
OUTPUTS_DIR <- "outputs"
SPC_DIR     <- file.path(OUTPUTS_DIR, "spc")
DES_DIR     <- file.path(OUTPUTS_DIR, "des")

# --- DES run size + calibration ---------------------------------------------
# reps is the tuning knob for total runtime; 30 keeps run_all under ~3 min on a
# laptop. Drop to 15 if you need it faster; raise for tighter confidence bands.
DES_MONTHS <- 6L
DES_REPS   <- 30L

# Representative daily caseload the pipeline is simulated under. NOTE: the raw
# monthly volume the generator produces (~90/mo, higher in renewal season) would
# saturate the modelled office capacity (2–3 staff per stage) — which is exactly
# the capacity question the scenarios probe. We therefore calibrate DES arrivals
# to a stable representative caseload rather than peak volume, and flag
# seasonality-in-arrivals as future work. Tune this knob to move baseline
# compliance within the plausible 65–90% band.
DES_ARRIVALS_PER_DAY <- 0.85
