<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * A one-sheet .xlsx, written by hand.
 *
 * The Custom Report offers an Excel download (client, 19 October 2026) and the
 * project has no spreadsheet library. An .xlsx is a zip of a handful of XML
 * parts, and a table of text and numbers needs only six of them, so this
 * writes those rather than adding a dependency for one button. ext-zip is
 * already required in production: spatie/laravel-backup zips with it.
 *
 * What it supports is what a report needs and nothing more: rows of strings,
 * integers and floats; null as an empty cell (AGENTS.md §6.4: a dash, never a
 * zero — an empty cell is a spreadsheet's dash); a bold style for headings and
 * the total; and number formats so a peso column reads 1,234.50 while staying
 * a number that sums. Strings are inline, so there is no shared-string table.
 *
 * If the reports ever need several sheets, formulas or column widths beyond
 * the guess below, replace this with a real library instead of growing it.
 */
final class SimpleXlsx
{
    /** Cell styles, by index into the cellXfs written in styles(). */
    public const PLAIN = 0;

    public const BOLD = 1;

    public const COUNT = 2;

    public const MONEY = 3;

    public const COUNT_BOLD = 4;

    public const MONEY_BOLD = 5;

    public const DECIMAL = 6;

    public const DECIMAL_BOLD = 7;

    /**
     * @param  list<list<array{0: string|int|float|null, 1?: int}>>  $rows  each cell [value, style]
     * @param  list<int>  $widths  column widths in characters
     */
    public static function build(string $sheetName, array $rows, array $widths = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the spreadsheet.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the spreadsheet for writing.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::escape(self::sheetName($sheetName)).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($rows, $widths));
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** @param  list<list<array{0: string|int|float|null, 1?: int}>>  $rows */
    private static function sheet(array $rows, array $widths): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($widths !== []) {
            $xml .= '<cols>';
            foreach ($widths as $i => $width) {
                $n = $i + 1;
                $xml .= '<col min="'.$n.'" max="'.$n.'" width="'.max(6, min(80, $width)).'" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach ($rows as $r => $cells) {
            $rowNumber = $r + 1;
            $xml .= '<row r="'.$rowNumber.'">';
            foreach ($cells as $c => $cell) {
                $value = $cell[0];
                $style = $cell[1] ?? self::PLAIN;
                $ref = self::column($c).$rowNumber;
                if ($value === null || $value === '') {
                    $xml .= $style === self::PLAIN ? '' : '<c r="'.$ref.'" s="'.$style.'"/>';
                } elseif (is_int($value) || is_float($value)) {
                    $xml .= '<c r="'.$ref.'" s="'.$style.'"><v>'.(is_float($value) ? self::float($value) : $value).'</v></c>';
                } else {
                    $xml .= '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'
                        .self::escape($value).'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private static function styles(): string
    {
        // numFmt 3 is the built-in "#,##0"; 4 is "#,##0.00".
        $xf = static fn (int $font, int $fmt) => '<xf numFmtId="'.$fmt.'" fontId="'.$font.'" fillId="0" borderId="0" xfId="0"'
            .($fmt === 0 ? '' : ' applyNumberFormat="1"').($font === 0 ? '' : ' applyFont="1"').'/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="8">'
            .$xf(0, 0).$xf(1, 0).$xf(0, 3).$xf(0, 4).$xf(1, 3).$xf(1, 4).$xf(0, 4).$xf(1, 4)
            .'</cellXfs>'
            .'</styleSheet>';
    }

    /** 0 => A, 25 => Z, 26 => AA. */
    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + (($n - 1) % 26)).$name;
        }

        return $name;
    }

    private static function float(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    /** Excel refuses a sheet name over 31 characters or holding : \ / ? * [ ]. */
    private static function sheetName(string $name): string
    {
        $clean = trim((string) preg_replace('/[:\\\\\\/?*\\[\\]]/', ' ', $name));

        return mb_substr($clean === '' ? 'Report' : $clean, 0, 31);
    }

    private static function escape(string $value): string
    {
        // Control characters other than tab and newline are not legal XML.
        $value = (string) preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value);

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
