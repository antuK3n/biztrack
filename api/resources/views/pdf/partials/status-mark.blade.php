{{--
    What a certificate out of force prints across its face [client, 10 November
    2026: watermark and dated banner]: a large pale diagonal word over the page,
    and one boxed line under the title saying since when.

    One partial for all six sheets — the Mayor's Permit, CENRO's certificate and
    the four clearances drawn by their own views — so a status cannot print one
    way on one sheet and another way on the next. `$status_mark` comes built
    from PermitController::statusMark; null on a permit in force, which prints
    nothing here. Styles are in permit.blade.php, the shell every sheet renders
    inside.

    The watermark is `position: fixed`, which dompdf places against the page and
    not the element it sits in, so where this partial is included only decides
    where the banner goes. `$bannerClass` lets a crowded sheet (the Certificate
    of Occupancy) set the banner smaller where it has room for it.
--}}
@if(! empty($status_mark))
    <div class="watermark">{{ $status_mark['word'] }}</div>
    <div class="status banner {{ $bannerClass ?? '' }}">{{ $status_mark['banner'] }}</div>
@endif
