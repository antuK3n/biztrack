# R/service.R — the compute half of the Laravel integration.
#
# Laravel POSTs register rows to plumber; these functions turn them into the
# statistics the analytics screens display. Two rules hold everywhere in this
# file, and the whole integration rests on them:
#
#   1. PURE. Same input JSON => same output. No database, no file reads, no
#      Sys.Date(), no RNG without a fixed seed. R never touches the database:
#      Laravel owns all SQL, which keeps RBAC and office scoping in one place.
#      Purity is also what makes these testable and what lets the PHP fallback be
#      checked against them on a shared fixture.
#   2. THE CALLER OWNS THE RULES. Minimum completions per week, the calibration
#      cap, the risk weights and bands all arrive in the payload. Neither engine
#      hardcodes a number the other also hardcodes, because that is how two
#      implementations drift while both look correct.
#
# The output schema is Laravel's, not R's: these return exactly what the screen
# consumes, field for field, because the PHP port is the fallback and a screen
# must not be able to tell the two apart by shape — only by the provenance meta
# Laravel attaches. See api/app/Support/AnalyticsResolver.php.
#
# The statistics themselves are the prototype's: spc.R's qcc-based control charts
# are called directly rather than reimplemented here.

suppressWarnings(suppressMessages({
  library(dplyr)
  library(tibble)
  library(qcc)
  library(survival)
}))

# --- helpers -----------------------------------------------------------------

# jsonlite turns a JSON array of objects into a data.frame, but an EMPTY array
# into an empty list, and a single object into a bare named list. Every payload
# reader goes through this so those three shapes stop being three code paths.
.rows <- function(x) {
  if (is.null(x) || length(x) == 0) return(tibble())
  if (is.data.frame(x)) return(as_tibble(x))
  if (is.list(x) && !is.null(names(x))) return(as_tibble(as.list(x)))
  bind_rows(lapply(x, function(r) as_tibble(lapply(r, function(v) if (is.null(v)) NA else v))))
}

# jsonlite maps a JSON null to NULL, which drops out of a list rather than
# becoming NA. Pull scalars through this so a missing field is a stated default
# instead of a zero-length vector that poisons arithmetic downstream.
.scalar <- function(x, default) {
  if (is.null(x) || length(x) == 0 || (length(x) == 1 && is.na(x))) default else x[[1]]
}

# unbox so a length-1 vector serialises as 3 rather than [3]. The PHP fallback
# emits scalars and the two payloads have to match.
.n   <- function(x) jsonlite::unbox(as.numeric(x))
.i   <- function(x) jsonlite::unbox(as.integer(x))
.s   <- function(x) jsonlite::unbox(as.character(x))
.b   <- function(x) jsonlite::unbox(as.logical(x))
.r3  <- function(x) .n(round(as.numeric(x), 3))
.r2  <- function(x) .n(round(as.numeric(x), 2))

# NULL for a JSON null; PHP emits null for "no rule fired" and R must too.
.s_or_null <- function(x) if (is.null(x) || is.na(x)) NULL else .s(x)

# --- 1. Permit processing time (SPC) -----------------------------------------

#' Per-department control charts over weekly review turnaround.
#'
#' Wraps the prototype's own spc.R functions — weekly_turnaround() for ISO-week
#' bucketing and compute_control_limits() for the qcc individuals chart — then
#' adds the per-week EWMA series the screen draws.
#'
#' Limits are fitted on the LEADING calibration window only, so a recent
#' slowdown cannot help set the limits meant to catch it. That is the one
#' statistical decision in here worth restating, because it is the difference
#' between a chart that flags the CHO slowdown and one that absorbs it.
#'
#' @param payload Parsed request body: params$weeks, now, window_start,
#'   min_completions_per_week, calibration_weeks_cap, departments, reviews.
#' @return The Permit Processing Time Monitoring payload.
service_processing_time <- function(payload) {
  min_n      <- as.integer(.scalar(payload$min_completions_per_week, 3L))
  calib_cap  <- as.integer(.scalar(payload$calibration_weeks_cap, 24L))
  depts      <- .rows(payload$departments)
  reviews    <- .rows(payload$reviews)

  frame <- list(
    generated_at             = .s(.scalar(payload$now, NA_character_)),
    window_weeks             = .i(.scalar(payload$params$weeks, NA_integer_)),
    window_start             = .s(.scalar(payload$window_start, NA_character_)),
    min_completions_per_week = .i(min_n),
    calibration_weeks_cap    = .i(calib_cap),
    completed_reviews        = .i(nrow(reviews))
  )

  if (nrow(reviews) == 0 || nrow(depts) == 0) {
    return(c(frame, list(departments = list(), thin = list())))
  }

  assignments <- tibble(
    department_code = as.character(reviews$department_code),
    assigned_at     = as.POSIXct(reviews$assigned_at, format = "%Y-%m-%dT%H:%M:%OS", tz = "UTC"),
    completed_at    = as.POSIXct(reviews$completed_at, format = "%Y-%m-%dT%H:%M:%OS", tz = "UTC")
  )

  weekly <- weekly_turnaround(assignments, min_n = min_n)

  # Completions per department regardless of whether any week survived the
  # minimum, so a department that fell short can say by how much instead of
  # silently vanishing from the chart.
  completions <- assignments |>
    count(department_code, name = "completed") |>
    tibble::deframe()

  charted <- list()
  thin    <- list()

  # Department order follows the payload, which is Laravel's `departments.id`
  # order — so the chart tabs do not reshuffle between refreshes.
  for (i in seq_len(nrow(depts))) {
    dc   <- as.character(depts$code[[i]])
    name <- as.character(depts$name[[i]])
    done <- as.integer(if (!is.na(completions[dc])) completions[dc] else 0L)
    w    <- weekly |> filter(department_code == dc) |> arrange(week_start)

    if (nrow(w) == 0) {
      if (done > 0) {
        thin[[length(thin) + 1]] <- list(
          code             = .s(dc),
          name             = .s(name),
          completed_reviews = .i(done),
          reason           = .s(sprintf(
            "No week in this window reached %d completed reviews.", min_n))
        )
      }
      next
    }

    shaped <- .spc_department(w, dc, name, done, weekly, calib_cap)

    # No variation in the calibration window means no estimate of variation, so
    # there is no control chart to draw. Say that, rather than draw a chart whose
    # limits sit exactly on the centre line — with a zero-width band every week
    # that is not identical to the others reads as out of control, which is an
    # artifact of the arithmetic and not a finding about the office.
    if (is.null(shaped)) {
      thin[[length(thin) + 1]] <- list(
        code             = .s(dc),
        name             = .s(name),
        completed_reviews = .i(done),
        reason           = .s(paste("Weekly turnaround did not vary across the",
                                    "calibration window, so no control limits can be fitted."))
      )
      next
    }

    charted[[length(charted) + 1]] <- shaped
  }

  c(frame, list(departments = charted, thin = thin))
}

