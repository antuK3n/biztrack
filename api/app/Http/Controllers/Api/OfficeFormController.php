<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApplicationStatus;
use App\Enums\ClearanceStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\ApplicationOfficeForm;
use App\Models\PermitType;
use App\Services\WorkflowService;
use App\Support\ApplicationVisibility;
use App\Support\Audit;
use App\Support\OfficeFormAnswers;
use App\Support\PdfFile;
use App\Support\SheetRequirements;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Per-office application form payloads (UI prototype Parts 4-7, pages 040-044).
 * Opaque JSON keyed by permit type; no manuscript equivalent.
 *
 * Two writers share one payload: the applicant answers the questions only they
 * can answer, and the reviewing officer records the issuance dates only the
 * office can know. Anything the system already knows (application type, filing
 * date, which certificate the FSIC is for, and the floor area, storey count,
 * site tenure and authorised representative the zoning sheet carries) is
 * derived here and never asked.
 */
class OfficeFormController extends Controller
{
    /**
     * Permit types with an applicant-facing form sheet.
     *
     * The list moved onto PermitType when the clearance stage was built: it is
     * a fact about the permit type, and the stage has to answer "does Apply
     * open a form?" without going through this controller.
     */
    private const FORM_PERMIT_CODES = PermitType::OFFICE_FORM_CODES;

    /** Issuance dates: recorded by the reviewing office, never by the applicant. */
    private const OFFICER_KEYS = ['building_permit_date', 'fsec_date', 'date_issued'];

    /** GET — owner-of or application.view_all. Derived answers are merged in. */
    public function index(Request $request, Application $application): JsonResponse
    {
        $this->authorizeView($request, $application);

        $stored = ApplicationOfficeForm::with('permitType:id,code')
            ->where('application_id', $application->id)
            ->get()
            ->keyBy(fn (ApplicationOfficeForm $f) => $f->permitType?->code);

        // Every form-bearing permit type on the application shows up, even
        // before the applicant saves anything, so the wizard can render the
        // derived answers on a form the applicant has not opened yet.
        $codes = $application->permitTypes()
            ->pluck('code')
            ->filter(fn (string $code) => in_array($code, self::FORM_PERMIT_CODES, true))
            ->merge($stored->keys())
            ->unique()
            ->values();

        $forms = $codes
            ->filter(fn (string $code) => $this->readableCode($request, $code))
            ->values()
            ->map(fn (string $code) => [
                'permit_type_code' => $code,
                'form_data' => OfficeFormAnswers::derive($application, $code, $stored[$code]->form_data ?? []),
                /*
                 * What this office's paper asks the applicant to bring. It
                 * rides on this payload rather than on an endpoint of its own
                 * because the sheet renders it, the sheet already loads this,
                 * and a second round trip would buy nothing — the same
                 * reasoning ReferenceController gives for the zoning maps.
                 *
                 * Which sheets have one is `SheetRequirements`' business, not
                 * this controller's: CPDD's checklist and CENRO's FOR RENEWAL
                 * row are two answers to one question, and the officer's review
                 * screen asks it too.
                 *
                 * Null, not empty, for a sheet with none: an empty array reads
                 * as "this office asks for nothing", which is a claim, and it is
                 * not one CHO, BFP or OBO have made.
                 */
                'requirements' => SheetRequirements::for($application, $code),
            ])
            ->values();

        return response()->json(['data' => $forms]);
    }

