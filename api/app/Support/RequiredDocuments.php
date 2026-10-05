<?php

namespace App\Support;

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\Business;
use App\Models\DocumentType;
use App\Models\PermitType;
use Illuminate\Support\Collection;

/**
 * Which of the Documentary Requirements a filing must have uploaded to submit.
 *
 * ── Why the server asks now ─────────────────────────────────────────────────
 *
 * It never did. The wizard's Documents step gated Next on its list and
 * `ApplicationController::submit` took whatever arrived, so browser testing on
 * 5 October 2026 submitted a new application with NO documents at all — no
 * DTI/SEC/CDA registration, no location sketch — straight through the API, and
 * an amendment with no affidavit. The wizard's own note said where the gate
 * would go if it was ever wanted ("beside the amendment and prior-permit
 * checks"), and this is it.
 *
 * ── One list, read the wizard's way ────────────────────────────────────────
 *
 * The rows are the BUSINESS permit's `permit_type_requirements` — the same
 * reference rows the wizard is handed by `/permit-types` — and which apply is
 * decided by the same `context` tokens and `is_mandatory`, read here exactly
 * as `requiredDocs` in ApplyWizard.tsx reads them. That rule is ported, not
 * re-invented: change one and the other must follow, or the wizard will let
 * an applicant reach Submit for the server to refuse.
 *
 *  - an AMENDMENT answers from its own tokens alone (`amendment`, and the
 *    `amend_*` tokens of the FO-003 boxes it ticks) plus `all`;
 *  - anything else matches `all`, its own type, `BUSINESS`, and the answers
 *    `tax_incentives`, `rented` and `owned` off the business record;
 *  - an unrecognised token matches nothing (`renewal_off` is how a row is
 *    switched off), so extending the vocabulary never asks everybody.
 *
 * Optional rows (Other Requirements, the SPA) are never required.
 *
 * Only a filing carrying the business permit has the step at all: a
 * clearance-only renewal asks each office's documents inside its own sheet.
 */
final class RequiredDocuments
{
    /**
     * The required document types this filing has not uploaded.
     *
     * @return Collection<int, DocumentType>
     */
    public static function missingFor(Application $app): Collection
    {
        $uploaded = $app->documents()->pluck('document_type_id')->filter()->unique()->all();

        return self::requiredFor($app)
            ->reject(fn (DocumentType $dt) => in_array($dt->id, $uploaded, true))
            ->values();
    }

    /** @return Collection<int, DocumentType> */
    public static function requiredFor(Application $app): Collection
    {
        if (! $app->permitTypes()->where('permit_types.code', PermitType::OUTCOME_CODE)->exists()) {
            return collect();
        }

        $business = PermitType::where('code', PermitType::OUTCOME_CODE)->with('documentTypes')->first();
        if ($business === null) {
            return collect();
        }

        $applies = self::matcher($app);

        return $business->documentTypes
            ->filter(fn (DocumentType $dt) => (bool) ($dt->pivot->is_mandatory ?? false))
            ->filter(fn (DocumentType $dt) => $applies((string) ($dt->pivot->context ?? '')))
            ->unique('id')
            ->values();
    }

    /** `appliesNow` from ApplyWizard.tsx, over the business on record. */
    private static function matcher(Application $app): \Closure
    {
        /** @var Business|null $biz */
        $biz = $app->business()->withTrashed()->first();
        $type = $app->application_type?->value;

        if ($app->application_type === ApplicationType::Amendment) {
            $groups = $app->requestedChanges()->pluck('field')
                ->map(fn (string $field) => AmendableFields::kinds()[$field]['group'] ?? null)
                ->filter()->unique()->all();
            $ticked = fn (string $group) => in_array($group, $groups, true);

            $form = Business::normalizeRegistrationType($biz?->registration_type);
            $sole = $form !== null && (Business::REGISTRAR_BY_FORM[$form] ?? null) === 'DTI';
            $incorporated = $form !== null && ! $sole;
            $namedBox = $ticked('address') || $ticked('ownership') || $ticked('trade_name');
            $rented = (bool) ($biz?->is_rented ?? false);

            return function (string $context) use ($ticked, $sole, $incorporated, $namedBox, $rented): bool {
                $tokens = self::tokens($context);
                if (in_array('all', $tokens, true)) {
                    return true;
                }
                foreach ($tokens as $t) {
                    $hit = match ($t) {
                        'amendment' => true,
                        'amend_address' => $ticked('address'),
                        'amend_owner' => $ticked('ownership'),
                        'amend_trade_name' => $ticked('trade_name'),
                        'amend_address_rented' => $ticked('address') && $rented,
                        'amend_address_owned' => $ticked('address') && ! $rented,
                        'amend_sole' => $namedBox && $sole,
                        'amend_corporate' => $namedBox && $incorporated,
                        default => false,
                    };
                    if ($hit) {
                        return true;
                    }
                }

                return false;
            };
        }

        $rented = (bool) ($biz?->is_rented ?? false);
        $incentives = (bool) ($biz?->has_tax_incentives ?? false);

        return function (string $context) use ($type, $rented, $incentives): bool {
            $tokens = self::tokens($context);
            if ($tokens === [] || in_array('all', $tokens, true) || in_array($type, $tokens, true)) {
                return true;
            }
            foreach ($tokens as $t) {
                if (strtoupper($t) === PermitType::OUTCOME_CODE
                    || ($t === 'tax_incentives' && $incentives)
                    || ($t === 'rented' && $rented)
                    || ($t === 'owned' && ! $rented)) {
                    return true;
                }
            }

            return false;
        };
    }

    /** @return list<string> */
    private static function tokens(string $context): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $context)), fn ($t) => $t !== ''));
    }
}
