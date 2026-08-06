# R/renewal_model.R — the fitted half of Renewal Risk.
#
# R/service.R's service_renewal_risk() scores permits against a rule book Laravel
# hands it. Nothing there is fitted; the weights were chosen, and the payload says
# so in as many words. This file is the other thing: a logistic regression fitted
# to outcomes recovered from permit history by
# api/app/Support/RenewalOutcomes.php, evaluated on a period it was not fitted on,
# and reported with the numbers that decide whether anyone should believe it.
#
# The same two rules as service.R hold here and matter more, not less:
#
#   1. PURE. No Sys.Date(), no RNG, no database. glm() is deterministic, the
#      train/test split arrives in the payload as a date Laravel computed, and
#      the same JSON in always produces the same coefficients out. A model that
#      re-fitted differently on every refresh could not be checked by anyone.
#   2. THE CALLER OWNS THE RULES. The label definition, the settle window, the
#      lead grid and the split cutoff are all Laravel's; this file fits what it
#      is given and reports what it found.
#
# ── WHY LOGISTIC AND NOT SOMETHING STRONGER ──────────────────────────────────
#
# A gradient-boosted forest would very likely score a point or two better here.
# It would also be unreadable, and the reader of this screen is a licensing
# officer deciding whether to ring a business owner. "Nothing filed yet, and this
# business has been late twice before" is a reason. A variable-importance bar is
# not. glm(family = binomial) gives one coefficient per signal, each with a sign,
# a size and a standard error, so the model can be argued with on the merits and
# a wrong one can be spotted by inspection. That is worth more than the points.
#
# ── WHAT THE NUMBERS COMING BACK ARE, AND ARE NOT ───────────────────────────
#
# `probability` here is a probability in the ordinary sense — it is fitted to
# recorded outcomes and it is reported with the calibration figures that say how
# far to trust it. That word is used carefully and only of this figure: the RULE
# score remains a rule score, is still not a probability, and its wording is
# still guarded by AnalyticsDefinitionsTest.
#
# What the figures cannot escape is where they were fitted. Almost every one of
# the outcomes in this register was written by database/seeders/
# AnalyticsHistorySeeder.php, so what is fitted below is the seeder's renewal
# behaviour, not Malabon's. The payload carries that sentence and every surface
# that shows a figure from this file shows it too, in plain words and not in a
# tooltip.

suppressWarnings(suppressMessages({
  library(stats)
}))

# --- helpers -----------------------------------------------------------------

# Area under the ROC curve, by the Mann-Whitney identity: the probability that a
# randomly chosen late cycle was scored above a randomly chosen punctual one.
# Computed from ranks rather than by sweeping thresholds so ties are handled
# correctly and no package is needed — pROC is not installed and this is four
# lines. NA when one class is absent, because AUC is undefined there rather than
# 0.5.
.rm_auc <- function(y, p) {
  y <- as.integer(y)
  ok <- !is.na(p) & !is.na(y)
  y <- y[ok]; p <- p[ok]
  n1 <- sum(y == 1); n0 <- sum(y == 0)
  if (n1 == 0 || n0 == 0) return(NA_real_)
  r <- rank(p)
  (sum(r[y == 1]) - n1 * (n1 + 1) / 2) / (n1 * n0)
}

# Mean squared error of a probability against the outcome. Unlike AUC this
# punishes a confident wrong answer, which is the failure an officer would
# actually feel, and it is why both are reported.
.rm_brier <- function(y, p) {
  ok <- !is.na(p) & !is.na(y)
  if (!any(ok)) return(NA_real_)
  mean((p[ok] - as.numeric(y[ok]))^2)
}

.rm_logit <- function(p) {
  # Clamped away from 0 and 1: a predicted certainty has infinite logit and would
  # take the calibration regression with it.
  p <- pmin(pmax(p, 1e-6), 1 - 1e-6)
  log(p / (1 - p))
}

# --- the fit -----------------------------------------------------------------

