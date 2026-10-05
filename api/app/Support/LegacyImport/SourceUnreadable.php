<?php

namespace App\Support\LegacyImport;

use RuntimeException;

/**
 * The source could not be read at all — as opposed to a row being rejected.
 *
 * Its message is written for the super admin and shown as-is: a missing
 * column, or a file that is not a CSV.
 */
class SourceUnreadable extends RuntimeException {}
