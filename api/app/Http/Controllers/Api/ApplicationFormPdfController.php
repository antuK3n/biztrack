<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Enums\ApplicationType;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationOfficeForm;
use App\Models\PermitType;
use App\Models\User;
use App\Support\ApplicationVisibility;
use App\Support\Audit;
use App\Support\PaperForms;
use App\Support\PdfFile;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The filing, printed on the office's own paper form (client, 19 October 2026).
 *
 * *"It is also requested to us to have a downloadable application form in the
 * admin side when viewing the filing/application… Also, the printed format
 * should be the same as the paper-based forms."* The user picked two kinds:
 *
 *  1. the Business Permit application — MCG-BPLO-FO-001 for a new business,
 *     FO-002 for a renewal, the BPLO Amendment Form (FO-003) for an amendment;
 *  2. the office clearance sheets the owner fills in — Zoning, FSIC, Sanitary,
 *     CENRO and the OBO Unified Application.
 *
 * ── Who may print ──────────────────────────────────────────────────────────
 *
 * The officers who can open the filing in review, and nobody else:
 * `application.review` and `ApplicationVisibility::canView` — the same pair
 * OfficeFormController::upsert calls a reviewer. Every office on the filing
 * may print the Business Permit form, because every one of them already reads
 * it on the review sheet. An office SHEET follows `readsOfficeSheet`: BPLO and
 * the super admin print any, a clearance office only its own (checklist item
 * 111 — the boundary is the issuing department, and a printed copy is the same
 * read in a different format).
 *
 * The super admin does not hold `application.review` (it was taken off with
 * Track — "It is not his role to do those things") but does hold
 * `application.view_any_office` and opens every filing; it is admitted on
 * that, since printing a filing is reading one, not working it.
 *
 * Owners are not offered this: the user did not pick it, and an owner's
 * permission set carries neither door.
 *
 * Every download is written to the audit log, because a printed copy of a
 * filing leaves the system and the log is the only record that it did.
 */
class ApplicationFormPdfController extends Controller
{
    /** GET /applications/{id}/form-pdf */
    public function businessPermit(Request $request, Application $application): Response
    {
        $this->authorizeReader($request->user(), $application);

        abort_if(
            $application->status === ApplicationStatus::Draft,
            422,
            'This application has not been submitted yet.'
        );

        $type = $application->application_type ?? ApplicationType::New;
        // A yearly inspection was filed on no form (App\Support\AnnualInspection).
        abort_unless(isset(PaperForms::BPLO_FORM[$type->value]), 404, 'There is no form for this entry.');
        $doc = PaperForms::BPLO_FORM[$type->value];
        $f = PaperForms::common($application);

        [$view, $paper] = match ($type) {
            ApplicationType::Renewal => ['pdf.forms.bp-renewal', 'a4'],
            ApplicationType::Amendment => ['pdf.forms.bp-amendment', PaperForms::LONG_BOND],
            default => ['pdf.forms.bp-new', 'a4'],
        };

        $data = ['f' => $f, 'doc' => $doc, 'ck' => self::checkbox()];
        if ($type === ApplicationType::Amendment) {
            $data['amend'] = PaperForms::amendments($application);
        } else {
            $application->loadMissing('permitTypes');
            $data['annex'] = PaperForms::annex($application);
        }

        $pdf = Pdf::loadView($view, $data)->setPaper($paper, 'portrait')
            ->setOption('enable_font_subsetting', true);

        Audit::log('application.form_downloaded', $application, [
            'form' => $doc['id'],
            'application_type' => $type->value,
        ]);

        return PdfFile::render($pdf)->download(
            strtolower($doc['id']).'-'.$application->tracking_id.'.pdf'
        );
    }

    /** GET /applications/{id}/office-forms/{permitTypeCode}/pdf */
    public function officeSheet(Request $request, Application $application, string $permitTypeCode): Response
    {
        $user = $request->user();
        $this->authorizeReader($user, $application);

        $code = strtoupper($permitTypeCode);
        $view = self::SHEETS[$code] ?? null;
        abort_if($view === null, 404, 'That office has no application form.');

        $permitType = PermitType::where('code', $code)->firstOrFail();

        abort_unless(
            ApplicationVisibility::readsOfficeSheet($user, $permitType->issuing_department_id),
            403,
            'That form belongs to another office.'
        );

        $application->loadMissing('permitTypes');
        abort_unless(
            $application->permitTypes->contains('id', $permitType->id),
            404,
            'This filing does not include that clearance.'
        );

        // A sheet nobody filled in is not a form to print — every box but the
        // derived ones would be blank, and it would look like a filed copy.
        abort_unless(
            ApplicationOfficeForm::where('application_id', $application->id)
                ->where('permit_type_id', $permitType->id)
                ->exists(),
            404,
            'The applicant has not filled in this form yet.'
        );

        $f = PaperForms::common($application);
        $sheet = PaperForms::sheet($application, $code);

        $pdf = Pdf::loadView($view[0], [
            'f' => $f,
            'd' => $sheet['data'],
            'ticks' => $sheet['ticks'],
            'saved_on' => $sheet['saved_on'],
            'fee' => PaperForms::feeFor($application, $code),
            'ck' => self::checkbox(),
        ])->setPaper($view[1], 'portrait')->setOption('enable_font_subsetting', true);

        Audit::log('application.form_downloaded', $application, [
            'form' => $code,
            'permit_type_id' => $permitType->id,
        ]);

        return PdfFile::render($pdf)->download(strtolower($code).'-form-'.$application->tracking_id.'.pdf');
    }

    /** View and paper per office sheet. */
    private const SHEETS = [
        'ZONING' => ['pdf.forms.zoning', 'a4'],
        'SANITARY' => ['pdf.forms.sanitary', 'a4'],
        'CEC' => ['pdf.forms.cec', 'a4'],
        'FSIC' => ['pdf.forms.fsic', PaperForms::LONG_BOND],
        'OCCUPANCY' => ['pdf.forms.occupancy', PaperForms::LONG_BOND],
    ];

    private function authorizeReader(User $user, Application $application): void
    {
        abort_unless(
            ($user->hasPermission('application.review') || ApplicationVisibility::readsEveryOffice($user))
                && $user->hasPermission(ApplicationVisibility::VIEW_ALL)
                && ApplicationVisibility::canView($user, $application),
            403,
            'You may not print this application.'
        );
    }

    /** ☒ / ☐ — the paper's box, ticked or not. */
    private static function checkbox(): \Closure
    {
        return fn ($on): string => $on ? '☒' : '☐';
    }
}
