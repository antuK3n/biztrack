<?php

namespace App\Support\LegacyImport\Sources;

use App\Support\LegacyImport\SourceUnreadable;
use App\Support\LegacyImport\Template;

/**
 * Turn a source's own header into the template's columns.
 *
 * Headers are matched case-insensitively with spaces read as underscores, so
 * "Business Name" from a spreadsheet or BUSINESS_NAME from a database both
 * land on `business_name`. Columns the template does not know are ignored; a
 * template column the source does not carry reads as empty, except the ones
 * required on every row, whose absence stops the import before it starts.
 */
trait MapsTemplateColumns
{
    /**
     * @param  list<string|null>  $header
     * @return array<int, string> source position => template column
     */
    protected function mapHeader(array $header): array
    {
        $known = array_flip(Template::headers());
        $map = [];
        foreach ($header as $i => $name) {
            $key = self::headerKey((string) $name);
            if (isset($known[$key]) && ! in_array($key, $map, true)) {
                $map[$i] = $key;
            }
        }

        $required = array_keys(array_filter(Template::COLUMNS, fn ($c) => $c[0] === true));
        $missing = array_values(array_diff($required, $map));
        if ($missing !== []) {
            throw new SourceUnreadable(
                'The source is missing '.(count($missing) === 1 ? 'a column BizTrack needs' : 'columns BizTrack needs')
                .': '.implode(', ', $missing).'. Download the template to see every column and what goes in it.'
            );
        }

        return $map;
    }

    /**
     * One source row as template columns, every column present.
     *
     * @param  array<int, mixed>  $values
     * @param  array<int, string>  $map
     * @return array<string, string|null>
     */
    protected function mapRow(array $values, array $map): array
    {
        $row = array_fill_keys(Template::headers(), null);
        foreach ($map as $i => $column) {
            $value = $values[$i] ?? null;
            if ($value === null) {
                continue;
            }
            $value = trim((string) $value);
            $row[$column] = $value === '' ? null : $value;
        }

        return $row;
    }

    public static function headerKey(string $name): string
    {
        // A UTF-8 byte-order mark, which Excel writes at the front of a CSV
        // saved as "CSV UTF-8", would otherwise make the first column unknown.
        $name = preg_replace('/^\x{FEFF}/u', '', $name) ?? $name;

        return strtolower(preg_replace('/[\s\-]+/', '_', trim($name)) ?? '');
    }
}
