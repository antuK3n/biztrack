<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessLine extends Model
{
    protected $fillable = ['business_id', 'psic_code_id', 'capitalization'];

    protected $casts = ['capitalization' => 'decimal:2'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function psicCode(): BelongsTo
    {
        return $this->belongsTo(PsicCode::class);
    }

    /**
     * How this line of business READS — the applicant's own words first.
     *
     * ── The certificate that said "Other (not listed)" ──────────────────────
     *
     * A permit printed the PSIC title and nothing else, so a hardware store
     * filed under the catch-all code 00000 got a Mayor's Permit whose Line of
     * Business box read *Other (not listed)*. The client's question is the
     * whole specification: *"pwede ba yon? make sure na meron kung ano nilagay
     * nya o ininput"* [1 October 2026].
     *
     * The words were never missing. `business_lines.line_of_business` holds
     * them, the wizard REQUIRES them on a catch-all line (`needsText`), and the
     * applicant's own screens have always preferred them — `carriedOver.ts`
     * reads `line_of_business || psic_code.title`. Three server-side readers
     * disagreed with that, which is why this is a method and not a fourth copy
     * of the expression.
     *
     * Free text first, PSIC title second, for every line rather than only the
     * catch-all. On a classified line the free text is the trade as the
     * applicant describes it and the title is the statistical class it was
     * sorted into; on a document that names a particular business, the first
     * is the better answer and the second is the fallback for the lines that
     * have none.
     *
     * Null only when a line has neither, which an orphaned PSIC row can leave
     * behind. Callers print a dash.
     */
    public function tradeName(): ?string
    {
        $typed = trim((string) $this->line_of_business);

        return $typed !== '' ? $typed : $this->psicCode?->title;
    }
}
