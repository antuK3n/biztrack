<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * The house style for a list endpoint: `{ data: [...], meta: {...} }`.
 *
 * Every list here was written as `->get()` back when the register held a few
 * dozen rows. Three years of history later `/inspections` was answering 2,850
 * rows and 1.8 MB on one request and the browser fell over rendering them —
 * the endpoint itself returned 200, which is what made it hard to see. The rest
 * of the lists had exactly the same shape and were only waiting their turn.
 *
 * Two rules the shape has to keep:
 *
 *  - `data` stays a plain array. Callers that unwrap `data` and iterate keep
 *    working; they see fewer rows rather than a TypeError on a paginator
 *    object. `meta` is additive.
 *  - `per_page` is clamped, not trusted. `->paginate((int) $request->query(...))`
 *    looks bounded and is not: `per_page=0` and `per_page=-1` both become an
 *    unbounded LIMIT in SQLite, and `per_page=999999` is simply obeyed. That is
 *    the same full-table read the pagination was added to prevent, one query
 *    string away.
 */
trait PaginatesLists
{
    /** Rows per page when the caller does not say. */
    protected int $defaultPerPage = 50;

    /** The most a caller may ask for, however loudly it asks. */
    protected int $maxPerPage = 200;

    /**
     * The requested page size, clamped into [1, maxPerPage].
     *
     * Non-numeric input casts to 0 and would otherwise mean "no limit", so the
     * lower clamp is doing real work, not defending against a typo.
     */
    protected function perPage(Request $request, ?int $default = null, ?int $max = null): int
    {
        $max = $max ?? $this->maxPerPage;
        $default = $default ?? $this->defaultPerPage;
        $requested = $request->query('per_page');

        if ($requested === null || $requested === '' || ! is_numeric($requested)) {
            return min($default, $max);
        }

        return max(1, min((int) $requested, $max));
    }

    /**
     * Page meta beside the rows.
     *
     * `total` is not decoration: without it a screen shows the first fifty rows
     * and silently implies that is the whole set, which is a worse bug than the
     * slow page it replaced.
     *
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    protected function pageMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
