# app.R — BizTrack R Analytics Shiny dashboard (progress-report UI).
#
# A conventional Shiny app: reads the precomputed artifacts produced by
# run_all.R and presents them interactively. Run run_all.R first (it generates
# data/ and outputs/), then launch with:
#
#   Rscript run_app.R          # serves on http://localhost:8788
#
# The SPC control chart is re-rendered live per department selection; the DES
# and analytics views read the batch results. Nothing here recomputes the heavy
# simulation — that stays in the offline batch companion.

suppressWarnings(suppressMessages({
  library(shiny)
  library(bslib)
  library(DT)
  library(ggplot2)
  library(dplyr)
  library(readr)
  library(glue)
}))

# Reuse the prototype's own code so the UI and the batch pipeline agree.
source("config.R")
source("R/analytics.R")
source("R/spc.R")

# ---- load precomputed artifacts --------------------------------------------
need <- c("data/applications.csv", "data/assignments.csv",
          "outputs/spc/flags.csv", "outputs/des/scenarios.csv")
missing <- need[!file.exists(need)]
if (length(missing)) {
  stop("Missing generated artifacts: ", paste(missing, collapse = ", "),
       "\n>>> Run `Rscript run_all.R` first, then relaunch the app.")
}

applications <- read_csv("data/applications.csv", show_col_types = FALSE)
assignments  <- read_csv("data/assignments.csv",  show_col_types = FALSE)
flags        <- read_csv("outputs/spc/flags.csv", show_col_types = FALSE)
scenarios    <- read_csv("outputs/des/scenarios.csv", show_col_types = FALSE)

# ---- derive the summary figures --------------------------------------------
compliance <- compute_ra11032_compliance_rate(applications)
proctime   <- average_processing_time(applications)
workload   <- department_workload_stats(assignments)
weekly     <- weekly_turnaround(assignments)

anomaly_start <- ANCHOR_DATE - ANOMALY_WEEKS * 7L
overall_comp  <- compliance$compliance_rate[compliance$scope == "overall"]
overall_proc  <- proctime$mean_days[proctime$scope == "overall"]
n_ooc         <- sum(flags$status == "out_of_control")
cho_window    <- flags |>
  filter(department_code == "CHO", status == "out_of_control",
         week_start >= anomaly_start)
base_c   <- scenarios$sim_compliance_rate[scenarios$scenario == "baseline"]
best_row <- scenarios |>
  filter(scenario != "baseline") |>
  slice_max(sim_compliance_rate, n = 1)
best_lift <- 100 * (best_row$sim_compliance_rate - base_c)

pct  <- function(x) sprintf("%.1f%%", 100 * x)
days <- function(x) sprintf("%.1f d", x)

# ---- theme (civic blue, restrained) ----------------------------------------
biz_theme <- bs_theme(
  version    = 5,
  primary    = "#0025cc",
  base_font  = font_collection("Inter", "Segoe UI", "system-ui", "sans-serif"),
  "navbar-bg" = "#1d2433"
)

# ---- helpers ----------------------------------------------------------------
pretty_dt <- function(df, perc_cols = character(), round_cols = character(),
                      digits = 2) {
  df <- df |> mutate(across(all_of(perc_cols),  ~ round(100 * .x, 1)))
  df <- df |> mutate(across(all_of(round_cols), ~ round(.x, digits)))
  names(df) <- gsub("_", " ", names(df))
  datatable(df, rownames = FALSE, options = list(
    dom = "t", paging = FALSE, ordering = TRUE,
    scrollX = TRUE, columnDefs = list(list(className = "dt-body-right",
                                           targets = "_all"))
  ), class = "compact stripe hover")
}