service_renewal_model <- function(payload) {
  rows    <- .rows(payload$rows)
  current <- .rows(payload$current)
  cutoff  <- .scalar(payload$split$cutoff, NA_character_)
  min_obs <- as.integer(.scalar(payload$minimum_observations, 100L))
  bins    <- as.integer(.scalar(payload$calibration_bins, 10L))
  limit   <- max(1L, as.integer(.scalar(payload$estimate_limit, 25L)))

  if (nrow(rows) == 0 || is.na(cutoff)) {
    return(.rm_unavailable(payload, "no_labelled_history"))
  }

  d <- .rm_frame(rows, payload)
  train <- d[d$split == "train", , drop = FALSE]
  test  <- d[d$split == "test", , drop = FALSE]

  # Rare levels go before anything is fitted. See .rm_fold_rare.
  folded <- .rm_fold_rare(train, as.integer(.scalar(payload$minimum_level_observations, 25L)))
  train  <- folded$data

  # Refusing to fit is a result. A model fitted on eighty rows, or on a training
  # period in which nothing was ever late, would still return coefficients and a
  # flattering AUC; saying "not enough history" is the honest output and the
  # screen renders it as such.
  if (nrow(train) < min_obs || length(unique(train$late)) < 2) {
    return(.rm_unavailable(payload, "not_enough_training_history"))
  }
  if (nrow(test) < 1 || length(unique(test$late)) < 2) {
    return(.rm_unavailable(payload, "not_enough_evaluation_history"))
  }

  terms <- .rm_usable_terms(train, payload)
  if (length(terms$keep) == 0) {
    return(.rm_unavailable(payload, "no_signal_varies"))
  }

  # Test rows are forced onto the levels the fit actually saw. A level that only
  # appears after the cutoff has no coefficient, and predict() would return NA
  # for the whole row; folding it onto the reference level gives the reader the
  # model's honest answer for "a permit like this one, minus a stage we have
  # never fitted" and the count of rows it happened to is reported.
  aligned <- .rm_align(test, train, terms$keep)
  test    <- aligned$data

  fit <- stats::glm(
    stats::as.formula(paste("late ~", paste(terms$formula, collapse = " + "))),
    family = stats::binomial(),
    data   = train
  )

  p_test <- as.numeric(stats::predict(fit, newdata = test, type = "response"))

  auc   <- .rm_auc(test$late, p_test)
  brier <- .rm_brier(test$late, p_test)

  # The reference point every skill claim is made against: predicting the
  # training period's own late rate for everything, forever. A model that cannot
  # beat that has learned nothing, and the screen should be able to say so.
  base_rate     <- mean(train$late)
  baseline      <- .rm_brier(test$late, rep(base_rate, nrow(test)))
  skill         <- if (is.na(baseline) || baseline == 0) NA_real_ else 1 - (brier / baseline)

  calib <- .rm_calibration(test$late, p_test, bins)

  list(
    available           = .b(TRUE),
    unavailable_reason  = NULL,
    generated_at        = .s(.scalar(payload$now, NA_character_)),
    engine              = .s("glm(family = binomial)"),

    label        = .rm_label_out(payload),
    split        = .rm_split_out(payload, train, test, cutoff),
    training     = .rm_period_out(train),
    evaluation   = .rm_period_out(test),
    counts       = .rm_counts_out(payload),

    coefficients = .rm_coefficients_out(fit, terms),
    dropped      = lapply(c(folded$dropped, terms$dropped),
                          function(x) list(term = .s(x$term), label = .s(x$label),
                                           reason = .s(x$reason))),

    metrics = list(
      auc                   = .r3(auc),
      brier                 = .r3(brier),
      baseline_brier        = .r3(baseline),
      skill_score           = if (is.na(skill)) NULL else .r3(skill),
      calibration_intercept = .r3(calib$intercept),
      calibration_slope     = .r3(calib$slope),
      # The gate on the word. A fitted figure only earns the name "probability"
      # when it can be read as a rate, and this says whether this one currently
      # can. When it is FALSE the screen stops calling the figure a probability
      # and calls it a ranking, which is what an uncalibrated score is.
      calibrated            = .b(calib$calibrated),
      observations          = .i(nrow(test)),
      unfitted_levels       = .i(aligned$folded)
    ),

    # Discrimination with the clock held still. See the note on .rm_horizon_auc.
    horizon_auc           = .rm_horizon_auc(test, p_test),
    calibration           = calib$bins,
    calibration_statement = .s(calib$statement),

    estimates     = .rm_estimates(fit, current, terms, train, limit),
    estimate_note = .s(.scalar(payload$estimate_note, "")),

    training_data = list(
      synthetic = .b(as.logical(.scalar(payload$training_data$synthetic, TRUE))),
      notice    = .s(.scalar(payload$training_data$notice, ""))
    ),
    methodology = .s(.scalar(payload$methodology, ""))
  )
}

