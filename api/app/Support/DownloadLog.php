<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every file BizTrack hands a signed-in reader, written to the audit trail.
 *
 * Thesis panel revision matrix, item 2: "Every download is logged with the
 * user, the file, and the date and time." The trail recorded a document being
 * uploaded and removed, and nothing about who opened it. Each file served is
 * now one `file.downloaded` row, through Audit::log like every other action:
 *
 *   who          the row's actor, the signed-in reader
 *   which file   `kind` and `file` in the row's changes
 *   whose        the row's subject, the record the file belongs to — none for
 *                a report or export drawn from the whole register
 *   when         the row's own timestamp
 *
 * ── Called with the response, last ──────────────────────────────────────
 *
 * Every serving action ends `return DownloadLog::record($response, …)`, below
 * all of its refusals, so a 403 or a 404 never gets here and a row means the
 * file went out. The response is built before this runs, so a file that could
 * not be read or rendered is not logged either.
 *
 * The name is read off the response rather than passed in. The trail then
 * names the file exactly as the reader's browser was told to save it, and no
 * caller can log one name and send another.
 *
 * ── View and Download are the same row ──────────────────────────────────
 *
 * Both buttons fetch the same URL (web/src/lib/resources.ts, viewBlob and
 * downloadBlob). Whether the bytes then open in a tab or save to disk is the
 * browser's decision, made after they arrive, so the server cannot tell the
 * two apart and the row does not claim to.
 *
 * Not logged: the signed-in user's own profile photo, and the public permit
 * check (VerifyController), which serves no file.
 */
final class DownloadLog
{
    public const ACTION = 'file.downloaded';

    /**
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    public static function record(Response $response, string $kind, ?Model $subject = null): Response
    {
        Audit::log(self::ACTION, $subject, [
            'kind' => $kind,
            'file' => self::filename($response),
        ]);

        return $response;
    }

    /**
     * The name the reader's browser was told to save the file as.
     *
     * `filename*` first: when the real name is not plain ASCII (an owner's
     * "Pahintulot – 2026.pdf") it is carried there, percent-encoded, and
     * `filename` holds only the ASCII stand-in Symfony writes beside it.
     */
    private static function filename(Response $response): ?string
    {
        $parts = HeaderUtils::combine(HeaderUtils::split(
            (string) $response->headers->get('Content-Disposition'),
            ';=',
        ));

        $encoded = $parts['filename*'] ?? null;
        if (is_string($encoded) && stripos($encoded, "utf-8''") === 0) {
            return rawurldecode(substr($encoded, strlen("utf-8''")));
        }

        return is_string($parts['filename'] ?? null) ? $parts['filename'] : null;
    }
}
