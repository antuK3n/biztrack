# R/analytics.R — shared analytics functions, named exactly as the paper names
# them. Other sections of the manuscript cite these by name, so the signatures
# and return shapes are part of the contract. Each returns a tibble.

suppressWarnings(suppressMessages({
  library(dplyr)
  library(lubridate)
  library(tibble)
}))

#' RA 11032 compliance rate
#'
#' Share of applications approved on or before their RA 11032 working-day
#' deadline, overall and split by application_type. Only decided (approved)
#' applications with a deadline are counted; in-flight/rejected are excluded
#' from the denominator since they have no approval date to judge.
#'
#' @param applications Applications tibble (see applications.csv schema).
#' @return tibble: scope, application_type, n, on_time, compliance_rate.
compute_ra11032_compliance_rate <- function(applications) {
  decided <- applications |>
    filter(status == "approved", !is.na(approved_at), !is.na(deadline_at)) |>
    mutate(on_time = approved_at <= deadline_at)

  overall <- decided |>
    summarise(
      scope            = "overall",
      application_type = "all",
      n                = n(),
      compliance_rate  = mean(on_time),  # rate before we overwrite on_time
      on_time          = sum(on_time)
    ) |>
    dplyr::select(scope, application_type, n, on_time, compliance_rate)

  by_type <- decided |>
    group_by(application_type) |>
    summarise(
      n               = n(),
      compliance_rate = mean(on_time),   # rate before we overwrite on_time
      on_time         = sum(on_time),
      .groups         = "drop"
    ) |>
    mutate(scope = "by_type") |>
    dplyr::select(scope, application_type, n, on_time, compliance_rate)

  bind_rows(overall, by_type)
}

#' Average processing time
#'
#' Mean and median calendar days from submitted_at to approved_at, overall and
#' per application_type. Computed over approved applications only.
#'
#' @param applications Applications tibble.
#' @return tibble: scope, application_type, n, mean_days, median_days.
average_processing_time <- function(applications) {
  appr <- applications |>
    filter(status == "approved", !is.na(approved_at)) |>
    mutate(proc_days = as.numeric(approved_at - submitted_at, units = "days"))

  overall <- appr |>
    summarise(
      scope            = "overall",
      application_type = "all",
      n                = n(),
      mean_days        = mean(proc_days),
      median_days      = median(proc_days)
    )

  by_type <- appr |>
    group_by(application_type) |>
    summarise(
      n           = n(),
      mean_days   = mean(proc_days),
      median_days = median(proc_days),
      .groups     = "drop"
    ) |>
    mutate(scope = "by_type") |>
    dplyr::select(scope, application_type, n, mean_days, median_days)

  bind_rows(overall, by_type)
}

#' Department workload statistics
#'
#' Per department: number of review assignments, mean turnaround days
#' (assigned_at -> completed_at), and share of total workload.
#'
#' @param assignments Assignments tibble (see assignments.csv schema).
#' @return tibble: department_code, n_assignments, mean_turnaround_days,
#'   workload_share.
department_workload_stats <- function(assignments) {
  total <- nrow(assignments)
  assignments |>
    filter(!is.na(completed_at)) |>
    mutate(turnaround = as.numeric(completed_at - assigned_at, units = "days")) |>
    group_by(department_code) |>
    summarise(
      n_assignments        = n(),
      mean_turnaround_days = mean(turnaround),
      .groups              = "drop"
    ) |>
    mutate(workload_share = n_assignments / total) |>
    arrange(desc(n_assignments))
}

#' Business permit compliance
#'
#' Share of businesses that hold a currently valid permit for every permit type
#' they have ever been issued. A business missing one of its own permit types is
#' not compliant, which is why this cannot be a simple count of valid permits.
#'
#' @param businesses Businesses tibble (see businesses.csv schema).
#' @param permits Permits tibble (see permits.csv schema).
#' @param as_of Date the validity window is judged against. Defaults to
#'   ANCHOR_DATE so a run is reproducible; never Sys.Date().
#' @return tibble: n_businesses, compliant, compliance_rate.
compute_business_permit_compliance <- function(businesses, permits,
                                               as_of = ANCHOR_DATE) {
  ever <- permits |>
    group_by(business_id, permit_type_code) |>
    summarise(
      valid_now = any(valid_from <= as_of & valid_until >= as_of),
      .groups   = "drop"
    )

  per_business <- ever |>
    group_by(business_id) |>
    summarise(compliant = all(valid_now), .groups = "drop")

  # Denominator is businesses that have ever held a permit. A business that has
  # never been issued anything cannot be "non-compliant" for lacking a renewal.
  tibble(
    n_businesses    = nrow(per_business),
    compliant       = sum(per_business$compliant),
    compliance_rate = if (nrow(per_business) == 0) NA_real_ else mean(per_business$compliant)
  )
}

#' Renewal compliance
#'
#' Share of permits that fell due inside the period whose replacement was filed
#' before the old permit expired.
#'
#' The denominator is deliberately narrow. A renewal filing replaces one permit,
#' so counting every permit type that fell due would build a denominator the
#' numerator cannot reach: the ancillary clearances ride along on the business
#' permit's filing and are never separately renewed. Restricting to the permit
#' types that renewals actually replace keeps the ratio commensurable.
#'
#' @param permits Permits tibble.
#' @param period Length-2 Date vector, c(start, end), inclusive.
#' @param renewable_types Permit type codes that renewals replace.
#' @return tibble: period_start, period_end, due, on_time, compliance_rate.
compute_renewal_compliance <- function(permits, period,
                                       renewable_types = "BUSINESS") {
  due <- permits |>
    filter(
      permit_type_code %in% renewable_types,
      valid_until >= period[[1]],
      valid_until <= period[[2]]
    )

  # Pulled out before the tibble() call on purpose: a column named `due` would
  # shadow the data frame `due` for every expression after it, because tibble()
  # evaluates its arguments sequentially under data masking. The first version of
  # this failed with "$ operator is invalid for atomic vectors".
  n_due <- nrow(due)
  on_time <- sum(due$renewed_on_time, na.rm = TRUE)
  rate <- if (n_due == 0) NA_real_ else mean(due$renewed_on_time, na.rm = TRUE)

  tibble(
    period_start    = period[[1]],
    period_end      = period[[2]],
    due             = n_due,
    on_time         = on_time,
    compliance_rate = rate
  )
}