#' One department's control chart, flags and drift reading.
#'
#' Returns NULL when the calibration window has no variation — see the caller.
#' @keywords internal
.spc_department <- function(w, dc, name, completed, weekly, calib_cap) {
  lim  <- compute_control_limits(weekly, dc, calib_cap = calib_cap)
  vals <- w$mean_days

  # qcc's sigma is the mean moving range over d2, so zero means every
  # calibration week had the identical mean and there is nothing to chart.
  if (!is.finite(lim$sigma) || lim$sigma <= 0) return(NULL)

  beyond <- vals > lim$UCL | vals < lim$LCL

  # EWMA on the same clean calibration window. lambda 0.2 / 3 sigma (qcc's
  # defaults) catches a run of small increases that no single week flags.
  calib    <- vals[seq_len(lim$calib_weeks)]
  sd_calib <- if (length(calib) > 1) stats::sd(calib) else 0
  z <- ucl <- lcl <- rep(NA_real_, length(vals))
  drift <- rep(FALSE, length(vals))

  e <- tryCatch(
    qcc::ewma(vals, center = lim$center, std.dev = sd_calib,
              lambda = 0.2, nsigmas = 3, plot = FALSE),
    error = function(err) NULL
  )
  if (!is.null(e)) {
    z   <- as.numeric(e$y)
    lcl <- as.numeric(e$limits[, "LCL"])
    ucl <- as.numeric(e$limits[, "UCL"])
    if (length(e$violations)) drift[as.integer(e$violations)] <- TRUE
  } else {
    z   <- .ewma_series(vals, lim$center, 0.2)
    lcl <- rep(lim$center, length(vals))
    ucl <- rep(lim$center, length(vals))
  }

  rule_hit <- vapply(seq_along(vals), function(i) {
    hits <- c(if (beyond[i]) "beyond_limits", if (drift[i]) "ewma_drift")
    if (length(hits)) paste(hits, collapse = "+") else NA_character_
  }, character(1))

  status <- ifelse(beyond | drift, "out_of_control", "in_control")

  points <- lapply(seq_along(vals), function(i) list(
    week_start     = .s(as.character(w$week_start[[i]])),
    reviews        = .i(w$n[[i]]),
    mean_days      = .r3(vals[[i]]),
    deviation_days = .r3(vals[[i]] - lim$center),
    ewma           = .r3(z[[i]]),
    status         = .s(status[[i]]),
    rule_hit       = .s_or_null(rule_hit[[i]])
  ))

  flagged_idx <- which(status == "out_of_control")
  flagged <- lapply(flagged_idx, function(i) list(
    week_start     = .s(as.character(w$week_start[[i]])),
    mean_days      = .r3(vals[[i]]),
    deviation_days = .r2(vals[[i]] - lim$center),
    rule_hit       = .s_or_null(rule_hit[[i]])
  ))

  last <- length(vals)

  list(
    code              = .s(dc),
    name              = .s(name),
    completed_reviews = .i(completed),
    center            = .r3(lim$center),
    lcl               = .r3(lim$LCL),
    ucl               = .r3(lim$UCL),
    sigma             = .n(round(lim$sigma, 4)),
    calibration_weeks = .i(lim$calib_weeks),
    # "Outside" / "Inside" on the Process Status Indicator: the reading is about
    # the most recent week, not the history.
    status            = .s(if (status[[last]] == "out_of_control") "outside" else "inside"),
    latest_week       = .s(as.character(w$week_start[[last]])),
    latest_mean_days  = .r3(vals[[last]]),
    points            = points,
    flagged           = flagged,
    trend             = .spc_trend(lim$center, z, ucl, rule_hit, last)
  )
}

#' Gradual-slowdown reading for the weighted-trend bar.
#'
#' Magnitude is how far the smoothed series has walked from centre as a fraction
#' of its own EWMA band, so a full bar means the drift has reached the flagging
#' threshold rather than some arbitrary ceiling.
#' @keywords internal
.spc_trend <- function(center, z, ucl, rule_hit, last) {
  if (last < 1) {
    return(list(direction = .s("steady"), magnitude = .n(0), ewma = .n(0),
                deviation_days = .n(0), drift_flagged = .b(FALSE)))
  }

  smoothed <- z[[last]]
  band     <- ucl[[last]] - center
  ratio    <- if (is.finite(band) && band > 0) (smoothed - center) / band else 0

  direction <- if (ratio >= 0.5) "rising" else if (ratio <= -0.5) "easing" else "steady"

  list(
    direction      = .s(direction),
    magnitude      = .n(round(min(1, abs(ratio)), 4)),
    ewma           = .r3(smoothed),
    deviation_days = .r2(smoothed - center),
    drift_flagged  = .b(!is.na(rule_hit[[last]]) && grepl("ewma_drift", rule_hit[[last]]))
  )
}

#' z_i = lambda * x_i + (1 - lambda) * z_{i-1}, z_0 = center.
#' @keywords internal
.ewma_series <- function(values, center, lambda) {
  z <- numeric(length(values))
  prev <- center
  for (i in seq_along(values)) {
    prev <- lambda * values[[i]] + (1 - lambda) * prev
    z[[i]] <- prev
  }
  z
}

# --- 2. Renewal risk ---------------------------------------------------------

