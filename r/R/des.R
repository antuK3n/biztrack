# R/des.R — Feature 6: Discrete-Event Simulation.
#
# A simmer model of BizTrack's real permit flow: arrival -> payment delay ->
# three PARALLEL department reviews -> (for new applications) sanitary + fire
# inspections -> issuance. Service times are fitted (lognormal, via
# fitdistrplus) from the synthetic history, EXCLUDING the injected-anomaly weeks
# so we simulate the *normal* process, not the broken fortnight.
#
# It answers the staffing question: "+2 BFP inspectors -> what happens to
# RA 11032 on-time compliance?" by comparing scenarios.
#
# Modelling notes (documented divergences, all defensible):
#  - New (complex) applications traverse the full pipeline incl. both
#    inspections; renewals/amendments (simple) are lighter-touch — BPLO
#    re-validation only, no re-inspection — matching real LGU practice.
#  - Arrivals use a representative steady rate (see config DES_ARRIVALS_PER_DAY);
#    seasonality-in-arrivals is future work.
#  - Simulation clock is in working days (no weekends), so RA 11032 working-day
#    deadlines are compared directly against simulated flow time.

suppressWarnings(suppressMessages({
  library(dplyr)
  library(lubridate)
  library(ggplot2)
  library(tibble)
  library(simmer)
  library(fitdistrplus)
}))

DES_STAGES <- c("BPLO_review", "CHO_review", "BFP_review",
                "sanitary_inspection", "fire_inspection")

#' Fit lognormal service-time distributions per pipeline stage
#'
#' Fits a lognormal to each stage's observed durations via
#' fitdistrplus::fitdist, dropping the injected-anomaly weeks so the fit
#' reflects the normal process. Prints a compact fit summary.
#'
#' @param assignments Assignments tibble.
#' @param inspections Inspections tibble.
#' @return Named list keyed by stage, each list(meanlog, sdlog, mean_days, n).
fit_service_distributions <- function(assignments, inspections) {
  anomaly_start <- ANCHOR_DATE - ANOMALY_WEEKS * 7L

  review_dur <- assignments |>
    filter(!is.na(completed_at), as.Date(assigned_at) < anomaly_start) |>
    mutate(dur = as.numeric(completed_at - assigned_at, units = "days")) |>
    filter(dur > 0)

  insp_dur <- inspections |>
    filter(!is.na(conducted_at), as.Date(scheduled_at) < anomaly_start) |>
    mutate(dur = as.numeric(conducted_at - scheduled_at, units = "days")) |>
    filter(dur > 0)

  samples <- list(
    BPLO_review         = review_dur$dur[review_dur$department_code == "BPLO"],
    CHO_review          = review_dur$dur[review_dur$department_code == "CHO"],
    BFP_review          = review_dur$dur[review_dur$department_code == "BFP"],
    sanitary_inspection = insp_dur$dur[insp_dur$department_code == "CHO"],
    fire_inspection     = insp_dur$dur[insp_dur$department_code == "BFP"]
  )

  params <- lapply(names(samples), function(st) {
    x   <- samples[[st]]
    fit <- fitdistrplus::fitdist(x, "lnorm")
    ml  <- unname(fit$estimate["meanlog"])
    sl  <- unname(fit$estimate["sdlog"])
    list(meanlog = ml, sdlog = sl,
         mean_days = exp(ml + sl^2 / 2), n = length(x))
  })
  names(params) <- names(samples)

  message("DES service-time fits (lognormal, anomaly weeks excluded):")
  for (st in DES_STAGES) {
    p <- params[[st]]
    message(sprintf("  %-20s mean=%.2fd  meanlog=%.3f  sdlog=%.3f  (n=%d)",
                    st, p$mean_days, p$meanlog, p$sdlog, p$n))
  }
  params
}

