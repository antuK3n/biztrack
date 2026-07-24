<?php

namespace App\Support;

use Smalot\PdfParser\Parser;
use Throwable;

/**
 * OCR-lite (master plan R19): reads the PDF *text layer* (no image OCR) and
 * regex-extracts a business name, DTI/SEC registration number, and validity
 * date. Suggestions only — callers never auto-apply. Returns [] on any failure.
 */
class OcrLite
{
    /** @return array{business_name?:string,registration_number?:string,valid_until?:string} */
    public static function extract(string $absolutePath): array
    {
        try {
            if (! is_file($absolutePath)) {
                return [];
            }
            $text = (new Parser)->parseFile($absolutePath)->getText();
        } catch (Throwable $e) {
            return [];
        }

        if (trim($text) === '') {
            return [];
        }

        $out = [];

        // DTI (e.g. DTI-01923845) or SEC (e.g. SEC-2026-4471) registration number.
        if (preg_match('/\b((?:DTI|SEC|CDA)[-\s]?[A-Z0-9\-]{4,})\b/i', $text, $m)) {
            $out['registration_number'] = trim(str_replace(' ', '-', $m[1]));
        }

        // Business name: line labelled "Business Name:" / "Name of Business:".
        if (preg_match('/(?:business\s*name|name\s*of\s*business)\s*[:\-]\s*(.+)/i', $text, $m)) {
            $name = trim(preg_split('/[\r\n]/', $m[1])[0]);
            if ($name !== '') {
                $out['business_name'] = mb_substr($name, 0, 255);
            }
        }

        // Validity / expiry date: "Valid Until: 2026-12-31" or "Valid Until December 31, 2026".
        if (preg_match('/(?:valid\s*until|valid\s*through|expiry|expiration)\s*[:\-]?\s*([A-Za-z0-9,\/\s\-]{6,30})/i', $text, $m)) {
            $raw = trim(preg_split('/[\r\n]/', $m[1])[0]);
            $ts = strtotime($raw);
            if ($ts !== false) {
                $out['valid_until'] = date('Y-m-d', $ts);
            }
        }

        return $out;
    }
}