# The model frame: payload columns turned into the types glm needs, with factor
# levels pinned to the payload's declared set rather than to whatever happened to
# appear. Pinning matters — if the levels were read off the data, a refresh in
# which nobody filed a draft would silently renumber every contrast and the
# coefficients would stop being comparable between runs.
.rm_frame <- function(rows, payload) {
  stages <- as.character(.scalar_vec(payload$label$stages, c("none")))
  fees   <- as.character(.scalar_vec(payload$label$fee_states, c("settled")))

  data.frame(
    late             = as.integer(rows$late),
    split            = as.character(rows$split),
    expires_on       = as.character(rows$expires_on),
    as_at            = as.character(rows$as_at),
    cycle_id         = as.integer(rows$cycle_id),
    days_to_expiry   = as.numeric(rows$days_to_expiry),
    # Time enters on a log scale. The hazard accelerates sharply in the last
    # fortnight — 41% of still-open cycles are late 180 days out against 99.6%
    # one day out — and a straight day count cannot bend like that, so a linear
    # term would misfit both ends at once and wreck the calibration this whole
    # exercise is judged on. One coefficient either way, and the reported
    # interpretation is per doubling of the days remaining, which is a sentence
    # an officer can check against their own experience.
    time_remaining   = log1p(pmax(0, as.numeric(rows$days_to_expiry))),
    renewal_stage    = factor(as.character(rows$renewal_stage), levels = stages),
    punctuality_known = as.numeric(rows$punctuality_known),
    prior_late_rate  = as.numeric(rows$prior_late_rate),
    open_findings    = as.numeric(rows$open_findings),
    fee_state        = factor(as.character(rows$fee_state), levels = fees),
    stringsAsFactors = FALSE
  )
}

# jsonlite hands a JSON array of strings back as a character vector, but a
# one-element array as a bare string and an absent key as NULL. Same three-shapes
# problem .rows() solves for tables.
.scalar_vec <- function(x, default) {
  if (is.null(x) || length(x) == 0) return(default)
  unlist(x, use.names = FALSE)
}

