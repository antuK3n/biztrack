<?php

namespace App\Support;

use Barryvdh\DomPDF\PDF;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Render a dompdf document exactly once and hand the same bytes to both the
 * archive copy and the download response.
 *
 * Dompdf's CPDF backend mutates its object table while writing: font subsets
 * get deflated in place and the CIDToGIDMap is re-emitted. Calling ->output()
 * a second time therefore compresses already-compressed streams and produces a
 * file that readers reject ("Unknown compression method in flate stream",
 * "Invalid CIDToGIDMap entry in CID font") — tester item 48. laravel-dompdf
 * guards render() but not output(), so ->output() followed by ->download() is
 * enough to corrupt the file. Render through here instead.
 */
class PdfFile
{
    public function __construct(public readonly string $content) {}

    public static function render(PDF $pdf): self
    {
        return new self($pdf->output());
    }

    public function download(string $filename): Response
    {
        $fallback = str_replace('%', '', Str::ascii($filename));

        return new Response($this->content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $filename, $fallback),
            'Content-Length' => (string) strlen($this->content),
        ]);
    }
}