    /**
     * POST — one file into one slot of the zoning checklist.
     *
     * Owner only, and only while the sheet itself is theirs to write. The
     * checklist is part of the sheet: an office that has accepted the form has
     * accepted the documents attached to it, and swapping a title deed
     * underneath an approval is the same defect as rewriting an answer.
     *
     * Deliberately NOT `HeldPermits`: that mechanism keys its files on
     * `permit_type_id` and deletes every other row carrying the same permit, so
     * routing checklist uploads through it would have each new attachment
     * silently delete the applicant's certificate — and the certificate delete
     * the checklist. These leave `permit_type_id` null and are found by their
     * document type. See the note on ZoningRequirements::uploads.
     */
    public function storeRequirement(
        Request $request,
        Application $application,
        string $permitTypeCode,
        string $documentCode,
    ): JsonResponse {
        [$permitType] = $this->authorizeRequirementWrite($request, $application, $permitTypeCode, $documentCode);

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ], [
            'file.required' => 'Choose the file to upload.',
            'file.max' => 'The file may not be larger than 10MB.',
            'file.mimes' => 'Upload a PDF, JPG, or PNG file.',
        ]);

        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension() ?: $file->guessExtension();
        $filename = Str::uuid()->toString().'.'.$ext;
        $directory = "private/documents/{$application->id}";

        Storage::disk('local')->putFileAs($directory, $file, $filename);

        $document = ApplicationDocument::create([
            'application_id' => $application->id,
            'document_type_id' => SheetRequirements::documentType($permitTypeCode, $documentCode)->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => "{$directory}/{$filename}",
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
        ]);
        Audit::log('document.uploaded', $document);

        // One file per slot: uploading again replaces, so CPDD never has to work
        // out which of two tax declarations is the live one.
        $this->forgetRequirement($application, $documentCode, $document->id);

        return response()->json([
            'data' => [
                'permit_type_code' => $permitType->code,
                'requirements' => SheetRequirements::for($application, $permitTypeCode),
            ],
        ], 201);
    }

    /**
     * GET — Section X of MCG-CPDD-FO-003 v1.2 as a printable page.
     *
     * The declaration is sworn before a notary and the online flow has no step
     * for that at all (questions-for-malabon C9 item 2). Until the LGU settles
     * how notarisation is meant to work in this channel, the one useful thing
     * BizTrack can do is hand the applicant the exact page the notary expects,
     * with their filing already named on it — so what comes back as a scan can
     * be matched to an application rather than to a business name.
     *
     * Readable by anyone who may view the filing, not just the owner: CPDD
     * looking at a notarised scan has an obvious reason to want the blank it
     * was made from.
     */
    public function declarationTemplate(Request $request, Application $application, string $permitTypeCode): Response
    {
        $this->authorizeView($request, $application);
        abort_unless($permitTypeCode === 'ZONING', 404, 'That form has no declaration.');

        $application->loadMissing('business');

        /*
         * Two values, and only two.
         *
         * The page used to be handed the business address and the applicant's
         * name as well, for a masthead and a pre-printed signature line that no
         * longer exist. What is left is what identifies the filing a scan
         * belongs to — see the note in the view for why nothing about the
         * BUSINESS itself is printed on a page that gets sworn to.
         */
        $pdf = Pdf::loadView('pdf.zoning-declaration', [
            'tracking_id' => $application->tracking_id ?? '',
            'business_name' => $application->business?->name ?? '',
        ]);

        return PdfFile::render($pdf)->download("locational-clearance-declaration-{$application->tracking_id}.pdf");
    }

    /** DELETE — take one checklist file back off. */
    public function destroyRequirement(
        Request $request,
        Application $application,
        string $permitTypeCode,
        string $documentCode,
    ): JsonResponse {
        [$permitType] = $this->authorizeRequirementWrite($request, $application, $permitTypeCode, $documentCode);

        $this->forgetRequirement($application, $documentCode, null);

        return response()->json([
            'data' => [
                'permit_type_code' => $permitType->code,
                'requirements' => SheetRequirements::for($application, $permitTypeCode),
            ],
        ]);
    }

    /**
     * The three checks both checklist writes share, in one place.
     *
     * @return array{0: PermitType}
     */
    private function authorizeRequirementWrite(
        Request $request,
        Application $application,
        string $permitTypeCode,
        string $documentCode,
    ): array {
        abort_unless(
            $application->applicant_user_id === $request->user()->id,
            403,
            'This application is not yours.'
        );

        abort_unless(
            SheetRequirements::accepts($permitTypeCode, $documentCode),
            404,
            'That is not a requirement on this checklist.',
        );

        $permitType = PermitType::where('code', $permitTypeCode)->firstOrFail();

        abort_unless(
            $application->permitTypes()->where('permit_types.id', $permitType->id)->exists(),
            422,
            'That permit type is not part of this application.'
        );

        abort_unless(
            $this->ownerMayEdit($application, $permitType),
            422,
            'This form can no longer be edited, so its requirements are fixed as CPDD received them.'
        );

        return [$permitType];
    }

    /**
     * Drop this slot's files, the stored copies with them.
     *
     * A "removed" document still on disk is not removed, and it stays
     * downloadable through /documents/{id}/download for as long as it is there
     * — the same reasoning, and the same failure, as HeldPermits::forget.
     */
    private function forgetRequirement(Application $application, string $documentCode, ?int $keepId): void
    {
        $query = ApplicationDocument::where('application_id', $application->id)
            ->whereHas('documentType', fn ($q) => $q->where('code', $documentCode));

        if ($keepId !== null) {
            $query->whereKeyNot($keepId);
        }

        foreach ($query->get() as $old) {
            if ($old->stored_path && Storage::disk('local')->exists($old->stored_path)) {
                Storage::disk('local')->delete($old->stored_path);
            }
            Audit::log('document.removed', $old);
            $old->delete();
        }
    }

    /**
     * PUT — upserts the form payload.
     *
     * Owner: draft/returned only, and may write everything except the office
     * issuance dates. Reviewing officer: the issuance dates only, at any point
     * in the review. Derived answers are re-applied on every write, so a
     * client-supplied value for them is never trusted.
     */
    public function upsert(Request $request, Application $application, string $permitTypeCode): JsonResponse
    {
        $user = $request->user();
        $isOwner = $application->applicant_user_id === $user->id;
        /*
         * A reviewer may record issuance dates only on a filing its office is
         * part of; the permission alone is no longer enough (checklist item 56).
         *
         * Item 111 narrows it once more, to the sheet as well as the filing: the
         * issuance date on the FSIC sheet is the fire office stating when it
         * issued that certificate, so it is not the sanitary officer's to write
         * even though both offices are on the filing. readableCode() is the same
         * boundary the GET uses — a sheet you may not read is not a sheet you may
         * sign.
         */
        $isReviewer = $user->hasPermission('application.review')
            && ApplicationVisibility::canView($user, $application)
            && $this->readableCode($request, $permitTypeCode);

        abort_unless($isOwner || $isReviewer, 403, 'This application is not yours.');

        $permitType = PermitType::where('code', $permitTypeCode)->firstOrFail();

        if ($isOwner) {
            abort_unless(
                $this->ownerMayEdit($application, $permitType),
                422,
                'This form can no longer be edited. Office forms are open while the application is a draft or has been returned to you, and while a clearance you applied for is still waiting on its office.'
            );
        }

        $request->validate([
            // "present", not "required": a sheet whose every answer is derived
            // (the FSIC form) legitimately posts an empty object.
            'form_data' => ['present', 'array', 'max:512'], // guard against huge payloads
            // Absent means "save only", which is the safe default: a caller that
            // does not know about submitting cannot accidentally do it.
            'submit' => ['sometimes', 'boolean'],
            // Birthdays can never be in the future (CEC "Birthday of Owner").
            'form_data.owner_birthday' => ['sometimes', 'nullable', 'date', 'before:today'],
            // An office cannot have issued a document on a future date.
            'form_data.building_permit_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'form_data.fsec_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'form_data.date_issued' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
        ], [
            'form_data.owner_birthday.before' => "The owner's birthday must be a date in the past.",
            'form_data.building_permit_date.before_or_equal' => 'The building permit date issued cannot be in the future.',
            'form_data.fsec_date.before_or_equal' => 'The FSEC date issued cannot be in the future.',
            'form_data.date_issued.before_or_equal' => 'The date issued cannot be in the future.',
        ]);

        // Opaque JSON: keep the full payload, not validated()'s narrowed keys.
        $submitted = $request->input('form_data', []);

        // Belt-and-braces size cap (~16KB serialized).
        abort_if(strlen(json_encode($submitted)) > 16384, 422, 'The form payload is too large (max 16KB).');

        abort_unless(
            $application->permitTypes()->where('permit_types.id', $permitType->id)->exists(),
            422,
            'That permit type is not part of this application.'
        );

        $existing = ApplicationOfficeForm::where('application_id', $application->id)
            ->where('permit_type_id', $permitType->id)
            ->first();
        $current = $existing?->form_data ?? [];

        if ($isOwner) {
            // The applicant owns the answers; the office dates stay as recorded.
            $formData = array_diff_key($submitted, array_flip(self::OFFICER_KEYS))
                + array_intersect_key($current, array_flip(self::OFFICER_KEYS));
        } else {
            // The officer may only touch the issuance dates.
            $formData = array_intersect_key($submitted, array_flip(self::OFFICER_KEYS)) + $current;
        }

        $formData = OfficeFormAnswers::derive($application, $permitType->code, $formData);

        $form = ApplicationOfficeForm::updateOrCreate(
            ['application_id' => $application->id, 'permit_type_id' => $permitType->id],
            ['form_data' => $formData]
        );
        Audit::log('office_form.saved', $form);

        /*
         * ── Saving is not submitting. Completing is. ──────────────────────────
         *
         * Saving used to submit outright, and that was one act too few. The Save
         * button was disabled while anything required was missing, so a
         * half-filled sheet could not be saved at all — and nothing on this
         * stage autosaves, so leaving the page lost the typing. Making the CEC
         * sheet blocking on 8 September turned that from a corner into the
         * ordinary case: Owner's Address and the certification are required, so
         * the first CEC form anybody opened was unsaveable until it was
         * finished in one sitting.
         *
         * So the client says which it is doing. `submit` is true only when
         * `officeFormMissing` reports nothing outstanding — the rule lives in
         * the browser beside the sheet it describes and has no PHP counterpart
         * to re-check against, which is why this trusts the flag. It is a weak
         * guarantee and a deliberate one: a forced `submit` on a half-filled
         * sheet is returned by the office, exactly as a badly-filled one would
         * be, and that is a much smaller cost than the alternative it replaces.
         *
         * Owner only. An officer recording issuance dates writes to the same row
         * through the same endpoint, and their save must not be read as the
         * applicant handing the sheet in.
         *
         * The service is idempotent and refuses anything past ForApproval, so
         * re-saving a submitted sheet does not re-notify an office or move a
         * permit that has already been accepted.
         */
        if ($isOwner && $request->boolean('submit')) {
            app(WorkflowService::class)->submitClearanceForm($application, $permitType);
        }

        return response()->json([
            'data' => [
                'permit_type_code' => $permitType->code,
                'form_data' => $form->form_data,
            ],
        ]);
    }

    /**
     * When the applicant may still write a sheet. TWO windows, not one.
     *
     * The first is the filing being theirs: a draft they have not sent, or a
     * filing an office has returned to them.
     *
     * The second is the clearance stage, and it is the one that is easy to
     * delete by accident. Clearances are applied for AFTER the first payment
     * (docs/clearances-after-payment.md), so the filing is already under review
     * — or for inspection, or approved — at the moment its office sheet first
     * becomes reachable. Without this window every clearance form would open
     * read-only the instant it appeared, and the applicant would be billed for
     * a clearance whose form they could never fill in.
     *
     * It closes when THAT office signs off, and not when the filing moves.
     * `completed` on the clearance's own assignment is the office saying it has
     * read the sheet and accepted it; letting the applicant rewrite it
     * afterwards would leave the register holding answers no officer approved,
     * under an approval that names them. Every other assignment state leaves it
     * open, `returned` deliberately included — an office asking for a
     * correction is the clearest possible case for the form being editable.
     *
     * Per SHEET rather than per filing, because the six clearances move
     * independently: City Health completing its review must not freeze the
     * market sheet the applicant applied for an hour ago.
     */
    private function ownerMayEdit(Application $application, PermitType $permitType): bool
    {
        if (in_array($application->status, [ApplicationStatus::Draft, ApplicationStatus::Returned], true)) {
            return true;
        }

        // A closed filing takes nothing more, whatever its assignments say.
        if (in_array($application->status, [ApplicationStatus::Rejected, ApplicationStatus::Cancelled], true)) {
            return false;
        }

        /*
         * ── The permit's own status decides, not the office's assignment ─────
         *
         * This looked for an assignment on the issuing office and allowed the
         * save while it was open. That worked only because Apply routed the
         * office immediately — and once Apply stopped doing that (see
         * `WorkflowService::startClearance`), there was no assignment at the
         * one moment the applicant most needs to write: filling in the form
         * they have just opened. The save would have been refused with "this
         * form can no longer be edited", which is the exact error the client
         * hit from the other direction on 8 September 2026.
         *
         * So it asks the permit instead. The applicant owns the sheet while the
         * office has not yet accepted it — NotStarted (opened, not submitted),
         * ForApproval (submitted, not yet read) and Returned (sent back to fix)
         * — and loses it the moment the office moves it on, which is the same
         * line `ClearanceController::storeHeld` draws for swapping the evidence.
         *
         * A permit with no pivot row at all is one this filing does not carry;
         * `upsert` has already refused that above, so `null` here means a race
         * rather than a state and is answered conservatively.
         */
        $row = $application->permitTypes
            ->firstWhere('id', $permitType->id)?->pivot
            ?? $application->permitTypes()->where('permit_types.id', $permitType->id)->first()?->pivot;

        /*
         * ── ForApproval came OFF this list on 9 September 2026 ───────────────
         *
         * It was here on the reasoning that the office had not opened the sheet
         * yet, so correcting a typo before anybody read it cost nothing. The
         * client overruled it, and their rule is the simpler one: "We do not
         * promote any editing of forms once submitted."
         *
         * That is a defensible line and arguably the safer one. An office that
         * has the sheet may read it at any moment, and a form that changes
         * under a reviewer mid-read is worse than an applicant having to ask
         * for it back — which they can, through Messages, and the office
         * returning it puts the permit at `Returned` and the sheet back in
         * their hands.
         *
         * So two states are the applicant's: NotStarted, where they are still
         * filling it in, and Returned, where an office has handed it back for
         * exactly that purpose.
         */
        return $row !== null && in_array($row->status, [
            ClearanceStatus::NotStarted,
            ClearanceStatus::Returned,
        ], true);
    }

    /**
     * May this reader see this particular office's sheet? (checklist item 111)
     *
     * The RULE is `ApplicationVisibility::readsOfficeSheet()` and its reasoning
     * is written there. What stays here is the translation from a permit-type
     * CODE — which is what this controller's routes are keyed on — to the
     * issuing department the rule compares against.
     *
     * It used to be both, and that is why the fix leaked: the officer review
     * sheet loads its office forms from `GET /assignments/{id}`, which cannot
     * call a private method on this controller and therefore filtered nothing
     * (SEP-1). Keep the rule shared. If this ever grows a second clause, the
     * clause belongs in ApplicationVisibility, or the two endpoints will
     * disagree again.
     */
    private function readableCode(Request $request, string $code): bool
    {
        return ApplicationVisibility::readsOfficeSheet(
            $request->user(),
            $this->issuingDepartmentId($code),
        );
    }

    /**
     * Which office issues this clearance. Memoised per request because index()
     * asks once per code and upsert() once per call, and the answer is seeded
     * reference data that cannot change inside one request.
     *
     * @var array<string, int|null>
     */
    private array $issuingDepartments = [];

    private function issuingDepartmentId(string $code): ?int
    {
        if (! array_key_exists($code, $this->issuingDepartments)) {
            $this->issuingDepartments[$code] = PermitType::where('code', $code)
                ->value('issuing_department_id');
        }

        return $this->issuingDepartments[$code];
    }

    /** Owner, an office routed this filing, or BPLO/admin (checklist item 56). */
    private function authorizeView(Request $request, Application $application): void
    {
        ApplicationVisibility::authorize(
            $request->user(),
            $application,
            'You may not view this application.'
        );
    }
}
