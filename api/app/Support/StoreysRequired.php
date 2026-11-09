<?php

namespace App\Support;

use App\Enums\ApplicationType;
use App\Models\Application;
use App\Models\PermitType;

/**
 * Must this filing state its No. of Storeys before it is submitted?
 *
 * Client, 10 November 2026 ("ask once, lock everywhere", then "screen +
 * server"): Business Operation asks it, required, and the CPDD, BFP and OBO
 * sheets show that answer locked (OfficeFormAnswers::fromBusinessPermit). The
 * wizard holds a filing without it; this is the same rule at submit, so a
 * filing cannot reach the offices around the wizard with three sheets left to
 * ask it again.
 *
 * Only a new or renewal filing carrying the Business Permit: an amendment and
 * a clearance-only renewal never see Business Operation, so they are not
 * asked. At submit, never at autosave — drafts save half-typed by design.
 *
 * Its own class, as RequiredDocuments is, so the test fixtures that submit
 * (`attachRequiredDocuments` in tests/Pest.php) ask the same question the gate
 * asks and cannot drift from it.
 */
final class StoreysRequired
{
    public const MESSAGE = 'Enter the No. of Storeys.';

    public static function missing(Application $application): bool
    {
        if (! in_array($application->application_type, [ApplicationType::New, ApplicationType::Renewal], true)) {
            return false;
        }
        if (! $application->permitTypes()->where('code', PermitType::OUTCOME_CODE)->exists()) {
            return false;
        }

        $storeys = $application->fee_profile['storeys'] ?? null;

        return ! (is_numeric($storeys) && (int) $storeys >= 1);
    }
}
