{{--
    Shown only when these figures were computed as the report was made, rather
    than read from a scheduled refresh.

    This used to mean "the PHP fallback ran because the R service was down", and
    the paragraph warned the reader that the reference implementation had not
    produced the document. R has been removed and there is one implementation, so
    there is nothing left to warn about: the numbers are the same numbers either
    way.

    What survives is the freshness question, and it is worth a line on paper for
    the reason the whole partial existed. A reader holding a printout months later
    can see the timestamp but cannot tell whether it is the hour the nightly
    refresh ran or the moment someone pressed Export — and for a register that
    changes during the working day, those are different claims about how current
    the figures are. So the document says which it was.

    Deliberately not styled as a warning. Renewal Risk's filtered and paginated
    views can never be precomputed (see config/analytics.php), so this notice is
    permanent and routine for them; shaping it like a fault would flag correct
    operation as a defect on every export.

    Expects: $meta (AnalyticsResolver provenance).
--}}
@if (($meta['source'] ?? 'local') !== 'snapshot')
    <div class="note">
        <strong>Computed for this report.</strong>
        {{ $meta['notice'] ?? '' }}
    </div>
@endif
