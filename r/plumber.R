# plumber.R — the integration surface Laravel talks to.
#
# R stays a separate program and remains the statistics engine. The direction of
# data flow is the thing to understand here: LARAVEL PUSHES ROWS TO R. R never
# touches the database. Laravel owns all SQL, which keeps RBAC and office scoping
# in exactly one place, and R is a pure compute service — rows in, statistics out.
#
#     php artisan analytics:refresh
#         ├─ Laravel queries the register
#         ├─ POSTs the row sets here
#         ├─ R computes (R/service.R, over R/spc.R)
#         └─ Laravel persists the result
#
#     page load ──> Laravel reads the persisted result   (no R involved)
#
# So these endpoints are called by the refresh command, not by page loads. That
# is what makes an analytics screen fast and what stops an R outage from breaking
# one — it only stops the figures getting newer.
#
# This replaces an earlier GET surface that served precomputed outputs/*.csv.
# Those endpoints did not fit "Laravel pushes rows" and are gone; the synthetic
# CSV pipeline (run_all.R) is untouched and remains how the R side is developed
# and validated independently of Laravel.
#
# SECURITY: bind to localhost only. Plumber has no authentication of its own, so
# anything that can reach this port can read register data. run_api.R binds
# 127.0.0.1 and the live tunnel forwards only the web port. Do not expose this.

library(plumber)
library(jsonlite)

source("config.R")
source("R/spc.R")
source("R/service.R")

#* @apiTitle BizTrack R Analytics
#* @apiDescription Pure compute endpoints. Laravel POSTs register rows; R returns
#*   statistics. Same input JSON always yields the same output.

#* One line per call, so a slow or failing dataset is visible in the console.
#* @filter logger
function(req) {
  cat(sprintf("[%s] %s %s\n", format(Sys.time(), "%H:%M:%S"),
              req$REQUEST_METHOD, req$PATH_INFO))
  plumber::forward()
}

#* Liveness and engine version.
#*
#* `analytics:refresh` calls this before building any payload, so an outage costs
#* one request instead of a full pass of register queries. The version is recorded
#* on every snapshot: if two snapshots disagree, the first question is whether the
#* engine changed underneath them.
#* @get /health
#* @serializer unboxedJSON
function() {
  list(
    status    = "ok",
    r_version = as.character(getRversion()),
    qcc       = as.character(utils::packageVersion("qcc")),
    endpoints = c("/spc/processing-time", "/renewal-risk")
  )
}

#* Permit Processing Time Monitoring — per-department control charts.
#*
#* Accepts completed review assignments; returns control limits, flagged weeks and
#* the EWMA drift reading. Computed by spc.R's qcc individuals chart, with limits
#* fitted on the leading calibration window only so a recent slowdown cannot
#* widen the limits meant to catch it.
#* @post /spc/processing-time
#* @serializer json list(auto_unbox = FALSE, null = "null", na = "null")
function(req, res) {
  .compute(req, res, service_processing_time)
}

#* Renewal Risk — permits near expiry ranked by a weighted rule score.
#*
#* Accepts per-permit renewal facts plus the rule set itself (weights, bands,
#* thresholds), so those numbers live in one place instead of being hardcoded here
#* as well. Returns a score and band per permit.
#*
#* The score is not a probability and does not estimate how likely a renewal is to
#* be late — see the note on service_renewal_risk().
#* @post /renewal-risk
#* @serializer json list(auto_unbox = FALSE, null = "null", na = "null")
function(req, res) {
  .compute(req, res, service_renewal_risk)
}

#' Run one compute function against a request body.
#'
#' Errors come back as a 500 carrying the R condition message. Laravel treats any
#' non-2xx as "R unavailable" and falls back to its own port, labelling the screen
#' accordingly — so the useful thing to return is the actual message, which is the
#' only clue about what in the payload R could not handle.
#' @keywords internal
.compute <- function(req, res, fn) {
  payload <- tryCatch(
    jsonlite::fromJSON(req$postBody, simplifyVector = TRUE, simplifyDataFrame = TRUE),
    error = function(e) NULL
  )

  if (is.null(payload)) {
    res$status <- 400
    return(list(error = "Request body was not valid JSON."))
  }

  tryCatch(
    fn(payload),
    error = function(e) {
      res$status <- 500
      cat(sprintf("  ERROR: %s\n", conditionMessage(e)))
      list(error = conditionMessage(e))
    }
  )
}