# Fold factor levels too thin to fit onto the reference level.
#
# This is not tidying. Left alone, `renewal_stage = draft` (four training rows,
# all one outcome) produced an estimate of -14.17 with a standard error of 378 —
# textbook quasi-separation, meaning "every draft in the training period went the
# same way, so the fit ran off to infinity and was stopped by the iteration
# limit". The number is not small or large; it is undefined. Printing it in a
# coefficient table an officer is invited to read and argue with would be worse
# than printing nothing, because it looks like a finding.
#
# A level survives if it has enough rows AND both outcomes occur in it. The
# second condition is the one that matters: a level in which nothing was ever
# late cannot have a finite coefficient however many rows it has. Survivors keep
# their contrast; the rest are folded into the reference level and named in
# `dropped`, so the table stays complete by saying what is missing.
.rm_fold_rare <- function(train, min_level) {
  dropped <- list()

  for (s in list(list(term = "renewal_stage", label = "Renewal progress"),
                 list(term = "fee_state",     label = "Unsettled fees"))) {
    col <- train[[s$term]]
    lv  <- levels(col)
    if (length(lv) == 0) next

    reference <- lv[[1]]
    keep <- character()

    for (level in lv) {
      idx <- !is.na(col) & col == level
      n   <- sum(idx)
      if (level == reference) { keep <- c(keep, level); next }
      if (n == 0) next

      outcomes <- length(unique(train$late[idx]))
      if (n < min_level || outcomes < 2) {
        dropped[[length(dropped) + 1]] <- list(
          term  = paste0(s$term, level),
          label = sprintf("%s — %s", s$label, gsub("_", " ", level)),
          reason = if (outcomes < 2) {
            sprintf("all %d training rows went the same way, so no finite coefficient exists for it — folded in with '%s'",
                    n, gsub("_", " ", reference))
          } else {
            sprintf("only %d training rows, below the %d needed to estimate it — folded in with '%s'",
                    n, min_level, gsub("_", " ", reference))
          }
        )
        next
      }
      keep <- c(keep, level)
    }

    chr <- as.character(col)
    chr[!(chr %in% keep) | is.na(chr)] <- reference
    train[[s$term]] <- factor(chr, levels = keep)
  }

  list(data = train, dropped = dropped)
}

# Which terms may enter the formula.
#
# glm() stops outright on a factor with one level, and a numeric column that
# never varies contributes a coefficient of NA that then poisons predict(). Both
# are real states of this register — `fee_state` is 'settled' on all but a
# handful of rows — so they are detected and the term is dropped WITH A STATED
# REASON rather than crashing the refresh or being quietly absent from a
# coefficient table the screen presents as complete.
.rm_usable_terms <- function(train, payload) {
  spec <- list(
    list(term = "time_remaining",    formula = "time_remaining",    label = "Time to expiry",             kind = "numeric"),
    list(term = "renewal_stage",     formula = "renewal_stage",     label = "Renewal progress",           kind = "factor"),
    list(term = "punctuality_known", formula = "punctuality_known", label = "Has a punctuality record",   kind = "numeric"),
    list(term = "prior_late_rate",   formula = "prior_late_rate",   label = "Share of earlier renewals late", kind = "numeric"),
    list(term = "open_findings",     formula = "open_findings",     label = "Open compliance findings",   kind = "numeric"),
    list(term = "fee_state",         formula = "fee_state",         label = "Unsettled fees",             kind = "factor")
  )

  keep <- list(); dropped <- list(); formula <- character()

  for (s in spec) {
    col <- train[[s$term]]
    if (s$kind == "factor") {
      present <- levels(droplevels(col[!is.na(col)]))
      if (length(present) < 2) {
        dropped[[length(dropped) + 1]] <- list(
          term = s$term, label = s$label,
          reason = sprintf("only one value (%s) appears in the training period, so it explains nothing",
                           if (length(present)) present[[1]] else "none")
        )
        next
      }
    } else if (length(unique(col[!is.na(col)])) < 2) {
      dropped[[length(dropped) + 1]] <- list(
        term = s$term, label = s$label,
        reason = "the same value on every training row, so it explains nothing"
      )
      next
    }
    keep[[length(keep) + 1]] <- s
    formula <- c(formula, s$formula)
  }

  list(keep = keep, dropped = dropped, formula = formula)
}

# Fold levels the fit never saw onto the reference level, and count how often.
.rm_align <- function(test, train, keep) {
  folded <- 0L
  for (s in keep) {
    if (s$kind != "factor") next
    seen <- levels(droplevels(train[[s$term]][!is.na(train[[s$term]])]))
    col  <- as.character(test[[s$term]])
    bad  <- !(col %in% seen) | is.na(col)
    folded <- folded + sum(bad)
    col[bad] <- seen[[1]]
    test[[s$term]] <- factor(col, levels = seen)
    train[[s$term]] <- factor(as.character(train[[s$term]]), levels = seen)
  }
  list(data = test, folded = folded)
}