#' Rank permits coming up for renewal by a weighted rule score.
#'
#' NOT A PREDICTION. Nothing here is fitted, trained or cross-validated; there is
#' no outcome variable and no likelihood. It is a checklist of five things the
#' register knows about a permit, each worth a fixed number of points, summed to
#' a 0-100 risk score whose only claim is ordinal: a permit scoring 70 carries
#' more known risk signals than one scoring 30.
#'
#' The client's paper asks for an "Estimated Probability of Delayed Renewal" and
#' the mockup prints percentages. There is no such model in this project's R
#' source to port, and calling this a probability would assert a predictive
#' validity it does not have — an officer could reasonably act on "88%" as if it
#' were calibrated. So the number keeps its bands and loses that label. See
#' docs/r-integration-spec.md section 2; flagged for the client to overrule
#' knowingly.
#'
#' Every weight, band and threshold arrives in payload$parameters, so this
#' function has no risk numbers of its own to drift from PHP's.
#'
#' @param payload Parsed request body: params, permits, parameters, rulebook.
#' @return The Renewal Risk payload.
service_renewal_risk <- function(payload) {
  p       <- payload$parameters
  permits <- .rows(payload$permits)
  limit   <- max(1L, as.integer(.scalar(payload$params$limit, 25L)))
  per_row <- as.integer(.scalar(payload$drivers_per_row, 3L))

  high_at <- as.numeric(.scalar(p$thresholds$high, 50))
  mod_at  <- as.numeric(.scalar(p$thresholds$moderate, 25))

  counts <- c(high = 0L, moderate = 0L, low = 0L)
  rows   <- list()

  for (i in seq_len(nrow(permits))) {
    row     <- permits[i, ]
    drivers <- .risk_drivers(row, p)
    score   <- sum(vapply(drivers, function(d) d$points, numeric(1)))

    band <- if (score >= high_at) "high" else if (score >= mod_at) "moderate" else "low"
    counts[[band]] <- counts[[band]] + 1L

    # The action is a direct function of the band, not a second judgement: one
    # number, one recommended next step, no hidden second rule set.
    act <- switch(band,
      high     = c("immediate_follow_up", "Immediate follow-up"),
      moderate = c("send_reminder", "Send reminder"),
      c("monitor", "Monitor")
    )

    # Only drivers that actually cost points; a row listing "Fees settled: 0" is
    # noise dressed as transparency. Heaviest first, weight breaking ties so the
    # order is stable.
    scoring <- Filter(function(d) d$points > 0, drivers)
    scoring <- scoring[order(
      -vapply(scoring, function(d) d$points, numeric(1)),
      -vapply(scoring, function(d) d$max, numeric(1))
    )]
    scoring <- utils::head(scoring, per_row)

    rows[[length(rows) + 1]] <- list(
      permit_id           = .i(row$permit_id),
      permit_number       = .s(row$permit_number),
      business_id         = .i(row$business_id),
      business            = .s(row$business),
      barangay            = if (is.na(row$barangay)) NULL else .s(row$barangay),
      permit_type         = .s(row$permit_type),
      valid_until         = .s(row$valid_until),
      days_to_expiry      = .i(row$days_to_expiry),
      score               = .i(score),
      band                = .s(band),
      band_label          = .s(switch(band, high = "High", moderate = "Moderate", "Low")),
      action              = .s(act[[1]]),
      action_label        = .s(act[[2]]),
      renewal_stage       = .s(row$renewal_stage),
      renewal_tracking_id = if (is.null(row$renewal_tracking_id) ||
                                is.na(row$renewal_tracking_id)) NULL
                            else .s(row$renewal_tracking_id),
      reminders_sent      = .i(row$reminders_sent),
      drivers             = lapply(scoring, .driver_out),
      .sort_score         = score,
      .sort_days          = as.numeric(row$days_to_expiry)
    )
  }

  # Highest score first, then soonest expiry: two permits on the same score are
  # not equally urgent.
  if (length(rows)) {
    rows <- rows[order(
      -vapply(rows, function(r) r$.sort_score, numeric(1)),
       vapply(rows, function(r) r$.sort_days, numeric(1))
    )]
  }
  rows <- lapply(rows, function(r) r[!(names(r) %in% c(".sort_score", ".sort_days"))])

  list(
    generated_at      = .s(.scalar(payload$now, NA_character_)),
    horizon_days      = .i(.scalar(payload$params$days, NA_integer_)),
    lapsed_grace_days = .i(.scalar(payload$lapsed_grace_days, 60L)),
    window_start      = .s(.scalar(payload$window_start, NA_character_)),
    window_end        = .s(.scalar(payload$window_end, NA_character_)),
    scored_permits    = .i(length(rows)),
    counts            = list(high = .i(counts[["high"]]),
                             moderate = .i(counts[["moderate"]]),
                             low = .i(counts[["low"]])),
    reminders_sent    = .i(.scalar(payload$reminders_sent, 0L)),
    at_risk           = utils::head(rows, limit),
    actions           = list(
      list(action = .s("immediate_follow_up"), label = .s("Immediate follow-up"),
           band = .s("high"), count = .i(counts[["high"]])),
      list(action = .s("send_reminder"), label = .s("Send reminder"),
           band = .s("moderate"), count = .i(counts[["moderate"]])),
      list(action = .s("monitor"), label = .s("Monitor"),
           band = .s("low"), count = .i(counts[["low"]]))
    ),
    rulebook   = .rulebook_out(payload$rulebook),
    thresholds = list(high = .i(high_at), moderate = .i(mod_at)),
    methodology = .s(.scalar(payload$methodology, ""))
  )
}

#' The five rules, scored for one permit. Weights come from the payload.
#' @keywords internal
.risk_drivers <- function(row, p) {
  w <- p$weights
  list(
    .risk_expiry(as.integer(row$days_to_expiry), p, w),
    .risk_progress(as.character(row$renewal_stage), as.integer(row$days_to_expiry), p, w),
    .risk_punctuality(as.integer(row$prior_renewals), as.integer(row$late_renewals), p, w),
    .risk_findings(as.integer(row$open_findings), p, w),
    .risk_fees(as.character(row$fee_state), p, w)
  )
}

# [days_remaining_at_or_below, points], ascending. A lapsed permit takes the
# weight outright: it is operating without a permit.
.risk_expiry <- function(days, p, w) {
  max_pts <- as.numeric(w$expiry)
  if (days < 0) {
    return(.driver("expiry", "Time to expiry", max_pts, max_pts,
                   sprintf("Lapsed %d %s ago", abs(days), .plural(abs(days), "day"))))
  }
  bands <- .band_matrix(p$expiry_bands)
  for (i in seq_len(nrow(bands))) {
    if (days <= bands[i, 1]) {
      return(.driver("expiry", "Time to expiry", bands[i, 2], max_pts,
                     sprintf("Expires in %d %s", days, .plural(days, "day"))))
    }
  }
  .driver("expiry", "Time to expiry", 0, max_pts,
          sprintf("Expires in %d days — more than %d out", days, max(bands[, 1])))
}

