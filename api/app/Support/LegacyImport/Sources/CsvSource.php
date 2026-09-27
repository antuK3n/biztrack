<?php

namespace App\Support\LegacyImport\Sources;

use App\Support\LegacyImport\SourceUnreadable;

/**
 * Rows from a CSV file in BizTrack's template (Template::csv()).
 *
 * Streams the file a line at a time, so a large export is never held in
 * memory whole. Two things Excel does to a CSV are undone here rather than
 * blamed on the person who saved it: a byte-order mark before the first
 * header, and Windows-1252 text (the default when "CSV" rather than
 * "CSV UTF-8" is chosen), which would otherwise turn Tañong into mojibake and
 * then into "unknown barangay".
 */
class CsvSource implements RowSource
{
    use MapsTemplateColumns;

    public function __construct(
        private string $path,
        private string $label,
    ) {}

    public function label(): string
    {
        return $this->label;
    }

    public function rows(): iterable
    {
        $handle = @fopen($this->path, 'r');
        if ($handle === false) {
            throw new SourceUnreadable('The uploaded file could not be opened. Upload it again.');
        }

        try {
            $header = $this->readLine($handle);
            if ($header === null || $header === [null]) {
                throw new SourceUnreadable('The file is empty. It needs the template’s header row, then one row per business permit.');
            }
            $map = $this->mapHeader($header);

            $line = 1;
            while (($values = $this->readLine($handle)) !== null) {
                $line++;
                // A blank line — fgetcsv's [null] — or a row of empty cells,
                // which is what a spreadsheet leaves below the data.
                if ($values === [null] || implode('', array_map('strval', $values)) === '') {
                    continue;
                }
                yield $line => $this->mapRow($values, $map);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return list<string|null>|null
     */
    private function readLine($handle): ?array
    {
        $values = fgetcsv($handle, null, ',', '"', '');
        if ($values === false) {
            return null;
        }

        return array_map(function ($v) {
            if ($v === null || mb_check_encoding($v, 'UTF-8')) {
                return $v;
            }

            return mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
        }, $values);
    }
}