# One row per coefficient, with the sentence that says what it means.
#
# The interpretation is written here rather than on the screen because it depends
# on the sign and the size, and a template in React would either be wrong for
# half the rows or so hedged as to say nothing. An odds ratio below 1 lowers the
# chance; above 1 raises it; the reader is told which, in those words.
.rm_coefficients_out <- function(fit, terms) {
  s <- summary(fit)$coefficients
  labels <- .rm_term_labels(terms)

  lapply(seq_len(nrow(s)), function(i) {
    term <- rownames(s)[i]
    est  <- unname(s[i, 1])
    or   <- exp(est)

    list(
      term        = .s(term),
      label       = .s(.rm_pretty_label(term, labels)),
      estimate    = .r3(est),
      std_error   = .r3(unname(s[i, 2])),
      z_value     = .r3(unname(s[i, 3])),
      p_value     = .n(signif(unname(s[i, 4]), 3)),
      odds_ratio  = .r3(or),
      significant = .b(unname(s[i, 4]) < 0.05),
      interpretation = .s(.rm_interpretation(term, est, or))
    )
  })
}

.rm_term_labels <- function(terms) {
  out <- list()
  for (s in terms$keep) out[[s$term]] <- s$label
  out
}

.rm_pretty_label <- function(term, labels) {
  if (term == "(Intercept)") return("Baseline")
  for (nm in names(labels)) {
    if (startsWith(term, nm)) {
      level <- substring(term, nchar(nm) + 1)
      if (nchar(level)) return(sprintf("%s — %s", labels[[nm]], gsub("_", " ", level)))
      return(labels[[nm]])
    }
  }
  term
}

.rm_interpretation <- function(term, est, or) {
  if (term == "(Intercept)") {
    return("Where the model starts before any signal is read.")
  }
  if (term == "time_remaining") {
    # The term is log1p(days), so a unit is an e-fold. Reported per doubling
    # because "twice as long left" is a thing an officer can picture.
    per_double <- exp(est * log(2))
    return(sprintf(
      "Each doubling of the days left %s the odds of a late renewal, by a factor of %.2f.",
      if (per_double < 1) "lowers" else "raises", per_double
    ))
  }
  sprintf("%s the odds of a late renewal, by a factor of %.2f.",
          if (or < 1) "Lowers" else "Raises", or)
}

# ── Discrimination with the clock held still ────────────────────────────────
#
# The single most important honesty check in this file.
#
# The evaluation set is a risk set: permits still unrenewed. The share of it that
# ends up late climbs steeply as expiry approaches, so a model that knew nothing
# but the date would still separate late from punctual across the pooled set and
# post a good AUC. Quoting only that number would be quoting the calendar and
# calling it a model.
#
# Splitting the AUC by lead time removes the calendar from the comparison: within
# one lead, every permit is the same distance from expiry, so any separation left
# is what the other four signals contribute. If these numbers sit at 0.5 the
# model is the calendar, whatever the pooled figure says, and the screen shows
# both so the reader can see which it is.
.rm_horizon_auc <- function(test, p) {
  leads <- sort(unique(test$days_to_expiry), decreasing = TRUE)
  out <- list()
  for (lead in leads) {
    idx <- test$days_to_expiry == lead
    y   <- test$late[idx]
    out[[length(out) + 1]] <- list(
      days_to_expiry = .i(lead),
      observations   = .i(sum(idx)),
      late           = .i(sum(y)),
      late_rate      = .r3(mean(y)),
      auc            = if (length(unique(y)) < 2) NULL else .r3(.rm_auc(y, p[idx]))
    )
  }
  out
}

