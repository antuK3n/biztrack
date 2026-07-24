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
