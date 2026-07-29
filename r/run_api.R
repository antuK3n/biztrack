# run_api.R — start the Plumber compute service.
#
#   cd r && Rscript run_api.R
#   curl localhost:8787/health
#
# Then, from api/:  php artisan analytics:refresh
#
# Bound to 127.0.0.1 deliberately. Plumber has no authentication of its own, so
# anything that can reach this port can read register data — and every payload it
# receives is register rows. Localhost only; the live tunnel forwards the web port
# and must never forward this one.
#
# Unlike the old surface, nothing here reads outputs/, so run_all.R is no longer a
# prerequisite. Laravel pushes the rows.
library(plumber)

port <- as.integer(Sys.getenv("BIZTRACK_R_PORT", "8787"))

pr("plumber.R") |> pr_run(host = "127.0.0.1", port = port)
