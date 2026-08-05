<?php

namespace App\Support;

use Smalot\PdfParser\Parser;
use Throwable;

class OcrLite
{
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

        if (preg_match('/\b((?:DTI|SEC|CDA)[-\s]?[A-Z0-9\-]{4,})\b/i', $text, $m)) {
            $out['registration_number'] = trim(str_replace(' ', '-', $m[1]));
        }

        if (preg_match('/(?:business\s*name|name\s*of\s*business)\s*[:\-]\s*(.+)/i', $text, $m)) {
            $name = trim(preg_split('/[\r\n]/', $m[1])[0]);
            if ($name !== '') {
                $out['business_name'] = mb_substr($name, 0, 255);
            }
        }

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