.risk_progress <- function(stage, days, p, w) {
  max_pts <- as.numeric(w$progress)
  pts_by_stage <- p$progress_points
  known <- if (!is.null(pts_by_stage[[stage]])) stage else "none"
  due   <- days <= as.numeric(.scalar(p$renewal_due_within_days, 30))

  # Nothing filed and nothing due yet is not a risk signal. Without this gate
  # every permit in the register scores at least Moderate and the Low band empties.
  if (known == "none" && !due) {
    return(.driver("progress", "Renewal progress", 0, max_pts, "Not yet due for renewal"))
  }

  detail <- switch(known,
    approved    = "Renewal approved",
    in_progress = "Renewal filed and in the queue",
    draft       = "Renewal started but never submitted",
    returned    = "Renewal returned to the applicant",
    rejected    = "Renewal rejected — must be refiled",
    "No renewal filed yet"
  )
  .driver("progress", "Renewal progress", as.numeric(pts_by_stage[[known]]), max_pts, detail)
}

.risk_punctuality <- function(prior, late, p, w) {
  max_pts <- as.numeric(w$punctuality)

  # A first-timer has no record either way. Half weight, flagged as unknown
  # rather than clean: scoring them zero would bury a genuinely less predictable
  # case at the bottom of the list.
  if (is.na(prior) || prior < 1) {
    return(.driver("punctuality", "Past punctuality",
                   as.numeric(.scalar(p$punctuality_unknown_points, 10)), max_pts,
                   "First renewal cycle — no punctuality record either way"))
  }

  late <- max(0, min(prior, if (is.na(late)) 0 else late))
  pts  <- round((late / prior) * max_pts)
  detail <- if (late == 0) {
    sprintf("All %d earlier %s filed before expiry", prior, .plural(prior, "renewal"))
  } else {
    sprintf("%d of %d earlier %s filed late", late, prior, .plural(prior, "renewal"))
  }
  .driver("punctuality", "Past punctuality", pts, max_pts, detail)
}

.risk_findings <- function(open, p, w) {
  max_pts <- as.numeric(w$findings)
  open <- if (is.na(open)) 0L else open
  bands <- .band_matrix(p$findings_bands)
  pts <- max_pts
  for (i in seq_len(nrow(bands))) {
    if (open <= bands[i, 1]) { pts <- bands[i, 2]; break }
  }
  .driver("findings", "Open compliance findings", pts, max_pts,
          if (open == 0) "Nothing outstanding"
          else sprintf("%d open %s", open, .plural(open, "finding")))
}

.risk_fees <- function(state, p, w) {
  pts <- p$fee_points[[state]]
  .driver("fees", "Unsettled fees", if (is.null(pts)) 0 else as.numeric(pts),
          as.numeric(w$fees),
          switch(state,
            unpaid  = "Assessed fee with no payment recorded",
            pending = "Payment recorded but not yet cleared",
            "Fees settled"))
}

.driver <- function(rule, label, points, max, detail) {
  list(rule = rule, label = label, points = as.numeric(points),
       max = as.numeric(max), detail = detail)
}

.driver_out <- function(d) list(
  rule = .s(d$rule), label = .s(d$label), points = .i(d$points),
  max = .i(d$max), detail = .s(d$detail)
)

# jsonlite gives a JSON array of [n, n] pairs as a matrix when rectangular and a
# list of vectors otherwise. Normalise to a 2-column matrix either way.
.band_matrix <- function(bands) {
  if (is.matrix(bands)) return(bands)
  do.call(rbind, lapply(bands, function(b) as.numeric(unlist(b))))
}

.rulebook_out <- function(rulebook) {
  rb <- .rows(rulebook)
  if (nrow(rb) == 0) return(list())
  lapply(seq_len(nrow(rb)), function(i) list(
    rule = .s(rb$rule[[i]]), label = .s(rb$label[[i]]),
    max = .i(rb$max[[i]]), description = .s(rb$description[[i]])
  ))
}

.plural <- function(count, word) if (count == 1) word else paste0(word, "s")

# --- 3. Analytics dashboard --------------------------------------------------

#' The Analytics Dashboard panels (spec section 1).
#'
#' Laravel sends counts, per-observation rows, and the rules (RA 11032's
#' statutory limits, the expiry horizons, how many rows a ranking shows). This
#' turns them into the screen's statistics. Nothing here queries anything and
#' nothing here holds a threshold of its own — every number that both engines need
#' arrives in the payload, which is what stops the two drifting while both look
#' right.
#'
#' The two computations worth calling out, because they are the ones a careless
#' rewrite would get subtly wrong:
#'
#'  - **A rate with an empty denominator is NULL, not 0.** So is a rate whose
#'    numerator the register cannot establish; that case carries a reason so the
#'    screen can distinguish "nothing to divide" from "cannot be counted", which
#'    are different facts and would otherwise both print as 0%.
#'  - **Expiry windows are cumulative.** A permit 20 days from expiry counts in
#'    the 30, 60 and 90 day rows. Expired is disjoint from all three.
#'
#' @param payload Parsed request body, as built by DashboardAnalytics::dataset().
#' @return The Analytics Dashboard payload.
service_dashboard <- function(payload) {
  top_n <- as.integer(.scalar(payload$top_n, 5L))

  compliance <- .dash_compliance(payload$compliance)
  validity   <- NULL
  for (ind in compliance) {
    if (ind$indicator == "permit_validity") validity <- ind$rate
  }

  list(
    generated_at  = .s(.scalar(payload$now, NA_character_)),
    window_months = .i(.scalar(payload$params$months, NA_integer_)),
    window_start  = .s(.scalar(payload$window_start, NA_character_)),
    ytd_start     = .s(.scalar(payload$ytd_start, NA_character_)),
    month_start   = .s(.scalar(payload$month_start, NA_character_)),
    today         = .s(.scalar(payload$today, NA_character_)),

    kpis = list(
      active_businesses       = .i(.scalar(payload$kpis$active_businesses, 0L)),
      applications_ytd        = .i(.scalar(payload$kpis$applications_ytd, 0L)),
      applications_this_month = .i(.scalar(payload$kpis$applications_this_month, 0L)),
      # The Compliance Rate card IS the permit-validity indicator, read back off
      # the computed panel rather than divided a second time here. One division,
      # so the card and the panel cannot disagree.
      compliance_rate         = validity
    ),

    volume                = .dash_volume(payload$volume),
    decisions             = .dash_decisions(payload$decisions),
    processing_tiers      = .dash_tiers(payload$tiers, payload$tier_observations),
    stages                = .dash_stages(payload$stage_observations),
    compliance            = compliance,
    expiry                = .dash_expiry(payload$permit_type_columns,
                                         payload$expiring_permits,
                                         payload$expiry_windows),
    top_barangays         = .dash_shares(payload$barangays, "barangay", top_n),
    top_lines_of_business = .dash_shares(payload$lines_of_business, "industry", top_n),
    organization_forms    = .dash_org_forms(payload$organization_forms),
    inspections           = .dash_inspections(payload$inspections),
    officer_activity      = .dash_officer(payload$officer_activity),
    map                   = .dash_map(payload$map)
  )
}

