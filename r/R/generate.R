# R/generate.R — the shared synthetic history generator.
#
# This is the generator the whole paper team depends on. It settles the
# "simulate data into the system" reading of Feature 6: two simulations now
# exist — this one manufactures believable *data*; DES (des.R) manufactures
# *answers*. Every realism rule below is deliberate and commented so the
# manuscript can cite it.
#
# One entry point: generate_history(months, seed) -> writes five CSVs to data/
# and returns them as a named list of tibbles.

suppressWarnings(suppressMessages({
  library(dplyr)
  library(tidyr)
  library(readr)
  library(lubridate)
  library(tibble)
}))

# --- helpers ----------------------------------------------------------------

# Add `n` working days (Mon–Fri) to each Date in `from`. Vectorised over both
# `from` and `n` (recycled). Used for RA 11032 deadline arithmetic.
add_working_days <- function(from, n) {
  n <- rep_len(n, length(from))
  out <- from
  for (i in seq_along(from)) {
    d <- from[i]; added <- 0L
    while (added < n[i]) {
      d <- d + 1L
      if (as.integer(format(d, "%u")) <= 5L) added <- added + 1L  # 1=Mon..7=Sun
    }
    out[i] <- d
  }
  out
}

# Lognormal draws whose *arithmetic mean* is `mean_target` given a chosen
# `sdlog` (shape). meanlog is back-solved so mean(X) == mean_target. Returns a
# numeric vector of length n. Used for department review durations (in days).
rlnorm_mean <- function(n, mean_target, sdlog = 0.4) {
  meanlog <- log(mean_target) - (sdlog^2) / 2
  rlnorm(n, meanlog = meanlog, sdlog = sdlog)
}

# Seasonal multiplier on monthly application volume.
#   January  x2.5, February x1.6 (renewal season), December x0.7, else x1.
season_multiplier <- function(month_num) {
  dplyr::case_when(
    month_num == 1 ~ 2.5,
    month_num == 2 ~ 1.6,
    month_num == 12 ~ 0.7,
    TRUE ~ 1.0
  )
}

# --- entry point ------------------------------------------------------------

