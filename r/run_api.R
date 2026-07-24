# run_api.R — start the Plumber API on :8787.
# Serves precomputed SPC + DES artifacts. Run run_all.R first so outputs/ exist.
#   Rscript run_api.R   then:  curl localhost:8787/health
library(plumber)
pr("plumber.R") |> pr_run(port = 8787)