#' Rate as a percentage rounded to one place, or NULL when there is none.
#' @keywords internal
.rate <- function(numerator, denominator) {
  if (is.na(denominator) || denominator <= 0) return(NULL)
  .n(round((as.numeric(numerator) / as.numeric(denominator)) * 100, 1))
}

#' @keywords internal
.dash_volume <- function(volume) {
  rows <- .rows(volume)
  if (nrow(rows) == 0) return(list(rows = list(), total = .i(0)))

  counts <- as.integer(rows$count)
  list(
    rows = lapply(seq_len(nrow(rows)), function(i) list(
      type  = .s(rows$type[[i]]),
      label = .s(rows$label[[i]]),
      count = .i(counts[[i]])
    )),
    total = .i(sum(counts))
  )
}

#' Decision outcomes and the approval rate.
#'
#' The denominator is the sum of the buckets Laravel flagged `decisioned`, so
#' Pending cannot leak into it. The flag travels with the fact precisely so this
#' function does not need to know which statuses count.
#' @keywords internal
.dash_decisions <- function(decisions) {
  rows <- .rows(decisions)
  if (nrow(rows) == 0) {
    return(list(rows = list(), total = .i(0), decisioned = .i(0),
                approved = .i(0), approval_rate = NULL))
  }

  counts     <- as.integer(rows$count)
  decisioned <- as.logical(rows$decisioned)
  approved   <- sum(counts[rows$outcome == "approved"])
  n_dec      <- sum(counts[decisioned])

  list(
    rows = lapply(seq_len(nrow(rows)), function(i) list(
      outcome    = .s(rows$outcome[[i]]),
      label      = .s(rows$label[[i]]),
      count      = .i(counts[[i]]),
      decisioned = .b(decisioned[[i]])
    )),
    total         = .i(sum(counts)),
    decisioned    = .i(n_dec),
    approved      = .i(approved),
    approval_rate = .rate(approved, n_dec)
  )
}

#' Mean processing time per RA 11032 tier against the statutory limit.
#'
#' A tier with no decided filing gets nulls and `observations = 0`. That is not a
#' compliant tier and must not be rendered as one — the register simply has
#' nothing to average, and saying so is the only honest option.
#'
#' `breaching` has no tolerance band. A mean of 3.1 working days against a 3-day
#' legal limit is a breach; softening it here would soften it on screen.
#' @keywords internal
.dash_tiers <- function(tiers, observations) {
  rules <- .rows(tiers)
  obs   <- .rows(observations)
  if (nrow(rules) == 0) return(list())

  lapply(seq_len(nrow(rules)), function(i) {
    key    <- as.character(rules$tier[[i]])
    target <- as.integer(rules$statutory_working_days[[i]])

    mine <- if (nrow(obs) == 0) obs[0, , drop = FALSE] else obs[obs$tier == key, , drop = FALSE]
    n    <- nrow(mine)

    if (n == 0) {
      return(list(
        tier = .s(key), label = .s(rules$label[[i]]),
        statutory_working_days = .i(target),
        observations = .i(0),
        mean_working_days = NULL, mean_calendar_days = NULL,
        within_statutory = .i(0), within_statutory_rate = NULL,
        within_recorded_deadline = .i(0),
        recorded_deadline_working_days = NULL,
        overage_days = NULL,
        breaching = .b(FALSE)
      ))
    }

    mean_working  <- round(mean(as.numeric(mine$working_days)), 1)
    mean_calendar <- round(mean(as.numeric(mine$calendar_days)), 1)
    within_stat   <- sum(as.logical(mine$within_statutory))

    # The recorded deadline is uniform across the register today, but that is data
    # and not a guarantee, so it is reported only when every filing in the tier
    # agrees. A mixed tier gets NULL rather than a figure that is half the story.
    recorded <- unique(mine$recorded_deadline_working_days)
    recorded <- recorded[!is.na(recorded)]

    list(
      tier = .s(key), label = .s(rules$label[[i]]),
      statutory_working_days = .i(target),
      observations       = .i(n),
      mean_working_days  = .n(mean_working),
      mean_calendar_days = .n(mean_calendar),
      # Against the STATUTE — the same yardstick as `breaching`.
      within_statutory      = .i(within_stat),
      within_statutory_rate = .n(round((within_stat / n) * 100, 1)),
      # Against applications.deadline_at, a different and more lenient yardstick.
      # Never present this one as statutory compliance.
      within_recorded_deadline = .i(sum(as.logical(mine$within_recorded_deadline))),
      recorded_deadline_working_days = if (length(recorded) == 1) .i(recorded[[1]]) else NULL,
      overage_days       = .n(round(mean_working - target, 1)),
      breaching          = .b(mean_working > target)
    )
  })
}

#' Mean time-in-stage per department, slowest first, plus the bottleneck.
#'
#' The bottleneck is emitted as computed values, never a sentence: a fixed
#' "Fire Protection is the bottleneck" would keep reading as true after Fire
#' Protection got faster. The screen assembles the wording from these numbers.
#' @keywords internal
.dash_stages <- function(observations) {
  obs <- .rows(observations)
  if (nrow(obs) == 0) {
    return(list(rows = list(), reviews = .i(0), mean_days = NULL, bottleneck = NULL))
  }

  days <- as.numeric(obs$days)

  summary <- tibble(code = as.character(obs$code),
                    name = as.character(obs$name),
                    days = days) |>
    group_by(code, name) |>
    summarise(reviews = n(), mean_days = round(mean(days), 1), .groups = "drop") |>
    # Slowest first, then review count, then code. The code tie-break is load
    # bearing: three offices carry one review each at a mean of 0.0 days, so both
    # earlier keys tie and the order would otherwise follow the payload, which is
    # not the order PHP's sort produces. The parity fixture caught that.
    arrange(desc(mean_days), desc(reviews), code)

  overall <- round(mean(days), 1)

  list(
    rows = lapply(seq_len(nrow(summary)), function(i) list(
      code      = .s(summary$code[[i]]),
      name      = .s(summary$name[[i]]),
      reviews   = .i(summary$reviews[[i]]),
      mean_days = .n(summary$mean_days[[i]])
    )),
    reviews    = .i(length(days)),
    mean_days  = .n(overall),
    bottleneck = list(
      code                = .s(summary$code[[1]]),
      name                = .s(summary$name[[1]]),
      mean_days           = .n(summary$mean_days[[1]]),
      reviews             = .i(summary$reviews[[1]]),
      above_average_days  = .n(round(summary$mean_days[[1]] - overall, 1)),
      share_of_reviews    = .n(round((summary$reviews[[1]] / max(1, length(days))) * 100, 1))
    )
  )
}

