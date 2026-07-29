{{--
    Shown only when the PHP fallback, not R, produced the figures in this report.

    R is the statistics engine; this codebase keeps a second implementation so an
    R outage cannot empty an analytics screen. The cost of two implementations is
    that they can drift, and a report that did not say which one ran would make
    any drift undetectable after the fact. So when it was the fallback, the
    document says so and says why.

    Expects: $meta (AnalyticsResolver provenance).
--}}
@if (($meta['source'] ?? 'local') !== 'r')
    <div class="note">
        <strong>These figures were computed locally, not by the R statistics service.</strong>
        {{ $meta['notice'] ?? '' }}
        The R implementation is the reference; a local computation should agree with it, but this
        report was not produced by it.
    </div>
@endif
