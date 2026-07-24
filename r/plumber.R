# plumber.R — the integration surface the paper describes.
#
# Serves PRECOMPUTED artifacts from outputs/ as JSON (consistent with the
# batch-companion architecture: R runs the analytics offline, Laravel consumes
# the results over HTTP). Run via run_api.R.

library(plumber)
library(readr)
library(jsonlite)

#* @apiTitle BizTrack R Analytics
#* @apiDescription Precomputed SPC + DES analytics for BizTrack (prototype).

#* Health check
#* @get /health
#* @serializer unboxedJSON
function() {
  spc_flags <- file.path("outputs", "spc", "flags.csv")
  generated_at <- if (file.exists(spc_flags)) {
    format(file.info(spc_flags)$mtime, "%Y-%m-%dT%H:%M:%S%z")
  } else {
    NA
  }
  list(status = "ok", generated_at = generated_at)
}

#* SPC flags (weekly control-chart results)
#* @get /spc/flags
#* @serializer unboxedJSON
function(res) {
  path <- file.path("outputs", "spc", "flags.csv")
  if (!file.exists(path)) {
    res$status <- 404
    return(list(error = "flags.csv not found — run run_all.R first"))
  }
  readr::read_csv(path, show_col_types = FALSE)
}

#* DES staffing-scenario results
#* @get /des/scenarios
#* @serializer unboxedJSON
function(res) {
  path <- file.path("outputs", "des", "scenarios.csv")
  if (!file.exists(path)) {
    res$status <- 404
    return(list(error = "scenarios.csv not found — run run_all.R first"))
  }
  readr::read_csv(path, show_col_types = FALSE)
}