#' Build the simmer pipeline trajectories
#'
#' Adds the department + inspector resources to `env` at the given capacities
#' and returns the complex/simple arrival trajectories that seize them. Complex
#' apps fan out to three parallel reviews (clone + synchronize) then two
#' parallel inspections; simple apps take the BPLO-only fast path.
#'
#' @param env simmer environment.
#' @param resources Named integer vector of capacities: BPLO, CHO, BFP,
#'   sanitary, fire.
#' @param params Output of fit_service_distributions().
#' @return list(complex, simple) trajectories.
build_pipeline <- function(env, resources, params) {
  env |>
    add_resource("BPLO",     resources[["BPLO"]]) |>
    add_resource("CHO",      resources[["CHO"]]) |>
    add_resource("BFP",      resources[["BFP"]]) |>
    add_resource("sanitary", resources[["sanitary"]]) |>
    add_resource("fire",     resources[["fire"]])

  svc <- function(stage) {
    p <- params[[stage]]
    function() rlnorm(1, p$meanlog, p$sdlog)
  }

  review_bplo <- trajectory() |>
    seize("BPLO", 1) |> timeout(svc("BPLO_review")) |> release("BPLO", 1)
  review_cho <- trajectory() |>
    seize("CHO", 1) |> timeout(svc("CHO_review")) |> release("CHO", 1)
  review_bfp <- trajectory() |>
    seize("BFP", 1) |> timeout(svc("BFP_review")) |> release("BFP", 1)
  insp_san <- trajectory() |>
    seize("sanitary", 1) |> timeout(svc("sanitary_inspection")) |> release("sanitary", 1)
  insp_fire <- trajectory() |>
    seize("fire", 1) |> timeout(svc("fire_inspection")) |> release("fire", 1)

  complex_traj <- trajectory("complex") |>
    timeout(function() runif(1, 0.25, 1.0)) |>       # payment / intake delay
    clone(3, review_bplo, review_cho, review_bfp) |> # three parallel reviews
    synchronize(wait = TRUE) |>                       # wait for all three
    clone(2, insp_san, insp_fire) |>                  # parallel inspections
    synchronize(wait = TRUE) |>
    timeout(function() runif(1, 0.1, 0.5))           # issuance

  simple_traj <- trajectory("simple") |>
    timeout(function() runif(1, 0.2, 0.8)) |>        # payment
    seize("BPLO", 1) |> timeout(svc("BPLO_review")) |> release("BPLO", 1) |>
    timeout(function() runif(1, 0.1, 0.4))           # issuance

  list(complex = complex_traj, simple = simple_traj)
}

# Time-weighted mean of a step-valued resource metric over [0, horizon].
.tw_mean <- function(time, value, horizon) {
  if (!length(time)) return(0)
  o <- order(time); time <- time[o]; value <- value[o]
  # state `value[i]` holds from time[i] until the next event (or horizon).
  edges <- c(time, horizon)
  dt <- diff(edges)
  keep <- dt > 0
  sum(value[keep] * dt[keep]) / horizon
}

#' Simulate one staffing scenario
#'
#' Runs `reps` replications of the pipeline at the given resource capacities and
#' returns a one-row summary: on-time compliance plus per-department mean queue
#' wait, mean queue length, and utilisation.
#'
#' @param name Scenario label.
#' @param resources Named integer capacity vector.
#' @param params Fitted service distributions.
#' @param arrivals_per_day Total arrival rate (complex+simple).
#' @param p_complex Share of arrivals that are complex (full pipeline).
#' @param months Sim horizon in 30-day months.
#' @param reps Replications to average over.
#' @return one-row tibble.
simulate_staffing_scenario <- function(name, resources, params,
                                        arrivals_per_day, p_complex,
                                        months = DES_MONTHS, reps = DES_REPS) {
  horizon <- months * 30
  lam_c   <- arrivals_per_day * p_complex
  lam_s   <- arrivals_per_day * (1 - p_complex)
  res_names <- c("BPLO", "CHO", "BFP", "sanitary", "fire")

  arr_all  <- list()
  wait_all <- list()
  res_all  <- list()

  for (r in seq_len(reps)) {
    set.seed(SEED + r)  # deterministic per replication
    env <- simmer("biztrack")
    trj <- build_pipeline(env, resources, params)
    env |>
      add_generator("complex", trj$complex, function() rexp(1, lam_c), mon = 2) |>
      add_generator("simple",  trj$simple,  function() rexp(1, lam_s), mon = 2) |>
      run(horizon)

    arr_all[[r]]  <- get_mon_arrivals(env) |> mutate(replication = r)
    wait_all[[r]] <- get_mon_arrivals(env, per_resource = TRUE) |> mutate(replication = r)
    res_all[[r]]  <- get_mon_resources(env) |> mutate(replication = r)
  }

  arr  <- bind_rows(arr_all)
  wres <- bind_rows(wait_all)
  rmon <- bind_rows(res_all)

  # --- compliance: on-time within the complexity deadline ------------------
  # RA 11032 deadlines are counted in WORKING days; the simulation clock and the
  # fitted service times are elapsed (calendar) days. Convert the working-day
  # deadline to its calendar-day equivalent (5-day work week => x 7/5) so the
  # comparison is apples-to-apples.
  wd_to_cal <- 7 / 5
  arr <- arr |>
    mutate(
      complexity = ifelse(grepl("^complex", name), "complex", "simple"),
      deadline   = ifelse(complexity == "complex",
                          DEADLINES$complex, DEADLINES$simple) * wd_to_cal,
      flow       = end_time - start_time,
      # only judge arrivals that entered early enough to have finished in time
      eligible   = start_time <= (horizon - deadline),
      on_time    = finished & (flow <= deadline)
    )
  elig <- arr |> filter(eligible)
  compliance <- if (nrow(elig)) mean(elig$on_time) else NA_real_

  # --- per-resource wait (queue time) --------------------------------------
  wait_by <- wres |>
    mutate(wait = (end_time - start_time) - activity_time) |>
    group_by(resource) |>
    summarise(wait = mean(pmax(0, wait)), .groups = "drop")

  # --- per-resource utilisation + queue length (time-weighted) -------------
  util_by <- rmon |>
    group_by(replication, resource) |>
    summarise(
      util  = .tw_mean(time, server / capacity, horizon),
      queue = .tw_mean(time, queue, horizon),
      .groups = "drop"
    ) |>
    group_by(resource) |>
    summarise(util = mean(util), queue = mean(queue), .groups = "drop")

  row <- tibble(scenario = name, sim_compliance_rate = compliance)
  for (rn in res_names) {
    row[[paste0("wait_", rn)]]  <- wait_by$wait[match(rn, wait_by$resource)]
    row[[paste0("queue_", rn)]] <- util_by$queue[match(rn, util_by$resource)]
    row[[paste0("util_", rn)]]  <- util_by$util[match(rn, util_by$resource)]
  }
  row
}