#' The three compliance indicators.
#'
#' Two distinct ways an indicator has no rate, kept distinct: an empty
#' denominator, and a numerator the register cannot establish. Both give NULL,
#' only the second carries a reason, and a screen that collapsed them would print
#' 0% for a missing link in the data — which reads as a compliance failure.
#' @keywords internal
.dash_compliance <- function(compliance) {
  rows <- .rows(compliance)
  if (nrow(rows) == 0) return(list())

  has_reason <- "unavailable_reason" %in% names(rows)

  lapply(seq_len(nrow(rows)), function(i) {
    numerator   <- as.integer(rows$numerator[[i]])
    denominator <- as.integer(rows$denominator[[i]])
    reason      <- if (has_reason) rows$unavailable_reason[[i]] else NA

    list(
      indicator         = .s(rows$indicator[[i]]),
      label             = .s(rows$label[[i]]),
      numerator         = .i(numerator),
      denominator       = .i(denominator),
      numerator_label   = .s(rows$numerator_label[[i]]),
      denominator_label = .s(rows$denominator_label[[i]]),
      rate              = if (is.null(reason) || all(is.na(reason))) .rate(numerator, denominator) else NULL,
      unavailable_reason = .s_or_null(reason)
    )
  })
}

#' Permits approaching expiry, in CUMULATIVE windows.
#'
#' 30d is a subset of 60d is a subset of 90d, which is what the mockup's own
#' figures do; Expired is disjoint from the three. Laravel sends one row per
#' permit with a signed days-to-expiry rather than pre-bucketed counts, which is
#' what makes the nesting a computation here instead of an assumption there.
#' @keywords internal
.dash_expiry <- function(columns, permits, windows) {
  cols <- .rows(columns)
  if (nrow(cols) == 0) return(list(columns = list(), rows = list()))

  codes   <- as.character(cols$code)
  horizons <- as.integer(unlist(windows))
  perms   <- .rows(permits)

  p_code <- if (nrow(perms) == 0) character(0) else as.character(perms$code)
  p_days <- if (nrow(perms) == 0) integer(0)   else as.integer(perms$days_to_expiry)

  # One row per window, then the disjoint Expired row.
  specs <- c(
    lapply(horizons, function(h) list(
      window = sprintf("next_%dd", h), label = sprintf("Next %dd", h),
      days = .i(h), expired = FALSE, keep = function(d) d >= 0 & d <= h
    )),
    list(list(window = "expired", label = "Expired", days = NULL,
              expired = TRUE, keep = function(d) d < 0))
  )

  rows <- lapply(specs, function(spec) {
    hit    <- if (length(p_days)) spec$keep(p_days) else logical(0)
    counts <- vapply(codes, function(code) sum(hit & p_code == code), integer(1))

    list(
      window  = .s(spec$window),
      label   = .s(spec$label),
      days    = spec$days,
      expired = .b(spec$expired),
      counts  = stats::setNames(lapply(counts, .i), codes),
      total   = .i(sum(counts))
    )
  })

  list(
    columns = lapply(seq_len(nrow(cols)), function(i) list(
      code = .s(cols$code[[i]]), label = .s(cols$label[[i]])
    )),
    rows = rows
  )
}

#' Rank a count list and give each row its share of the total.
#'
#' Count descending, then name ascending so equal counts hold a stable order
#' across refreshes instead of following whatever order the query returned.
#' @keywords internal
.dash_shares <- function(facts, name_key, top_n) {
  rows <- .rows(facts)
  if (nrow(rows) == 0) return(list(rows = list(), total = .i(0), groups = .i(0)))

  counts <- as.integer(rows$count)
  total  <- sum(counts)
  names_ <- as.character(rows[[name_key]])

  order_ <- order(-counts, names_)
  keep   <- utils::head(order_, top_n)
  extra  <- setdiff(names(rows), c("count", name_key))

  list(
    rows = lapply(seq_along(keep), function(r) {
      i <- keep[[r]]
      out <- list(
        rank  = .i(r),
        count = .i(counts[[i]]),
        share = if (total > 0) .n(round((counts[[i]] / total) * 100, 1)) else NULL
      )
      out[[name_key]] <- .s(names_[[i]])
      # Any other column Laravel sent (psic_code, for instance) rides along, so
      # the two engines emit the same keys without this listing them.
      for (col in extra) out[[col]] <- .s(rows[[col]][[i]])
      out
    }),
    total  = .i(total),
    groups = .i(nrow(rows))
  )
}

#' Businesses by form of organization.
#'
#' Shares are of businesses whose form IS recorded, so the rows sum to 100%
#' when any are populated rather than to a fraction set by how blank the column
#' is. When none is recorded every share is NULL and the screen says the field is
#' not captured — four zero bars would read as a finding about Malabon.
#' @keywords internal
.dash_org_forms <- function(facts) {
  rows  <- .rows(facts$forms)
  total <- as.integer(.scalar(facts$total, 0L))
  unrec <- as.integer(.scalar(facts$unrecorded, 0L))
  recorded <- total - unrec

  list(
    rows = if (nrow(rows) == 0) list() else lapply(seq_len(nrow(rows)), function(i) {
      count <- as.integer(rows$count[[i]])
      list(
        form  = .s(rows$form[[i]]),
        label = .s(rows$label[[i]]),
        count = .i(count),
        share = if (recorded > 0) .n(round((count / recorded) * 100, 1)) else NULL
      )
    }),
    recorded   = .i(recorded),
    unrecorded = .i(unrec),
    total      = .i(total)
  )
}