#' Predict renewal risk for one business
#'
#' NOT A FITTED MODEL, and the name is the paper's rather than a description of
#' the method. This is a transparent weighted rule set: five signals each
#' contribute up to a fixed maximum, summing to 100, and the total is banded.
#' There is no training step, no coefficients estimated from outcomes, and the
#' score is not a probability. Anything that presents it as one is wrong.
#'
#' The served implementation is service_renewal_risk() in R/service.R, which
#' scores a whole payload pushed by Laravel and carries the weights with it so
#' the two engines cannot disagree. This function is the single-business form the
#' manuscript cites, over the CSV schema.
#'
#' @param business_id Business to score.
#' @param permits Permits tibble.
#' @param applications Applications tibble.
#' @param as_of Date the score is computed for. Defaults to ANCHOR_DATE.
#' @return tibble: business_id, score, band, days_to_expiry, filed.
predict_renewal_risk <- function(business_id, permits, applications,
                                 as_of = ANCHOR_DATE) {
  own <- permits |>
    filter(business_id == !!business_id, permit_type_code == "BUSINESS") |>
    arrange(desc(valid_until))

  if (nrow(own) == 0) {
    return(tibble(
      business_id    = business_id,
      score          = NA_integer_,
      band           = NA_character_,
      days_to_expiry = NA_integer_,
      filed          = NA
    ))
  }

  current <- own[1, ]
  days <- as.integer(as.numeric(current$valid_until - as_of, units = "days"))

  # Time to expiry, max 30: stepped on the reminder marks, nothing beyond 90.
  expiry_points <- if (days <= 1) 30 else if (days <= 7) 24 else
    if (days <= 15) 18 else if (days <= 30) 12 else if (days <= 90) 6 else 0

  # Renewal progress, max 25: has a replacement actually been filed?
  filed <- any(
    applications$business_id == business_id &
      applications$application_type == "renewal" &
      !is.na(applications$submitted_at) &
      applications$submitted_at >= current$valid_from
  )
  progress_points <- if (filed) 0 else if (days <= 30) 25 else 0

  # Past punctuality, max 20: share of earlier renewals filed late. A first
  # cycle has no record either way, so it takes half weight rather than zero,
  # which would read as a clean history the business has not yet earned.
  prior <- own[-1, ]
  punctuality_points <- if (nrow(prior) == 0) 10 else
    round(20 * mean(!prior$renewed_on_time, na.rm = TRUE))

  score <- as.integer(expiry_points + progress_points + punctuality_points)

  tibble(
    business_id    = business_id,
    score          = score,
    band           = if (score >= 50) "high" else if (score >= 25) "moderate" else "low",
    days_to_expiry = days,
    filed          = filed
  )
}

#' Forecast which businesses should receive a renewal reminder
#'
#' The paper's §3 function: it decides who gets contacted, not how risky they
#' are. A business is due a reminder when its business permit crosses one of the
#' statutory monitoring marks (30, 15, 7 or 1 day out) and no replacement has
#' been filed. Reminders are not sent on risk score, because a business that has
#' already filed does not need chasing however poor its history.
#'
#' @param permits Permits tibble.
#' @param applications Applications tibble.
#' @param as_of Date the marks are judged against. Defaults to ANCHOR_DATE.
#' @param marks Days-before-expiry marks to fire on.
#' @return tibble: business_id, permit_id, valid_until, days_to_expiry, mark.
forecast_renewal_risk <- function(permits, applications, as_of = ANCHOR_DATE,
                                  marks = c(30L, 15L, 7L, 1L)) {
  candidates <- permits |>
    filter(permit_type_code == "BUSINESS", valid_until >= as_of) |>
    mutate(days_to_expiry = as.integer(as.numeric(valid_until - as_of, units = "days"))) |>
    filter(days_to_expiry <= max(marks))

  if (nrow(candidates) == 0) {
    return(tibble(
      business_id = integer(), permit_id = integer(),
      valid_until = as.Date(character()),
      days_to_expiry = integer(), mark = integer()
    ))
  }

  # Scoped to the CURRENT cycle, not "has ever renewed". Excluding every
  # business that has ever filed a renewal empties the list entirely on a
  # register with three years of history — which is exactly what the first
  # version of this did. A replacement only counts if it was filed after the
  # permit now expiring took effect.
  renewals <- applications |>
    filter(application_type == "renewal", !is.na(submitted_at))

  candidates <- candidates |>
    rowwise() |>
    mutate(filed = any(
      renewals$business_id == business_id & renewals$submitted_at >= valid_from
    )) |>
    ungroup()

  candidates |>
    filter(!filed) |>
    # The smallest mark still at or above the remaining days: a permit 22 days
    # out belongs to the 30-day mark and fires once there, not four times.
    mutate(mark = vapply(days_to_expiry, function(d) min(marks[marks >= d]), integer(1))) |>
    dplyr::select(business_id, permit_id = id, valid_until, days_to_expiry, mark) |>
    arrange(days_to_expiry)
}