# ── Calibration ─────────────────────────────────────────────────────────────
#
# A ranking can be perfect and the numbers still wrong: a model that scores every
# late cycle above every punctual one, but says 90% when it means 40%, has an AUC
# of 1.0 and is useless to anyone deciding how many businesses to ring today. So
# the probabilities are checked against outcomes directly, two ways.
#
#   - The regression of the outcome on the predicted log-odds. Its slope is 1 and
#     its intercept 0 when the model is right. A slope under 1 means the
#     predictions are spread too wide — too confident at both ends. An intercept
#     away from 0 means they are systematically high or low.
#   - Deciles of predicted risk against the rate actually observed in each. This
#     is the one to look at first, because it needs no statistics to read.
.rm_calibration <- function(y, p, bins) {
  y <- as.numeric(y)
  lp <- .rm_logit(p)

  slope <- NA_real_; intercept <- NA_real_
  if (length(unique(y)) > 1 && stats::var(lp) > 0) {
    m <- suppressWarnings(stats::glm(y ~ lp, family = stats::binomial()))
    intercept <- unname(stats::coef(m)[[1]])
    slope     <- unname(stats::coef(m)[[2]])
    # Calibration-in-the-large: the intercept with the slope pinned at 1, which
    # is the "are these too high or too low on average" reading. The free-slope
    # intercept above answers a different and less useful question.
    m0 <- suppressWarnings(stats::glm(y ~ 1, family = stats::binomial(), offset = lp))
    intercept <- unname(stats::coef(m0)[[1]])
  }

  # Equal-count bins, not equal-width. Predicted risk piles up at the ends here,
  # so equal-width bins would put nearly everything in two of them and report a
  # calibration curve made of noise.
  cuts <- unique(stats::quantile(p, probs = seq(0, 1, length.out = bins + 1), na.rm = TRUE))
  grp  <- if (length(cuts) < 3) rep(1L, length(p)) else cut(p, breaks = cuts, include.lowest = TRUE, labels = FALSE)

  rows <- list()
  worst <- 0
  for (g in sort(unique(grp))) {
    idx <- grp == g
    pred <- mean(p[idx]); obs <- mean(y[idx])
    worst <- max(worst, abs(pred - obs))
    rows[[length(rows) + 1]] <- list(
      bin          = .i(g),
      observations = .i(sum(idx)),
      predicted    = .r3(pred),
      observed     = .r3(obs),
      lower        = .r3(min(p[idx])),
      upper        = .r3(max(p[idx]))
    )
  }

  # The verdict, and the three ways it can fail. All three have to pass, because
  # each catches a different wrongness: the slope catches predictions spread too
  # wide or too narrow, the mean gap catches them being uniformly too high or too
  # low, and the worst decile catches a model that is right on average while
  # being badly wrong somewhere in the middle.
  calibrated <- !is.na(slope) &&
    slope >= 0.8 && slope <= 1.25 &&
    abs(mean(p) - mean(y)) <= 0.05 &&
    worst <= 0.10

  list(
    slope = slope, intercept = intercept, bins = rows, calibrated = calibrated,
    statement = .rm_calibration_statement(slope, intercept, worst, mean(y), mean(p))
  )
}

# The calibration finding in a sentence a panelist would accept, generated from
# the figures rather than written once and left to rot. Deliberately blunt: if
# the predictions are systematically high, this says so, because a calibration
# statement that only ever reports success is not a check.
.rm_calibration_statement <- function(slope, intercept, worst, observed, predicted) {
  if (is.na(slope)) {
    return(paste0("Calibration could not be measured: the evaluation period does not ",
                  "hold both outcomes across a range of predicted values."))
  }

  direction <- if (predicted > observed + 0.02) {
    sprintf("They run high: the model predicts %.0f%% late across the evaluation period where %.0f%% actually were.",
            predicted * 100, observed * 100)
  } else if (predicted < observed - 0.02) {
    sprintf("They run low: the model predicts %.0f%% late across the evaluation period where %.0f%% actually were.",
            predicted * 100, observed * 100)
  } else {
    sprintf("On average they are right: %.0f%% predicted against %.0f%% observed.",
            predicted * 100, observed * 100)
  }

  spread <- if (slope < 0.8) {
    sprintf("The spread is too wide (slope %.2f, ideal 1.00) — extreme figures are more extreme than the outcomes justify, so a 90%% should be read as a strong warning rather than as nine in ten.",
            slope)
  } else if (slope > 1.25) {
    sprintf("The spread is too narrow (slope %.2f, ideal 1.00) — the model hedges, and the real difference between a high and a low figure is larger than it shows.",
            slope)
  } else {
    sprintf("The spread is about right (slope %.2f against an ideal 1.00).", slope)
  }

  band <- sprintf("Across the risk deciles the largest gap between predicted and observed is %.0f percentage points.",
                  worst * 100)

  paste(direction, spread, band)
}

