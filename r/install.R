
options(repos = c(CRAN = "https://cran.rstudio.com"))

ensure_c_std <- function() {
  if (.Platform$OS.type != "unix") return(invisible())
  cc <- tryCatch(system2("clang", "--version", stdout = TRUE, stderr = TRUE),
                 error = function(e) "")
  makeconf_cc <- R.home("etc")
  cc_line <- tryCatch(
    grep("^CC =", readLines(file.path(makeconf_cc, "Makeconf")), value = TRUE),
    error = function(e) "")
  needs_fix <- any(grepl("gnu2[0-9]", cc_line)) &&
    any(grepl("Apple clang version (1[0-6])\\.", cc))
  if (!needs_fix) return(invisible())
  mk_dir <- path.expand("~/.R")
  mk     <- file.path(mk_dir, "Makevars")
  if (!dir.exists(mk_dir)) dir.create(mk_dir, recursive = TRUE)
  existing <- if (file.exists(mk)) readLines(mk) else character()
  if (!any(grepl("^CC *=", existing))) {
    writeLines(c(existing, "CC = clang -std=gnu17"), mk)
    message("Preflight: pinned CC to gnu17 in ~/.R/Makevars ",
            "(R/clang gnu23 mismatch).")
  }
}
ensure_c_std()

required <- c(
  # tidyverselite  plumbing
  "dplyr", "tidyr", "readr", "lubridate", "tibble",
  # rendering + glue-based reporting
  "ggplot2", "glue", "jsonlite",
  # stats
  "qcc",
  # (REMOVED FROM OUR FEATURESET) simulation
  "simmer", "simmer.plot", "fitdistrplus",
  # integration api 
  "plumber",
  # shiny dashboard
  "shiny", "bslib", "DT"
)

installed <- rownames(installed.packages())
missing   <- setdiff(required, installed)

if (length(missing) == 0) {
  message("All ", length(required), " packages already installed — nothing to do.")
} else {
  message("Installing ", length(missing), " package(s): ",
          paste(missing, collapse = ", "))
  install.packages(missing)
}

# Verify everything loads; fail loudly if a package didn't install cleanly.
ok <- vapply(required, requireNamespace, logical(1), quietly = TRUE)
if (!all(ok)) {
  stop("These packages failed to install/load: ",
       paste(required[!ok], collapse = ", "))
}
message("install.R OK — ", length(required), " packages ready.")
