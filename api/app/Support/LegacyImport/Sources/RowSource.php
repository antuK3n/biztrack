<?php

namespace App\Support\LegacyImport\Sources;

/**
 * Where legacy rows come from — a CSV file in BizTrack's template.
 *
 * It hands the importer rows keyed by the template's column names
 * (Template::headers()), so the dry run, the validation and the write are one
 * pipeline.
 */
interface RowSource
{
    /**
     * Each data row, keyed by its row number as the person who made the file
     * would count it (a CSV's line number).
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