# ── Applying the fit to the permits on the watchlist now ────────────────────
#
# Three states a current permit can be in, and only one of them gets a number:
#
#   - already lapsed. The renewal IS late; there is nothing left to estimate and
#     a figure here would be a restatement of the expiry date dressed as a
#     forecast.
#   - renewal already approved. The successor is granted, so the question the
#     model answers does not apply — and it is the exact state that was excluded
#     from the fit, so there is no coefficient for it either.
#   - still open. Estimated.
#
# Saying "not applicable" three different ways is more use than a number that
# quietly means something different in each.
.rm_estimates <- function(fit, current, terms, train, limit) {
  if (nrow(current) == 0) return(list())

  seen_stage <- levels(droplevels(train$renewal_stage[!is.na(train$renewal_stage)]))
  seen_fee   <- levels(droplevels(train$fee_state[!is.na(train$fee_state)]))

  days  <- as.numeric(current$days_to_expiry)
  stage <- as.character(current$renewal_stage)
  fee   <- as.character(current$fee_state)

  state <- ifelse(days < 0, "lapsed",
           ifelse(stage == "approved", "renewed", "open"))

  nd <- data.frame(
    time_remaining    = log1p(pmax(0, days)),
    renewal_stage     = factor(ifelse(stage %in% seen_stage, stage, seen_stage[[1]]), levels = seen_stage),
    punctuality_known = as.numeric(current$punctuality_known),
    prior_late_rate   = as.numeric(current$prior_late_rate),
    open_findings     = as.numeric(current$open_findings),
    fee_state         = factor(ifelse(fee %in% seen_fee, fee, seen_fee[[1]]), levels = seen_fee),
    stringsAsFactors  = FALSE
  )

  p <- rep(NA_real_, nrow(nd))
  open <- state == "open"
  if (any(open)) {
    p[open] <- as.numeric(stats::predict(fit, newdata = nd[open, , drop = FALSE], type = "response"))
  }

  rows <- lapply(seq_len(nrow(current)), function(i) list(
    permit_id       = .i(current$permit_id[[i]]),
    business        = .s(current$business[[i]]),
    permit_type     = .s(current$permit_type[[i]]),
    barangay        = if (is.na(current$barangay[[i]])) NULL else .s(current$barangay[[i]]),
    valid_until     = .s(current$valid_until[[i]]),
    days_to_expiry  = .i(days[[i]]),
    renewal_stage   = .s(stage[[i]]),
    probability     = if (is.na(p[[i]])) NULL else .r3(p[[i]]),
    state           = .s(state[[i]]),
    state_label     = .s(switch(state[[i]],
                          lapsed  = "Already lapsed — the renewal is late",
                          renewed = "Renewal already approved",
                          "Estimated")),
    # The rule score travels beside the fitted figure, computed by Laravel from
    # the same facts at the same moment. Carried through rather than recomputed
    # here so the two numbers on the screen cannot be about different permits or
    # different days.
    rule_score      = .i(current$rule_score[[i]]),
    rule_band       = .s(current$rule_band[[i]]),
    rule_band_label = .s(current$rule_band_label[[i]]),
    .sort           = if (is.na(p[[i]])) -1 else p[[i]]
  ))

  rows <- rows[order(-vapply(rows, function(r) r$.sort, numeric(1)))]
  rows <- lapply(rows, function(r) r[names(r) != ".sort"])
  utils::head(rows, limit)
}

