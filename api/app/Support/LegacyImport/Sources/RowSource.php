<?php

namespace App\Support\LegacyImport\Sources;

/**
 * Where legacy rows come from — a CSV file or an ODBC query.
 *
 * The importer never knows which. Both hand it rows keyed by the template's
 * column names (Template::headers()), so the dry run, the validation and the
 * write are one pipeline whichever way the data arrived (Ken's checklist,
 * "Migration 2 — reuses item 1's dry-run/validation/import pipeline").
 */
interface RowSource
{
    /**
     * Each data row, keyed by its row number as the person who made the file
     * would count it (a CSV's line number; an ODBC result's record number).
     * Values are trimmed strings, or null for an empty cell.
     *
     * Throws SourceUnreadable when the source cannot be opened or lacks a
     * required column — before any row is yielded.
     *
     * @return iterable<int, array<string, string|null>>
     */
    public function rows(): iterable;

    /** What the audit log and the screen call this source. */
    public function label(): string;
}
