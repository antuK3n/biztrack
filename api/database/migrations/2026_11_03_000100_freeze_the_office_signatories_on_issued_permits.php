<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Freeze the office's names onto every permit already issued.
 *
 * From 3 November 2026 a permit carries, in `issued_details`, a copy of the
 * issuing office's signatory rows as they stood when it was signed
 * (CertificateSignatories), so an office editing its names changes only the
 * permits issued afterwards. The permits issued before that read the live
 * rows — which means an edit tomorrow would rewrite a certificate signed
 * last month. This writes today's rows onto each of them once, so they too
 * stop moving.
 *
 * Only `issued_details` changes, and only where the key is absent; a second
 * run finds nothing. Counts reported (AGENTS.md §2.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('office_signatories')
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('role')
            ->get(['department_id', 'role', 'name'])
            ->groupBy('department_id')
            ->map(fn ($g) => $g->map(fn ($r) => ['role' => $r->role, 'name' => $r->name])->values()->all());

        $issuingOffice = DB::table('permit_types')->pluck('issuing_department_id', 'id');

        $frozen = 0;
        $total = 0;
        foreach (DB::table('permits')->get(['id', 'permit_type_id', 'issued_details']) as $permit) {
            $total++;
            $details = json_decode((string) $permit->issued_details, true);
            if (! is_array($details)) {
                $details = [];
            }
            if (array_key_exists('office_signatories', $details)) {
                continue;
            }
            $office = $issuingOffice[$permit->permit_type_id] ?? null;
            $details['office_signatories'] = $office !== null ? ($rows[$office] ?? []) : [];
            DB::table('permits')->where('id', $permit->id)->update([
                'issued_details' => json_encode($details, JSON_UNESCAPED_UNICODE),
            ]);
            $frozen++;
        }

        if (PHP_SAPI === 'cli') {
            fwrite(STDOUT, "office signatories frozen on {$frozen} of {$total} permit(s)".PHP_EOL);
        }
    }

    public function down(): void
    {
        // What a certificate said when it was signed is not undone.
    }
};