# ---- UI ---------------------------------------------------------------------
ui <- page_navbar(
  title = tags$span(class = "brand",
    tags$span(class = "brand-logo",
      tags$img(src = "biztrack-logo.png", height = "24", alt = "BizTrack")),
    tags$span(class = "brand-suffix", "R Analytics")
  ),
  theme = biz_theme,
  fillable = FALSE,
  header = tags$head(tags$style(HTML("
    .value-box { border: 1px solid #e3e6ec; }
    .card { border: 1px solid #e3e6ec; }
    .method-note { background:#eef2fc; border:1px solid #d1dbeb;
      border-radius:6px; padding:12px 16px; }
    body { background:#f7f8fa; }
    h2, .h2 { font-weight:600; }
    .kicker { text-transform:uppercase; letter-spacing:.06em;
      font-size:.72rem; color:#5b6472; }
    .slowdown-alert { background:#fdeceb; border:1px solid #f0c4c1;
      border-left:4px solid #bd0000; border-radius:6px; padding:12px 16px;
      color:#7a1a15; }
    .alert-none { background:#eef7ef; border:1px solid #cfe4cf;
      border-radius:6px; padding:12px 16px; color:#3d6b41; }
    .brand { display:inline-flex; align-items:center; gap:10px; }
    .brand-logo { background:#fff; border-radius:6px; padding:4px 9px;
      display:inline-flex; align-items:center; }
    .brand-logo img { display:block; }
    .brand-suffix { color:#cfd6e4; font-weight:500; font-size:.95rem;
      letter-spacing:.01em; }
  "))),

  # ---------------- Overview ----------------
  nav_panel(
    "Overview",
    div(class = "container-fluid py-3",
      div(class = "kicker mb-1", "Progress prototype · synthetic data"),
      h2("Permit processing analytics"),
      p(class = "text-muted",
        glue("36 months of synthetic history · seed {SEED} · ",
             "reproducible from `Rscript run_all.R`.")),
      layout_columns(
        fill = FALSE, col_widths = c(3, 3, 3, 3),
        value_box("RA 11032 compliance", pct(overall_comp),
                  p("approved on/before deadline"), theme = "primary"),
        value_box("Avg processing time", days(overall_proc),
                  p("submitted → approved")),
        value_box("Applications (36 mo)",
                  format(nrow(applications), big.mark = ","),
                  p(glue("{format(nrow(assignments), big.mark=',')} reviews · ",
                         "{nrow(scenarios)} DES scenarios"))),
        value_box("Weeks outside range", glue("{nrow(cho_window)} weeks"),
                  p("CHO sustained slowdown"))
      ),
      layout_columns(
        col_widths = c(6, 6),
        card(card_header("What this proves"),
          tags$ul(
            tags$li(strong("Feature 7 — SPC:"),
                    " a slowdown injected into CHO's last 6 weeks is caught",
                    " statistically while BPLO/BFP stay in control."),
            tags$li(strong("Feature 6 — DES:"),
                    glue(" simulating staffing answers the caseload question — ",
                         "baseline {pct(base_c)} on-time, best scenario ",
                         "+{round(best_lift,1)} pts.")),
            tags$li(strong("Shared generator:"),
                    " one command manufactures the believable history every",
                    " paper feature is built on.")
          )
        ),
        card(card_header("Department workload"),
          DTOutput("tbl_workload"))
      )
    )
  ),

  # ---------------- SPC ----------------
  nav_panel(
    "Processing Time Monitoring",
    div(class = "container-fluid py-3",
      div(class = "kicker mb-1",
          "Feature 6 · Permit Processing Time Monitoring"),
      h2("Weekly review turnaround"),
      div(class = "method-note mb-3",
        strong("● Injected → detected. "),
        glue("A x1.8 slowdown was deliberately injected into CHO's most recent ",
             "{ANOMALY_WEEKS} weeks (from {format(anomaly_start)}). The monitor ",
             "flags {nrow(cho_window)} consecutive weeks outside the normal range — ",
             "a genuine process shift, not a one-off spike.")),
      layout_sidebar(
        sidebar = sidebar(
          selectInput("dept", "Department",
                      choices = c("CHO", "BPLO", "BFP"), selected = "CHO"),
          helpText("Normal range calibrated on the first ~24 weeks so the recent",
                   "slowdown can't inflate its own limits."),
          width = 260
        ),
        # Top row — the three at-a-glance readings, equal width. These answer
        # "is it in range", "how long has it been out", "is it drifting" before
        # the reader reaches the chart.
        layout_columns(
          fill = FALSE, col_widths = c(4, 4, 4),
          uiOutput("spc_status_box"),
          uiOutput("spc_streak_box"),
          uiOutput("spc_gradual_box")
        ),
        uiOutput("spc_alert"),
        # Chart gets the full width: the shaded normal range is the whole point
        # of the view and it is unreadable when squeezed into a column.
        card(full_screen = TRUE,
          card_header("Department Processing Time Chart"),
          plotOutput("spc_plot", height = "440px")),
        card(card_header("Weeks Outside the Normal Range"),
          DTOutput("tbl_flags"))
      )
    )
  ),

  # ---------------- DES ----------------
  nav_panel(
    "DES — staffing scenarios",
    div(class = "container-fluid py-3",
      div(class = "kicker mb-1", "Feature 6 · Discrete-Event Simulation"),
      h2("What if we add staff?"),
      p(class = "text-muted",
        glue("simmer pipeline · {DES_REPS} reps · {DES_MONTHS}-month horizon. ",
             "On-time compliance measured against RA 11032 deadlines.")),
      layout_columns(
        col_widths = c(7, 5),
        card(full_screen = TRUE,
          card_header("On-time compliance by scenario"),
          plotOutput("des_plot", height = "380px")),
        card(card_header("Scenario detail (utilisation per resource)"),
          DTOutput("tbl_scenarios"))
      )
    )
  ),

  # ---------------- Data ----------------
  nav_panel(
    "Analytics tables",
    div(class = "container-fluid py-3",
      div(class = "kicker mb-1", "Shared analytics functions"),
      h2("The numbers the paper cites"),
      layout_columns(
        col_widths = c(6, 6),
        card(card_header("RA 11032 compliance rate"), DTOutput("tbl_compliance")),
        card(card_header("Average processing time (days)"), DTOutput("tbl_proctime"))
      )
    )
  ),

  nav_spacer(),
  nav_item(tags$span(class = "navbar-text small",
                     glue("seed {SEED} · synthetic mode")))
)

# ---- server -----------------------------------------------------------------
server <- function(input, output, session) {

  # One reactive computes everything the five monitoring panels need.
  spc_state <- reactive({
    dc  <- input$dept
    w   <- weekly |> filter(department_code == dc) |> arrange(week_start)
    lim <- compute_control_limits(weekly, dc)
    f   <- flags  |> filter(department_code == dc) |> arrange(week_start)

    # Trailing run of consecutive weeks currently outside the normal range.
    run_len <- 0L
    for (s in rev(f$status)) {
      if (identical(s, "out_of_control")) run_len <- run_len + 1L else break
    }
    last_mean <- if (nrow(w)) tail(w$mean_days, 1) else NA_real_
    direction <- if (!is.na(last_mean) && last_mean > lim$UCL) "above"
                 else if (!is.na(last_mean) && last_mean < lim$LCL) "below"
                 else "outside"

    # Gradual-slowdown (EWMA) drift active in the recent window?
    ewma_active <- any(grepl("ewma_drift", tail(f$rule_hit, 4)), na.rm = TRUE)

    flagged <- f |>
      filter(status == "out_of_control") |>
      mutate(days_beyond_range =
               round(pmax(mean_days - lim$UCL, lim$LCL - mean_days), 2)) |>
      dplyr::select(week_start, mean_days, days_beyond_range, rule_hit)

    list(lim = lim, run_len = run_len, direction = direction,
         ewma_active = ewma_active, flagged = flagged,
         dept_name = DEPARTMENTS$name[DEPARTMENTS$code == dc])
  })

  # Row 1 — Department Processing Time Chart
  output$spc_plot <- renderPlot({
    .spc_chart(weekly, flags, input$dept)
  }, res = 96)

  # Card 1 — Process Status Indicator. Named with the department so the reading
  # can never be mistaken for a different one than the chart below it.
  output$spc_status_box <- renderUI({
    s  <- spc_state()
    ok <- s$run_len == 0
    value_box(
      title = glue("Process Status · {input$dept}"),
      value = if (ok) "Within Normal Range" else "Outside Normal Range",
      p(if (ok) "Latest weeks sit inside this department's usual range."
        else glue("Latest week is {s$direction} the range.")),
      theme = if (ok) "success" else "danger"
    )
  })

  # Card 2 — how long it has been out. This was previously only inferable by
  # counting rows in the table.
  output$spc_streak_box <- renderUI({
    s <- spc_state()
    n <- s$run_len
    value_box(
      title = "Consecutive Weeks Outside",
      value = as.character(n),
      p(if (n == 0) "No unbroken run at present."
        else if (n == 1) "One week so far. Worth watching."
        else "A sustained run, not a one-off spike."),
      theme = if (n == 0) "success" else if (n < 2) "warning" else "danger"
    )
  })

  # Card 3 — Gradual Slowdown Detector.
  output$spc_gradual_box <- renderUI({
    s  <- spc_state()
    on <- s$ewma_active
    value_box(
      title = "Gradual Slowdown (EWMA)",
      value = if (on) "Drift detected" else "No drift",
      p(if (on) "Small week-on-week increases are accumulating."
        else "No steady downward trend in speed."),
      theme = if (on) "warning" else "secondary"
    )
  })

  # Row 5 — Slowdown Alert
  output$spc_alert <- renderUI({
    s <- spc_state()
    if (s$run_len < 1) {
      div(class = "alert-none",
          strong("No active slowdown alert. "),
          "This department is operating within its normal range.")
    } else {
      div(class = "slowdown-alert",
        strong("⚠ Slowdown Alert. "),
        glue("Processing Time Alert: the average review time of the ",
             "{s$dept_name} has been {s$direction} its normal range for ",
             "{s$run_len} consecutive week(s)."))
    }
  })

  # Row 3 — Flagged Weeks List
  output$tbl_flags <- renderDT({
    spc_state()$flagged |>
      pretty_dt(round_cols = c("mean_days", "days_beyond_range"))
  })

  output$des_plot <- renderPlot({
    base_c <- scenarios$sim_compliance_rate[scenarios$scenario == "baseline"]
    ggplot(scenarios, aes(reorder(scenario, sim_compliance_rate),
                          sim_compliance_rate)) +
      geom_col(fill = "#0025cc", width = 0.6) +
      geom_hline(yintercept = base_c, colour = "#7796c5", linetype = "dashed") +
      geom_text(aes(label = sprintf("%.1f%%", 100 * sim_compliance_rate)),
                hjust = -0.15, size = 4) +
      coord_flip(ylim = c(0, 1)) +
      labs(x = NULL, y = "simulated on-time compliance") +
      theme_minimal(base_size = 13)
  }, res = 96)

  output$tbl_scenarios <- renderDT({
    scenarios |>
      dplyr::select(scenario, sim_compliance_rate,
                    util_BPLO, util_CHO, util_BFP, util_fire) |>
      pretty_dt(perc_cols = c("sim_compliance_rate", "util_BPLO",
                              "util_CHO", "util_BFP", "util_fire"))
  })

  output$tbl_workload <- renderDT({
    workload |> pretty_dt(perc_cols = "workload_share",
                          round_cols = "mean_turnaround_days")
  })

  output$tbl_compliance <- renderDT({
    compliance |> pretty_dt(perc_cols = "compliance_rate")
  })

  output$tbl_proctime <- renderDT({
    proctime |> pretty_dt(round_cols = c("mean_days", "median_days"))
  })
}

shinyApp(ui, server)
