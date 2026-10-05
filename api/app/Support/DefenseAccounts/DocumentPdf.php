<?php

namespace App\Support\DefenseAccounts;

/**
 * A one-page PDF standing in for a scanned requirement.
 *
 * Small and real: the upload rule is `mimes:pdf`, which reads the bytes, so
 * the file has to BE a PDF, and an officer who opens it sees a page titled
 * for what it is ("DTI Certificate of Business Name Registration — Dela Cruz
 * Sari-Sari Store") rather than a blank. Written by hand rather than through
 * dompdf because a run uploads several hundred of these and dompdf spends a
 * font load on each; dompdf renders the roster PDF, where layout matters.
 *
 * Helvetica in WinAnsiEncoding covers what Malabon's names need (ñ, the em
 * dash); anything outside it is transliterated.
 */
final class DocumentPdf
{
    /** @param  list<string>  $lines */
    public static function make(string $title, array $lines = []): string
    {
        $text = 'BT /F1 15 Tf 56 770 Td ('.self::escape($title).') Tj ET'."\n";
        $y = 740;
        foreach ($lines as $line) {
            $text .= "BT /F1 11 Tf 56 {$y} Td (".self::escape($line).") Tj ET\n";
            $y -= 18;
        }
        $text .= "BT /F1 8 Tf 56 60 Td (Specimen prepared for the BizTrack defense accounts. Not an official document.) Tj ET\n";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($text)." >>\nstream\n{$text}endstream",
            '<< /Title <'.self::utf16Hex($title).'> /Producer (BizTrack) >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n".'0000000000 65535 f '."\n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf.'trailer'."\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R /Info 6 0 R >>'."\n"
            .'startxref'."\n".$xref."\n".'%%EOF'."\n";
    }

    private static function escape(string $text): string
    {
        $bytes = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);

        return strtr($bytes === false ? $text : $bytes, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private static function utf16Hex(string $text): string
    {
        return 'FEFF'.strtoupper(bin2hex((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')));
    }
}
