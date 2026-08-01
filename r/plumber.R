
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
    endpoints = c("/dashboard", "/growth/lifecycle", "/spc/processing-time", "/renewal-risk")
  )
}

#* Analytics Dashboard — the section 1 panel figures.
#*
#* Accepts counts, per-observation rows (one per decided filing, one per completed
#* review, one per permit near expiry) and the rules those are judged against:
#* RA 11032's statutory day limits, the expiry horizons, how many rows a ranking
#* shows. Returns every panel on the screen.
#*
#* Two things it will not do: report a rate with an empty denominator as 0%, and
#* average a tier the register holds no decided filing for. Both come back null,
#* with the count that explains why.
#* `digits = NA` keeps full numeric precision. jsonlite otherwise serialises at
#* four decimal places, which silently truncated the map's latitudes and
#* longitudes from six — about 11 metres of drift, and a mismatch against the PHP
#* fallback that the parity fixture caught. Every other figure here is already
#* rounded before it is emitted, so this changes nothing else.
#* @post /dashboard
#* @serializer json list(auto_unbox = FALSE, null = "null", na = "null", digits = NA)
function(req, res) {
  .compute(req, res, service_dashboard)
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

#* Business Lifecycle Monitoring — growth, status, cohort survival, trends.
#*
#* Accepts registration and closure counts, per-barangay and per-industry counts
#* for two periods, and one survival observation per business: how many renewal
#* cycles it cleared and whether it then lapsed or is still being watched.
#*
#* Cohort survival is computed with `survival::survfit`, as the client's paper
#* specifies. It is a Kaplan-Meier estimate over observed renewal cycles, not a
#* single-period ratio and not a forecast — businesses still inside their current
#* permit are censored rather than counted as failures.
#* @post /growth/lifecycle
#* @serializer json list(auto_unbox = FALSE, null = "null", na = "null")
function(req, res) {
  .compute(req, res, service_growth_lifecycle)
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
