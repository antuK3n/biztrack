{{--
    Provenance for a printed analytics report.

    A PDF outlives the screen it was exported from and gets forwarded, filed and
    quoted. "Generated <date>" was ambiguous — a reader would take it as when the
    file was printed, when it actually means when the statistics were computed,
    and those differ by however long it has been since the last
    `php artisan analytics:refresh`. So the wording is "Computed", and it names
    the engine.

    Naming the engine matters more on paper than on screen: if the R service was
    down and the PHP fallback produced these numbers, that fact has to travel with
    the document rather than being something the exporter happened to see once.

    Expects: $generated_at (formatted), $meta (AnalyticsResolver provenance).
--}}
Computed {{ $generated_at }}
@if (($meta['source'] ?? 'local') === 'r')
    by R{{ isset($meta['engine_version']) ? ' '.$meta['engine_version'] : '' }}
@else
    locally, not by R
@endif
