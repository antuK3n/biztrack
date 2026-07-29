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
