# R/db.R — PostgreSQL readers for the future "postgres" mode.
#
# These are STUBS by design. They name the real BizTrack tables and columns so
# the integration path is visible in code review, but every one refuses to run
# unless config MODE == "postgres". They are NOT exercised in the prototype;
# the prototype runs entirely on synthetic CSVs (see R/generate.R).
#
# When postgres mode is turned on, these would use DBI + RPostgres against the
# 35-table schema and return tibbles with the same column names the synthetic
# generator emits, so nothing downstream (analytics/SPC/DES) has to change.

.require_postgres <- function() {
  if (MODE != "postgres") {
    stop("postgres mode not enabled in prototype (config MODE == '",
         MODE, "'). Synthetic CSVs are the source of truth for the demo.",
         call. = FALSE)
  }
}

#' Read applications from Postgres (future mode)
#' @return tibble matching applications.csv columns
read_applications_pg <- function(conn = NULL) {
  .require_postgres()
  sql <- "
    SELECT a.id,
           a.business_id,
           a.application_type,
           a.complexity,
           a.submitted_at,
           a.approved_at,
           a.status,
           a.deadline_at
    FROM applications a"
  DBI::dbGetQuery(conn, sql)
}

#' Read department review assignments from Postgres (future mode)
#' @return tibble matching assignments.csv columns
read_assignments_pg <- function(conn = NULL) {
  .require_postgres()
  sql <- "
    SELECT asg.id,
           asg.application_id,
           d.code AS department_code,
           asg.assigned_at,
           asg.completed_at
    FROM assignments asg
    JOIN departments d ON d.id = asg.department_id"
  DBI::dbGetQuery(conn, sql)
}

#' Read inspections from Postgres (future mode)
#' @return tibble matching inspections.csv columns
read_inspections_pg <- function(conn = NULL) {
  .require_postgres()
  sql <- "
    SELECT i.id,
           i.application_id,
           d.code AS department_code,
           i.scheduled_at,
           i.conducted_at,
           i.result
    FROM inspections i
    JOIN departments d ON d.id = i.department_id"
  DBI::dbGetQuery(conn, sql)
}

#' Read businesses from Postgres (future mode)
#' @return tibble matching businesses.csv columns
read_businesses_pg <- function(conn = NULL) {
  .require_postgres()
  sql <- "
    SELECT b.id,
           b.name,
           b.barangay,
           b.registered_at
    FROM businesses b"
  DBI::dbGetQuery(conn, sql)
}

#' Read permits from Postgres (future mode)
#' @return tibble matching permits.csv columns
read_permits_pg <- function(conn = NULL) {
  .require_postgres()
  sql <- "
    SELECT p.id,
           p.business_id,
           pt.code AS permit_type_code,
           p.valid_from,
           p.valid_until,
           p.renewed_on_time
    FROM permits p
    JOIN permit_types pt ON pt.id = p.permit_type_id"
  DBI::dbGetQuery(conn, sql)
}
