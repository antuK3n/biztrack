{{--
    Provenance for a printed analytics report.

    A PDF outlives the screen it was exported from and gets forwarded, filed and
    quoted. "Generated <date>" was ambiguous — a reader would take it as when the
    file was printed, when it actually means when the statistics were computed,
    and those differ by however long it has been since the last
    `php artisan analytics:refresh`. So the wording is "Computed", and it names
    what computed them.

    This line used to read "by R 4.6.1" or "locally, not by R", because there
    were two engines and which one ran was a fact a filed document had to carry.
    R has been removed; BizTrack computes its own statistics, so there is one
    answer and the version is no longer a variable worth printing. The client
    asked for the banner to say "by BizTrack", and that is all it needs to say —
    how fresh the figures are is a separate question, answered by the date beside
    it and by pdf.partials.local-notice.

    Expects: $generated_at (formatted), $meta (AnalyticsResolver provenance).
--}}
Computed {{ $generated_at }} by {{ $meta['engine'] ?? 'BizTrack' }}