# --- shared output pieces ----------------------------------------------------

.rm_label_out <- function(payload) list(
  definition  = .s(.scalar(payload$label$definition, "")),
  grace_days  = .i(.scalar(payload$label$grace_days, 1L)),
  settle_days = .i(.scalar(payload$label$settle_days, 180L)),
  lead_days   = as.integer(.scalar_vec(payload$label$lead_days, integer()))
)

.rm_split_out <- function(payload, train, test, cutoff) list(
  cutoff     = .s(cutoff),
  basis      = .s(.scalar(payload$split$basis, "permit expiry date")),
  train_from = .s(min(train$expires_on)),
  train_to   = .s(max(train$expires_on)),
  test_from  = .s(min(test$expires_on)),
  test_to    = .s(max(test$expires_on)),
  random     = .b(FALSE)
)

.rm_period_out <- function(d) list(
  cycles       = .i(length(unique(d$cycle_id))),
  observations = .i(nrow(d)),
  late         = .i(sum(d$late)),
  late_rate    = .r3(mean(d$late))
)

.rm_counts_out <- function(payload) {
  c <- payload$counts
  list(
    businesses        = .i(.scalar(c$businesses, 0L)),
    cycles_found      = .i(.scalar(c$cycles_found, 0L)),
    cycles_unsettled  = .i(.scalar(c$cycles_unsettled, 0L)),
    cycles_labelled   = .i(.scalar(c$cycles_labelled, 0L)),
    late              = .i(.scalar(c$late, 0L)),
    late_rate         = .r3(.scalar(c$late_rate, 0)),
    observations      = .i(.scalar(c$observations, 0L)),
    train_observations = .i(.scalar(c$train_observations, 0L)),
    test_observations = .i(.scalar(c$test_observations, 0L))
  )
}

# The shape returned when nothing can be fitted.
#
# Every key the fitted answer carries is present and empty, because the screen and
# the PHP fallback both read this schema and a missing key is a crash where a null
# is a sentence. `available = FALSE` with a reason is a state the reader can act
# on; a rule score quietly re-labelled as a probability would not be.
.rm_unavailable <- function(payload, reason) list(
  available          = .b(FALSE),
  unavailable_reason = .s(reason),
  generated_at       = .s(.scalar(payload$now, NA_character_)),
  engine             = .s("glm(family = binomial)"),

  label      = .rm_label_out(payload),
  split      = list(cutoff = NULL, basis = .s(.scalar(payload$split$basis, "permit expiry date")),
                    train_from = NULL, train_to = NULL, test_from = NULL, test_to = NULL,
                    random = .b(FALSE)),
  training   = list(cycles = .i(0), observations = .i(0), late = .i(0), late_rate = NULL),
  evaluation = list(cycles = .i(0), observations = .i(0), late = .i(0), late_rate = NULL),
  counts     = .rm_counts_out(payload),

  coefficients = list(),
  dropped      = list(),
  metrics = list(auc = NULL, brier = NULL, baseline_brier = NULL, skill_score = NULL,
                 calibration_intercept = NULL, calibration_slope = NULL,
                 calibrated = .b(FALSE), observations = .i(0), unfitted_levels = .i(0)),
  horizon_auc           = list(),
  calibration           = list(),
  calibration_statement = .s(""),
  estimates             = list(),
  estimate_note         = .s(.scalar(payload$estimate_note, "")),

  training_data = list(
    synthetic = .b(as.logical(.scalar(payload$training_data$synthetic, TRUE))),
    notice    = .s(.scalar(payload$training_data$notice, ""))
  ),
  methodology = .s(.scalar(payload$methodology, ""))
)