#' Inspections per type, with the pass rate over COMPLETED inspections.
#'
#' Passed over completed, never over scheduled: dividing by scheduled would report
#' a queue's progress as a quality figure.
#' @keywords internal
.dash_inspections <- function(facts) {
  rows <- .rows(facts)
  fields <- c("scheduled", "completed", "passed", "failed", "conditional")

  if (nrow(rows) == 0) {
    zero <- stats::setNames(lapply(fields, function(f) .i(0)), fields)
    return(list(rows = list(), combined = c(
      list(type = .s("combined"), label = .s("Combined")), zero, list(pass_rate = NULL)
    )))
  }

  totals <- stats::setNames(
    vapply(fields, function(f) sum(as.integer(rows[[f]])), integer(1)),
    fields
  )

  shape <- function(type, label, values) {
    c(
      list(type = .s(type), label = .s(label)),
      stats::setNames(lapply(fields, function(f) .i(values[[f]])), fields),
      list(pass_rate = .rate(values[["passed"]], values[["completed"]]))
    )
  }

  list(
    rows = lapply(seq_len(nrow(rows)), function(i) {
      values <- stats::setNames(
        vapply(fields, function(f) as.integer(rows[[f]][[i]]), integer(1)),
        fields
      )
      shape(rows$type[[i]], rows$label[[i]], values)
    }),
    combined = shape("combined", "Combined", totals)
  )
}

#' Officer response latency, request fulfilment and meeting participation.
#'
#' The median goes out alongside the mean because with a handful of replies one
#' forgotten thread drags the mean somewhere no officer would recognise.
#'
#' A zero meeting count yields a NULL rate, not 0%: nothing was scheduled, so
#' nobody failed to attend.
#' @keywords internal
.dash_officer <- function(facts) {
  hours <- as.numeric(unlist(facts$response_hours))
  hours <- hours[!is.na(hours)]

  req_total     <- as.integer(.scalar(facts$requests$total, 0L))
  req_fulfilled <- as.integer(.scalar(facts$requests$fulfilled, 0L))
  met_sched     <- as.integer(.scalar(facts$meetings$scheduled, 0L))
  met_attended  <- as.integer(.scalar(facts$meetings$attended, 0L))

  list(
    responses            = .i(length(hours)),
    mean_response_hours  = if (length(hours)) .n(round(mean(hours), 1)) else NULL,
    # type = 7 is R's default quantile; for the even case it is the midpoint of
    # the two central values, which is what the PHP port computes.
    median_response_hours = if (length(hours)) .n(round(stats::median(hours), 1)) else NULL,
    threads_awaiting_reply = .i(.scalar(facts$threads_awaiting_reply, 0L)),
    requests_total        = .i(req_total),
    requests_fulfilled    = .i(req_fulfilled),
    requests_fulfilled_rate = .rate(req_fulfilled, req_total),
    meetings_scheduled    = .i(met_sched),
    meetings_attended     = .i(met_attended),
    meetings_attended_rate = .rate(met_attended, met_sched)
  )
}

#' The map point layer, plus the per-barangay aggregation the choropleth reads.
#' @keywords internal
.dash_map <- function(facts) {
  pts <- .rows(facts$points)

  frame <- list(
    mapped           = .i(.scalar(facts$mapped, 0L)),
    plotted          = .i(nrow(pts)),
    total_businesses = .i(.scalar(facts$total_businesses, 0L))
  )

  if (nrow(pts) == 0) {
    return(c(frame, list(points = list(), by_barangay = list())))
  }

  barangay <- as.character(pts$barangay)
  state    <- as.character(pts$permit_state)
  plotted  <- nrow(pts)

  points <- lapply(seq_len(plotted), function(i) list(
    business_id  = .i(pts$business_id[[i]]),
    business     = .s(pts$business[[i]]),
    barangay     = .s_or_null(barangay[[i]]),
    latitude     = .n(pts$latitude[[i]]),
    longitude    = .n(pts$longitude[[i]]),
    permit_state = .s(state[[i]])
  ))

  named <- !is.na(barangay)
  summary <- if (!any(named)) NULL else tibble(
      barangay = barangay[named],
      active   = state[named] == "active"
    ) |>
    group_by(barangay) |>
    summarise(businesses = n(), active = sum(active), .groups = "drop") |>
    arrange(desc(businesses), barangay)

  c(frame, list(
    points = points,
    by_barangay = if (is.null(summary)) list() else lapply(seq_len(nrow(summary)), function(i) list(
      barangay   = .s(summary$barangay[[i]]),
      businesses = .i(summary$businesses[[i]]),
      active     = .i(summary$active[[i]]),
      share      = if (plotted > 0) .n(round((summary$businesses[[i]] / plotted) * 100, 1)) else NULL
    ))
  ))
}

# --- 4. Business lifecycle monitoring ----------------------------------------

#' Business Lifecycle Monitoring (spec section 4).
#'
#' Growth, lifecycle status, cohort survival over renewal cycles, the barangays
#' growing fastest, closures by month, and per-industry direction.
#'
#' The one real statistic here is the survival curve; everything else is a share,
#' a delta or a rank. See .growth_survival() for why survival is the right measure
#' and a single division is not.
#'
#' @param payload Parsed request body, as built by BusinessGrowthAnalytics::dataset().
#' @return The Business Lifecycle Monitoring payload.
service_growth_lifecycle <- function(payload) {
  top_n      <- as.integer(.scalar(payload$top_n, 6L))
  registered <- as.integer(.scalar(payload$registrations, 0L))
  prior      <- as.integer(.scalar(payload$registrations_prior, 0L))

  list(
    generated_at       = .s(.scalar(payload$now, NA_character_)),
    period_months      = .i(.scalar(payload$params$months, NA_integer_)),
    period_start       = .s(.scalar(payload$period_start, NA_character_)),
    period_end         = .s(.scalar(payload$period_end, NA_character_)),
    prior_period_start = .s(.scalar(payload$prior_period_start, NA_character_)),
    registrations      = .i(registered),
    registrations_prior = .i(prior),
    # NULL when the prior period was empty. A change from zero is not a rate, and
    # the screen renders this as "No prior period" rather than a fabricated 0%.
    growth_rate = if (prior > 0) .n(round(((registered - prior) / prior) * 100, 1)) else NULL,
    closures    = .i(.scalar(payload$closures, 0L)),

    cohort_survival = .growth_survival(payload$cohorts,
                                       .scalar(payload$survival_methodology, ""),
                                       .scalar(payload$grace_days, 30L)),
    status_summary  = .growth_status(payload$status_counts),
    top_barangays   = .growth_barangays(payload$barangays, top_n),
    closure_trend   = .growth_closures(payload$closure_months),
    industry_growth = .growth_industries(payload$industries, top_n)
  )
}

