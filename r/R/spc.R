# R/spc.R — Feature 7: Statistical Process Control.
#
# Builds per-department X-bar control charts over weekly review turnaround and
# flags out-of-control weeks. The headline result: CHO's deliberately injected
# 6-week slowdown (see generate.R) is caught statistically — points beyond the
# control limit — while BPLO and BFP stay in control.

suppressWarnings(suppressMessages({
  library(dplyr)
  library(lubridate)
  library(ggplot2)
  library(tibble)
  library(qcc)
}))

#' Weekly review turnaround per department
#'
#' Aggregates review assignments into ISO-week buckets by completion date.
#' Weeks with fewer than 3 completed reviews are dropped (too few points for a
#' stable mean).
#'
#' @param assignments Assignments tibble.
#' @return tibble: department_code, week_start (Monday), n, mean_days.
weekly_turnaround <- function(assignments) {
  assignments |>
    filter(!is.na(completed_at)) |>
    mutate(
      turnaround = as.numeric(completed_at - assigned_at, units = "days"),
      week_start = as.Date(floor_date(as.Date(completed_at),
                                      unit = "week", week_start = 1))
    ) |>
    group_by(department_code, week_start) |>
    summarise(n = n(), mean_days = mean(turnaround), .groups = "drop") |>
    filter(n >= 3) |>
    arrange(department_code, week_start)
}

#' Control limits for one department's weekly turnaround
#'
#' X-bar (individuals) chart via qcc on the department's weekly means. Limits
#' are calibrated on the FIRST ~24 weeks only, so the injected recent slowdown
#' cannot contaminate its own control limits — the statistically honest move.
#'
#' @param weekly Output of weekly_turnaround().
#' @param department_code Department to fit.
#' @return tibble: department_code, center, LCL, UCL, calib_weeks.
compute_control_limits <- function(weekly, department_code) {
  dc <- department_code
  w  <- weekly |> filter(department_code == dc) |> arrange(week_start)
  vals    <- w$mean_days
  calib_n <- min(24L, length(vals))
  calib   <- vals[seq_len(calib_n)]

  q <- qcc::qcc(calib, type = "xbar.one", plot = FALSE)
  lim <- q$limits
  tibble(
    department_code = dc,
    center      = as.numeric(q$center),
    LCL         = max(0, as.numeric(lim[1, "LCL"])),
    UCL         = as.numeric(lim[1, "UCL"]),
    calib_weeks = calib_n
  )
}

#' Detect processing anomalies across all departments
#'
#' Applies each department's calibrated limits to every week and flags
#' out-of-control weeks by two rules: (1) beyond the X-bar control limits, and
#' (2) an EWMA pass (qcc::ewma) that catches slow drift the Shewhart chart can
#' miss.
#'
#' @param weekly Output of weekly_turnaround().
#' @return tibble: department_code, week_start, mean_days, status
#'   (in_control|out_of_control), rule_hit.
detect_processing_anomalies <- function(weekly) {
  depts <- sort(unique(weekly$department_code))
  out <- lapply(depts, function(dc) {
    w   <- weekly |> filter(department_code == dc) |> arrange(week_start)
    lim <- compute_control_limits(weekly, dc)

    beyond <- w$mean_days > lim$UCL | w$mean_days < lim$LCL

    # EWMA drift, calibrated on the same clean window.
    calib   <- w$mean_days[seq_len(lim$calib_weeks)]
    ewma_hit <- rep(FALSE, nrow(w))
    e <- tryCatch(
      qcc::ewma(w$mean_days, center = lim$center,
                std.dev = stats::sd(calib), plot = FALSE),
      error = function(err) NULL
    )
    if (!is.null(e) && length(e$violations)) {
      ewma_hit[e$violations] <- TRUE
    }

    rule_hit <- vapply(seq_len(nrow(w)), function(i) {
      hits <- c(if (beyond[i]) "beyond_limits", if (ewma_hit[i]) "ewma_drift")
      if (length(hits)) paste(hits, collapse = "+") else NA_character_
    }, character(1))

    tibble(
      department_code = dc,
      week_start      = w$week_start,
      mean_days       = w$mean_days,
      status          = ifelse(beyond | ewma_hit, "out_of_control", "in_control"),
      rule_hit        = rule_hit
    )
  })
  bind_rows(out)
}

#' Render one department's control chart to PNG
#' @keywords internal
.spc_chart <- function(weekly, flags, department_code) {
  dc  <- department_code
  w   <- weekly |> filter(department_code == dc) |> arrange(week_start)
  lim <- compute_control_limits(weekly, dc)
  f   <- flags |> filter(department_code == dc)
  w   <- w |> left_join(f |> dplyr::select(week_start, status), by = "week_start")
  ooc <- w |> filter(status == "out_of_control")

  ggplot(w, aes(week_start, mean_days)) +
    geom_hline(yintercept = lim$center, colour = "#0025cc", linewidth = 0.6) +
    geom_hline(yintercept = lim$UCL, colour = "#bd0000",
               linetype = "dashed", linewidth = 0.5) +
    geom_hline(yintercept = lim$LCL, colour = "#bd0000",
               linetype = "dashed", linewidth = 0.5) +
    geom_line(colour = "#5b6b8c", linewidth = 0.4) +
    geom_point(colour = "#33415c", size = 1.6) +
    geom_point(data = ooc, colour = "#bd0000", size = 2.8) +
    annotate("text", x = min(w$week_start), y = lim$UCL,
             label = sprintf("UCL %.2f", lim$UCL), hjust = 0, vjust = -0.4,
             size = 3, colour = "#bd0000") +
    annotate("text", x = min(w$week_start), y = lim$center,
             label = sprintf("CL %.2f", lim$center), hjust = 0, vjust = -0.4,
             size = 3, colour = "#0025cc") +
    labs(
      title    = glue::glue("{dc} — weekly review turnaround (X-bar control chart)"),
      subtitle = glue::glue("limits calibrated on first {lim$calib_weeks} weeks; ",
                            "{nrow(ooc)} out-of-control week(s) flagged"),
      x = "ISO week", y = "mean turnaround (days)"
    ) +
    theme_minimal(base_size = 11)
}

#' Run the full SPC pass: charts + flags.csv
#'
#' @param assignments Assignments tibble.
#' @return list(weekly, flags); side effects: 3 PNGs + flags.csv in outputs/spc.
run_spc <- function(assignments) {
  if (!dir.exists(SPC_DIR)) dir.create(SPC_DIR, recursive = TRUE)
  weekly <- weekly_turnaround(assignments)
  flags  <- detect_processing_anomalies(weekly)

  for (dc in sort(unique(weekly$department_code))) {
    p <- .spc_chart(weekly, flags, dc)
    ggsave(file.path(SPC_DIR, glue::glue("{dc}_control_chart.png")),
           p, width = 8, height = 4.5, dpi = 120)
  }
  readr::write_csv(flags, file.path(SPC_DIR, "flags.csv"))
  invisible(list(weekly = weekly, flags = flags))
}
