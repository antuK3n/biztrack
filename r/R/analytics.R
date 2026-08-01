
suppressWarnings(suppressMessages({
  library(dplyr)
  library(lubridate)
  library(tibble)
}))

compute_ra11032_compliance_rate <- function(applications) {
  decided <- applications |>
    filter(status == "approved", !is.na(approved_at), !is.na(deadline_at)) |>
    mutate(on_time = approved_at <= deadline_at)

  overall <- decided |>
    summarise(
      scope            = "overall",
      application_type = "all",
      n                = n(),
      compliance_rate  = mean(on_time),
      on_time          = sum(on_time)
    ) |>
    dplyr::select(scope, application_type, n, on_time, compliance_rate)

  by_type <- decided |>
    group_by(application_type) |>
    summarise(
      n               = n(),
      compliance_rate = mean(on_time),
      on_time         = sum(on_time),
      .groups         = "drop"
    ) |>
    mutate(scope = "by_type") |>
    dplyr::select(scope, application_type, n, on_time, compliance_rate)

  bind_rows(overall, by_type)
}

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

  tibble(
    n_businesses    = nrow(per_business),
    compliant       = sum(per_business$compliant),
    compliance_rate = if (nrow(per_business) == 0) NA_real_ else mean(per_business$compliant)
  )
}

compute_renewal_compliance <- function(permits, period,
                                       renewable_types = "BUSINESS") {
  due <- permits |>
    filter(
      permit_type_code %in% renewable_types,
      valid_until >= period[[1]],
      valid_until <= period[[2]]
    )

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

  expiry_points <- if (days <= 1) 30 else if (days <= 7) 24 else
    if (days <= 15) 18 else if (days <= 30) 12 else if (days <= 90) 6 else 0

  filed <- any(
    applications$business_id == business_id &
      applications$application_type == "renewal" &
      !is.na(applications$submitted_at) &
      applications$submitted_at >= current$valid_from
  )
  progress_points <- if (filed) 0 else if (days <= 30) 25 else 0

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
    mutate(mark = vapply(days_to_expiry, function(d) min(marks[marks >= d]), integer(1))) |>
    dplyr::select(business_id, permit_id = id, valid_until, days_to_expiry, mark) |>
    arrange(days_to_expiry)
}
