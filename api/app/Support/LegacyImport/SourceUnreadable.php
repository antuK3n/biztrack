<?php

namespace App\Support\LegacyImport;

use RuntimeException;

/**
 * The source could not be read at all — as opposed to a row being rejected.
 *
 * Its message is written for the super admin and shown as-is: a missing
 * column, a file that is not a CSV, an ODBC driver the server lacks. It never
 * carries a password or a raw driver dump.
 */
class SourceUnreadable extends RuntimeException {}