#' Run the three staffing scenarios and write outputs
#'
#' @param assignments Assignments tibble.
#' @param inspections Inspections tibble.
#' @param applications Applications tibble (for the complex share).
#' @return tibble of scenario rows; side effects: scenarios.csv + comparison PNG.
run_des <- function(assignments, inspections, applications) {
  if (!dir.exists(DES_DIR)) dir.create(DES_DIR, recursive = TRUE)
  params    <- fit_service_distributions(assignments, inspections)
  p_complex <- mean(applications$complexity == "complex")
  lambda    <- DES_ARRIVALS_PER_DAY

  base_res <- c(BPLO = DEPARTMENTS$reviewers[DEPARTMENTS$code == "BPLO"],
                CHO  = DEPARTMENTS$reviewers[DEPARTMENTS$code == "CHO"],
                BFP  = DEPARTMENTS$reviewers[DEPARTMENTS$code == "BFP"],
                sanitary = INSPECTORS$sanitary,
                fire     = INSPECTORS$fire)

  scenarios <- list(
    baseline             = base_res,
    plus2_bfp_inspectors = { r <- base_res; r["fire"] <- r["fire"] + 2L; r },
    plus1_cho_reviewer   = { r <- base_res; r["CHO"]  <- r["CHO"]  + 1L; r }
  )

  rows <- lapply(names(scenarios), function(nm) {
    message(glue::glue("DES scenario: {nm} ..."))
    simulate_staffing_scenario(nm, scenarios[[nm]], params, lambda, p_complex)
  })
  out <- bind_rows(rows)

  readr::write_csv(out, file.path(DES_DIR, "scenarios.csv"))

  base_c <- out$sim_compliance_rate[out$scenario == "baseline"]
  p <- ggplot(out, aes(reorder(scenario, sim_compliance_rate),
                       sim_compliance_rate)) +
    geom_col(fill = "#0025cc", width = 0.6) +
    geom_hline(yintercept = base_c, colour = "#bd0000", linetype = "dashed") +
    geom_text(aes(label = scales::percent(sim_compliance_rate, accuracy = 0.1)),
              hjust = -0.1, size = 3.5) +
    annotate("text", x = 0.6, y = base_c, label = "baseline",
             vjust = -0.5, hjust = 0, size = 3, colour = "#bd0000") +
    coord_flip(ylim = c(0, 1)) +
    labs(title = "RA 11032 on-time compliance by staffing scenario",
         subtitle = glue::glue("simmer DES · {DES_REPS} reps · ",
                              "{DES_MONTHS}-month horizon · dashed = baseline"),
         x = NULL, y = "simulated on-time compliance") +
    theme_minimal(base_size = 11)
  ggsave(file.path(DES_DIR, "scenario_comparison.png"), p,
         width = 8, height = 4.5, dpi = 120)

  invisible(out)
}