#' Generate synthetic permit history
#'
#' Produces 36 (default) months of believable BizTrack permit-processing
#' history — businesses, applications, department review assignments,
#' inspections, and issued permits — with realistic seasonality, RA 11032
#' deadlines, lognormal service times, and one deliberately injected CHO
#' slowdown for SPC to catch.
#'
#' @param months Integer number of months of history to generate.
#' @param seed   Integer RNG seed; same seed reproduces identical output.
#' @return Named list of tibbles: businesses, applications, assignments,
#'   inspections, permits. Also written as CSVs to data/.
generate_history <- function(months = MONTHS, seed = SEED) {
  set.seed(seed)
  if (!dir.exists(DATA_DIR)) dir.create(DATA_DIR, recursive = TRUE)

  anchor      <- ANCHOR_DATE
  start_month <- floor_date(anchor %m-% months(months - 1), "month")
  # Injected-anomaly window: the most recent ANOMALY_WEEKS full weeks.
  anomaly_start <- anchor - ANOMALY_WEEKS * 7L

  # ---- 1. Per-month application volume (seasonal + noise) ------------------
  month_starts <- seq(start_month, by = "month", length.out = months)
  base_vol     <- 90
  volumes <- vapply(month_starts, function(ms) {
    mult  <- season_multiplier(month(ms))
    noise <- rnorm(1, mean = 1, sd = 0.10)          # +/-10% month-to-month
    max(10L, as.integer(round(base_vol * mult * noise)))
  }, numeric(1))
  n_total <- sum(volumes)

  # ---- 2. Applications: submitted dates + type + complexity ----------------
  submitted_date <- as.Date(unlist(mapply(function(ms, k) {
    dim <- as.integer(days_in_month(ms))
    as.numeric(ms + sample.int(dim, k, replace = TRUE) - 1L)
  }, month_starts, volumes, SIMPLIFY = FALSE)), origin = "1970-01-01")
  submitted_month <- floor_date(submitted_date, "month")

  # Drop anything that would land after the anchor "now".
  keep            <- submitted_date <= anchor
  submitted_date  <- submitted_date[keep]
  submitted_month <- submitted_month[keep]
  n_total         <- length(submitted_date)

  # Type mix depends on the season: Jan/Feb are renewal-heavy.
  is_renewal_season <- month(submitted_month) %in% c(1, 2)
  application_type <- vapply(seq_len(n_total), function(i) {
    if (is_renewal_season[i]) {
      sample(c("renewal", "new", "amendment"), 1, prob = c(0.70, 0.20, 0.10))
    } else {
      sample(c("new", "renewal", "amendment"), 1, prob = c(0.55, 0.35, 0.10))
    }
  }, character(1))

  # Complexity -> RA 11032 deadline: new => complex (7 wd), else simple (3 wd).
  complexity   <- ifelse(application_type == "new", "complex", "simple")
  deadline_wd  <- ifelse(complexity == "complex",
                         DEADLINES$complex, DEADLINES$simple)

  # Submitted timestamp: a business hour (08:00–16:00) on the submitted date.
  submitted_at <- as.POSIXct(submitted_date) +
    (8 * 3600) + runif(n_total, 0, 8 * 3600)
  deadline_at  <- as.POSIXct(add_working_days(submitted_date, deadline_wd)) +
    (17 * 3600)   # close of business on the deadline day

  order_idx    <- order(submitted_at)  # chronological ids read naturally

  applications <- tibble(
    id               = seq_len(n_total),
    application_type = application_type,
    complexity       = complexity,
    submitted_at     = submitted_at,
    deadline_at      = deadline_at
  )[order_idx, ] |>
    mutate(id = seq_len(n_total))
  # Re-derive helpers in chronological order.
  submitted_at <- applications$submitted_at
  submitted_date <- as.Date(submitted_at)
  deadline_at  <- applications$deadline_at
  application_type <- applications$application_type
  complexity   <- applications$complexity

  # ---- 3. Businesses + barangay geography ---------------------------------
  n_business <- max(1L, as.integer(ceiling(n_total * 0.55)))
  # Zipf-ish reuse so some businesses renew many times over 3 years.
  biz_weights <- 1 / (seq_len(n_business))^0.6
  business_id <- sample(seq_len(n_business), n_total, replace = TRUE,
                        prob = biz_weights)

  businesses <- tibble(
    id       = seq_len(n_business),
    barangay = sample(BARANGAYS, n_business, replace = TRUE,
                      prob = BARANGAY_WEIGHTS)
  ) |>
    mutate(name = sprintf("%s Enterprises %04d", barangay, id))
  # registered_at: sometime before the business's first application.
  first_app <- tibble(business_id = business_id, submitted_date = submitted_date) |>
    group_by(business_id) |>
    summarise(first_date = min(submitted_date), .groups = "drop")
  businesses <- businesses |>
    left_join(first_app, by = c("id" = "business_id")) |>
    mutate(
      first_date    = as.Date(ifelse(is.na(first_date), start_month, first_date),
                              origin = "1970-01-01"),
      registered_at = first_date - as.integer(runif(n(), 30, 400))
    ) |>
    dplyr::select(id, name, barangay, registered_at)

  # ---- 4. Assignments: department reviews ----------------------------------
  # New (complex) applications fan out to three PARALLEL department reviews
  # (BPLO + CHO + BFP). Renewals/amendments (simple) are lighter-touch — a BPLO
  # re-validation only, no health/fire review or re-inspection — matching real
  # LGU practice and letting them realistically meet the tight 3-day deadline.
  dept_mean   <- setNames(DEPARTMENTS$mean_days, DEPARTMENTS$code)
  complex_ids <- applications$id[applications$complexity == "complex"]

  assign_grid <- bind_rows(
    tibble(application_id = applications$id, department_code = "BPLO"),
    tidyr::expand_grid(application_id = complex_ids,
                       department_code = c("CHO", "BFP"))
  )

  assignments <- assign_grid |>
    left_join(applications |> dplyr::select(id, submitted_at),
              by = c("application_id" = "id")) |>
    mutate(
      # Payment/queue delay before a reviewer picks it up: same/next business
      # day (~0.25–1.25 days).
      assigned_at = submitted_at + runif(n(), 0.25, 1.25) * 86400,
      base_days   = rlnorm_mean(n(), dept_mean[department_code], sdlog = 0.40),
      # 10% suffer a returned -> resubmitted loop adding 2–5 days.
      loop_days   = ifelse(runif(n()) < 0.10, runif(n(), 2, 5), 0),
      # THE INJECTED ANOMALY: CHO reviews in the most recent 6 weeks run x1.8
      # slower. This is the slowdown SPC must catch — injected on purpose,
      # documented here, and announced in the console so the prof sees it was
      # *found*, not faked.
      anomaly_mult = ifelse(department_code == "CHO" &
                              as.Date(assigned_at) >= anomaly_start, 1.8, 1.0),
      service_days = (base_days + loop_days) * anomaly_mult,
      completed_at = assigned_at + service_days * 86400
    )
  assignments <- assignments |>
    arrange(assigned_at) |>
    mutate(id = row_number()) |>
    dplyr::select(id, application_id, department_code, assigned_at, completed_at)

  # When did each application clear all three reviews?
  review_done <- assignments |>
    group_by(application_id) |>
    summarise(reviews_done_at = max(completed_at), .groups = "drop")

  # ---- 5. Inspections: CHO (sanitary) + BFP (fire) -------------------------
  insp_specs <- tibble(department_code = c("CHO", "BFP"))
  assign_done <- assignments |>
    filter(department_code %in% c("CHO", "BFP")) |>
    group_by(application_id, department_code) |>
    summarise(review_at = max(completed_at), .groups = "drop")

  # Conduct duration is department-specific: fire safety inspections take longer
  # (site walk-through + equipment checks) than sanitary. These feed the DES
  # service-time fits, where fire inspection is a genuine bottleneck the "+2 BFP
  # inspectors" scenario relieves.
  insp_shape <- c(CHO = 3.0, BFP = 3.0)
  insp_scale <- c(CHO = 0.5, BFP = 0.83)   # means ~1.5 (sanitary) / ~2.5 (fire)
  inspections <- assign_done |>
    mutate(
      scheduled_at = review_at + runif(n(), 0.5, 2.0) * 86400,   # scheduling lag
      conduct_days = rgamma(n(), shape = insp_shape[department_code],
                            scale = insp_scale[department_code]),
      conducted_at = scheduled_at + conduct_days * 86400,
      result = sample(c("passed", "conditional", "failed"), n(),
                      replace = TRUE, prob = c(0.82, 0.13, 0.05))
    ) |>
    arrange(scheduled_at) |>
    mutate(id = row_number()) |>
    dplyr::select(id, application_id, department_code, scheduled_at, conducted_at, result)

  # ---- 6. Outcomes: status + approval timestamp ---------------------------
  # Last inspection wrap-up drives the earliest possible approval.
  insp_done <- inspections |>
    group_by(application_id) |>
    summarise(insp_done_at = max(conducted_at), .groups = "drop")
  outcome <- applications |>
    left_join(review_done, by = c("id" = "application_id")) |>
    left_join(insp_done,   by = c("id" = "application_id")) |>
    mutate(
      pipeline_done = pmax(reviews_done_at, insp_done_at, na.rm = TRUE),
      recent = submitted_at > (as.POSIXct(anchor) - 25 * 86400)
    )

  status <- vapply(seq_len(nrow(outcome)), function(i) {
    if (outcome$recent[i]) {
      sample(c("approved", "in_flight", "rejected"), 1,
             prob = c(0.50, 0.45, 0.05))
    } else {
      sample(c("approved", "rejected"), 1, prob = c(0.93, 0.07))
    }
  }, character(1))

  approved_at <- as.POSIXct(rep(NA_real_, nrow(outcome)),
                            origin = "1970-01-01")
  is_appr <- status == "approved"
  # Issuance adds a short clerical delay after the pipeline finishes.
  approved_at[is_appr] <- outcome$pipeline_done[is_appr] +
    runif(sum(is_appr), 0.2, 1.5) * 86400

  applications <- applications |>
    mutate(
      business_id = business_id,
      status      = status,
      approved_at = approved_at
    ) |>
    dplyr::select(id, business_id, application_type, complexity,
           submitted_at, approved_at, status, deadline_at)

  # ---- 7. Permits: one issued per approved application --------------------
  approved_apps <- applications |>
    filter(status == "approved", !is.na(approved_at))
  # Each approved application issues the three permit types.
  permit_types <- c("BUSINESS", "SANITARY", "FSIC")
  permits <- tidyr::expand_grid(
    application_id  = approved_apps$id,
    permit_type_code = permit_types
  ) |>
    left_join(approved_apps |> dplyr::select(id, business_id, approved_at, application_type),
              by = c("application_id" = "id")) |>
    mutate(
      valid_from  = as.Date(approved_at),
      # Calendar-year validity: expires end of the issuing year.
      valid_until = as.Date(sprintf("%d-12-31", year(valid_from))),
      # Renewal punctuality ~78% on-time; NA for non-renewals.
      renewed_on_time = ifelse(
        application_type == "renewal",
        runif(n()) < 0.78,
        NA
      )
    ) |>
    arrange(valid_from) |>
    mutate(id = row_number()) |>
    dplyr::select(id, business_id, permit_type_code, valid_from, valid_until,
           renewed_on_time)

  # ---- 8. Write CSVs -------------------------------------------------------
  out <- list(
    businesses   = businesses,
    applications = applications,
    assignments  = assignments,
    inspections  = inspections,
    permits      = permits
  )
  readr::write_csv(businesses,   file.path(DATA_DIR, "businesses.csv"))
  readr::write_csv(applications, file.path(DATA_DIR, "applications.csv"))
  readr::write_csv(assignments,  file.path(DATA_DIR, "assignments.csv"))
  readr::write_csv(inspections,  file.path(DATA_DIR, "inspections.csv"))
  readr::write_csv(permits,      file.path(DATA_DIR, "permits.csv"))

  message(glue::glue(
    "generate_history: {nrow(businesses)} businesses, ",
    "{nrow(applications)} applications, {nrow(assignments)} assignments, ",
    "{nrow(inspections)} inspections, {nrow(permits)} permits."
  ))
  message(glue::glue(
    "  Injected CHO slowdown (x1.8) applies to reviews assigned on/after ",
    "{format(anomaly_start)} (last {ANOMALY_WEEKS} weeks)."
  ))
  invisible(out)
}