#' Kaplan-Meier survival over renewal cycles, overall and per cohort.
#'
#' WHY SURVIVAL AND NOT A RATIO. The paper's formula reads "businesses that
#' continued renewing on time over total businesses in the group". Taken literally
#' as one division that flatters the LGU: a business registered last month has had
#' no renewal to miss, so putting it in the denominator drags the figure toward
#' whatever share of the register is merely too new to have failed. Survival
#' analysis is the fix — a business still inside its current permit is CENSORED,
#' counting while it was observed and dropping out once there is nothing left to
#' see. That is why the paper names this package, and `survival::survfit` is what
#' computes it here rather than a hand-rolled product.
#'
#' The PHP fallback reproduces the same product-limit estimator, and the parity
#' fixture is what keeps the two from drifting.
#' @keywords internal
.growth_survival <- function(cohorts, methodology, grace_days) {
  obs <- .rows(cohorts)

  frame <- list(
    methodology = .s(methodology),
    grace_days  = .i(grace_days)
  )

  if (nrow(obs) == 0) {
    return(c(frame, list(
      businesses = .i(0), renewals_observed = .i(0), lapses = .i(0),
      max_cycle = .i(0), survival = NULL, points = list(), cohorts = list()
    )))
  }

  overall <- .km_curve(as.integer(obs$time), as.integer(obs$event))

  groups <- sort(unique(as.character(obs$cohort)))
  per_cohort <- lapply(groups, function(g) {
    mine  <- obs[as.character(obs$cohort) == g, , drop = FALSE]
    curve <- .km_curve(as.integer(mine$time), as.integer(mine$event))
    c(list(cohort = .s(g)), curve)
  })

  c(frame, overall, list(cohorts = per_cohort))
}

#' One Kaplan-Meier curve, evaluated at every integer renewal cycle.
#'
#' `survfit` does the estimation. It reports rows only at times where something
#' happened, so the curve is then read off at each integer cycle with
#' `summary(..., times = )`, which is the shape the screen draws and the shape the
#' PHP port emits. At-risk and lapse counts travel with each point so a reader can
#' see how thin a late cycle is rather than trusting a percentage computed over
#' three businesses.
#' @keywords internal
.km_curve <- function(time, event) {
  max_cycle <- if (length(time)) max(time) else 0L

  base <- list(
    businesses = .i(length(time)),
    # How many renewal cycles this group actually lived through: the sample size
    # behind the curve.
    renewals_observed = .i(sum(time)),
    lapses    = .i(sum(event)),
    max_cycle = .i(max_cycle)
  )

  if (max_cycle < 1) {
    return(c(base, list(survival = NULL, points = list())))
  }

  fit <- survival::survfit(survival::Surv(time, event) ~ 1)

  points <- list()
  surv   <- NULL
  for (t in seq_len(max_cycle)) {
    at_risk <- sum(time >= t)
    if (at_risk == 0) break

    s <- summary(fit, times = t, extend = TRUE)
    surv <- as.numeric(s$surv)[[1]]

    points[[length(points) + 1]] <- list(
      cycle    = .i(t),
      at_risk  = .i(at_risk),
      lapses   = .i(sum(time == t & event == 1)),
      survival = .n(round(surv * 100, 1))
    )
  }

  c(base, list(
    survival = if (is.null(surv)) NULL else .n(round(surv * 100, 1)),
    points   = points
  ))
}

#' @keywords internal
.growth_status <- function(counts) {
  labels <- c(active = "Active", expired = "Expired",
              inactive = "Inactive", closed = "Closed")

  values <- vapply(names(labels), function(k) as.integer(.scalar(counts[[k]], 0L)), integer(1))
  total  <- sum(values)

  lapply(seq_along(labels), function(i) list(
    status = .s(names(labels)[[i]]),
    label  = .s(labels[[i]]),
    count  = .i(values[[i]]),
    share  = if (total > 0) .n(round((values[[i]] / total) * 100, 1)) else NULL
  ))
}

#' Barangays ranked by the INCREASE between periods, as the spec asks — not by how
#' many they hold. A barangay with 300 registrations and no change is not growing.
#' @keywords internal
.growth_barangays <- function(barangays, top_n) {
  rows <- .rows(barangays)
  if (nrow(rows) == 0) return(list())

  current <- as.integer(rows$registrations)
  prior   <- as.integer(rows$prior)
  names_  <- as.character(rows$barangay)
  delta   <- current - prior

  # Delta descending, then volume, then name so ties hold a stable order.
  keep <- utils::head(order(-delta, -current, names_), top_n)

  lapply(keep, function(i) list(
    barangay      = .s(names_[[i]]),
    registrations = .i(current[[i]]),
    prior         = .i(prior[[i]]),
    delta         = .i(delta[[i]]),
    growth_rate   = if (prior[[i]] > 0)
                      .n(round(((current[[i]] - prior[[i]]) / prior[[i]]) * 100, 1))
                    else NULL
  ))
}

#' @keywords internal
.growth_closures <- function(months) {
  rows <- .rows(months)
  if (nrow(rows) == 0) return(list())

  lapply(seq_len(nrow(rows)), function(i) list(
    month    = .s(rows$month[[i]]),
    closures = .i(rows$closures[[i]])
  ))
}

#' @keywords internal
.growth_industries <- function(industries, top_n) {
  rows <- .rows(industries)
  if (nrow(rows) == 0) return(list())

  count   <- as.integer(rows$count)
  current <- as.integer(rows$registrations)
  prior   <- as.integer(rows$prior)
  codes   <- as.character(rows$psic_code)
  delta   <- current - prior

  keep <- utils::head(order(-count, -delta, codes), top_n)

  lapply(keep, function(i) list(
    industry      = .s(rows$industry[[i]]),
    psic_code     = .s(codes[[i]]),
    count         = .i(count[[i]]),
    registrations = .i(current[[i]]),
    prior         = .i(prior[[i]]),
    delta         = .i(delta[[i]]),
    direction     = .s(if (delta[[i]] > 0) "growing"
                       else if (delta[[i]] < 0) "declining" else "steady")
  ))
}
