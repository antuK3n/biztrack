# run_app.R — launch the BizTrack Shiny dashboard on :8788.
# Run run_all.R first so data/ and outputs/ exist, then:
#   Rscript run_app.R      then open  http://localhost:8788
shiny::runApp(getwd(), port = 8788, launch.browser = FALSE, host = "127.0.0.1")
