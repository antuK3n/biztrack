<?php

namespace App\Http\Controllers\Api;

use App\Enums\PermitStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Http\Resources\PermitResource;
use App\Models\Application;
use App\Models\Barangay;
use App\Models\Business;
use App\Models\BusinessOwner;
use App\Models\PsicCode;
use App\Support\ApplicationVisibility;
use App\Support\Audit;
use App\Support\MalabonGeo;
use App\Support\Numbering;
use App\Support\RenewablePermit;
use App\Support\Tin;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Business registry. Owners manage their own; an officer may read the record
 * behind any filing their office is allowed to open.
 */
class BusinessController extends Controller
{
    /**
     * The four structures the wizard offers, and the only values the panel bands.
     *
     * Lives on the model now (Business::ORGANIZATION_FORMS) so the migration,
     * the wizard contract and this controller cannot drift apart. Kept as an
     * alias because the two helpers below read better with the short name.
     */
    private const ORGANIZATION_FORMS = Business::ORGANIZATION_FORMS;

    /**
     * Malabon City's only postal code, used as the default for BPLO item A5.
     *
     * Named rather than inlined so that the day BizTrack licenses a second LGU,
     * the one place this assumption lives is findable. It is an assumption:
     * correct for every filing this system can currently accept, because the
     * wizard will not save a map pin outside the city.
     */
    private const MALABON_POSTAL_CODE = '1470';

    private array $eager = ['address.barangay', 'lines.psicCode', 'owners'];

    /**
     * The caller's own businesses, newest first.
     *
     * Already owner-scoped, so this is small today — but it is the one list an
     * applicant with a portfolio can grow without limit, and each row carries
     * its address and every line of business. Bounded on the same terms as the
     * rest so there is no list left that answers "all of them".
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $businesses = Business::with($this->eager)
            ->where('owner_user_id', $request->user()->id)
            /*
             * Permit holders first, then everything else newest-first.
             *
             * This list feeds the renewal and amendment choosers, which fetch
             * ONE page of 200 (PICKER_PAGE_SIZE). Ordered purely newest-first,
             * an owner past 200 businesses lost the oldest off the end — and
             * the oldest are precisely the ones whose permits are old enough to
             * need renewing. One owner on the demo register holds 239
             * businesses of which 5 have a permit: the chooser rendered 200
             * rows that could not be renewed and silently dropped the one that
             * could.
             *
             * The obvious fix — filter to businesses holding a permit — was
             * tried and is still not taken, though the reason has changed.
             *
             * It used to be that a renewal could legitimately be filed for a
             * business with no permit here, its permit having been issued on
             * paper. The client retired that case on 18 September 2026, so such
             * a business genuinely cannot be renewed now and must file a New
             * Application instead.
             *
             * Exclusion is still the wrong shape. This endpoint feeds a chooser,
             * and a business that is simply absent from it tells the applicant
             * nothing about why; one they can pick, and be told "no permit to
             * renew — file a New Application", tells them both. Filtering here
             * would move that dead end earlier and make it silent.
             *
             * So the answer remains ordering: the businesses that can be renewed
             * surface first, the rest stay reachable behind them. An EXISTS
             * rather than a join because `permits` is many-per-business and a
             * join would multiply the page.
             *
             * It was `withCount('permits')` and ORDER BY CASE WHEN
             * permits_count > 0. SQLite lets ORDER BY reach a select alias
             * inside an expression; PostgreSQL does not, so the owner's
             * business list answered 500 on the production database. The count
             * was read by nothing but this sort.
             */
            // Named in the amendment chooser, so it must arrive with the
            // list rather than a request per row. One constant query.
            ->with('currentBusinessPermit')
            ->orderByRaw('CASE WHEN EXISTS (SELECT 1 FROM permits WHERE permits.business_id = businesses.id) THEN 0 ELSE 1 END')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => BusinessResource::collection($businesses->items()),
            'meta' => $this->pageMeta($businesses),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateBusiness($request);

        $business = DB::transaction(function () use ($data, $request) {
            $business = Business::create([
                'owner_user_id' => $request->user()->id,
                'name' => $data['name'],
                'trade_name' => $data['trade_name'] ?? null,
                'registration_type' => $data['registration_type'] ?? null,
                'form_of_organization' => self::formOfOrganization($data),
                'registration_number' => $data['registration_number'] ?? null,
                'tin' => $data['tin'] ?? null,
                'is_rented' => (bool) ($data['is_rented'] ?? false),
                /*
                 * The paper's item 9 maps to this column and nothing had ever
                 * written it: `pays_rent` sat in the migration and in $fillable
                 * while every reader and writer used `is_rented`. They are one
                 * fact — "do you pay rent for occupying a place of business?" —
                 * so it is filled from the one answer rather than asked twice
                 * or left reading false on a business that plainly rents.
                 * Client's decision, 16 September 2026, over dropping it.
                 */
                'pays_rent' => (bool) ($data['is_rented'] ?? false),
                'lessor_name' => $data['lessor_name'] ?? null,
                'lessor_address' => $data['lessor_address'] ?? null,
                'lessor_contact' => $data['lessor_contact'] ?? null,
                'monthly_rental' => $data['monthly_rental'] ?? null,
                'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                'emergency_contact_number' => $data['emergency_contact_number'] ?? null,
                ...self::paperFormFields($data),
                'ban' => Numbering::ban(),
                'is_active' => true,
            ]);

            $this->syncAddressAndLines($business, $data);

            return $business;
        });

        Audit::log('business.created', $business);

        return response()->json([
            'data' => new BusinessResource($business->load($this->eager)),
        ], 201);
    }

    public function show(Request $request, Business $business): JsonResponse
    {
        $this->authorizeOwnerOrOfficer($request, $business);

        return response()->json([
            'data' => new BusinessResource($business->load($this->eager)),
        ]);
    }

    /**
     * The address and owner columns an officer can now edit, flattened.
     *
     * Dotted keys (`address.street`, `owner.surname`) so the audit entry
     * reads in the same vocabulary the request used, and so a reader of
     * the log can tell a business column from one on a related row.
     *
     * Reloaded from the database rather than read off the in-memory
     * relation: `syncAddressAndLines` replaces the address row and
     * `syncOwner` upserts the owner, so a relation loaded before the
     * write would hand back the values it was loaded with.
     *
     * @return array<string, scalar|null>
     */
    private static function relatedSnapshot(Business $business): array
    {
        $address = $business->address()->first();
        $owner = $business->owners()->where('is_primary', true)->first();

        $snapshot = [];
        foreach ([
            'house_bldg_no', 'street', 'line1', 'line2', 'block', 'lot',
            'lot_area_sqm', 'barangay_id', 'telephone', 'mobile_number',
            'email', 'website',
        ] as $column) {
            $snapshot["address.$column"] = $address?->$column;
        }
        foreach (['surname', 'given_name', 'middle_name', 'suffix', 'gender'] as $column) {
            $snapshot["owner.$column"] = $owner?->$column;
        }

        return $snapshot;
    }

    /**
     * An OFFICER corrects the filing's own answers.
     *
     * The same validation and the same writer as `update()` above, reached
     * through a different door: the applicant's rules are the rules, which
     * is the client's instruction of 30 September 2026 and the only reading
     * of it that cannot drift — a second copy of the rules agrees on the
     * day it is written and not after the next paper change.
     *
     * ── What is different, and why ──────────────────────────────────────
     *
     * It hangs off the APPLICATION rather than the business, because that
     * is what an officer is authorised against: a sanitary officer may read
     * the filings routed to CHO, not every business in the register.
     *
     * It is allowed at any status the officer can still see. A filing being
     * reviewed is the case this exists for, and the wizard's Draft-or-
     * Returned guard is about who may edit rather than about when.
     *
     * Every changed field is written to `application_corrections` with the
     * officer named. A citizen's declared answer changed by somebody else
     * has to say so on the record, or the declaration they signed stops
     * meaning anything — that is what a return gives for free and what a
     * direct edit has to buy back.
     */
    public function updateAsOfficer(Request $request, Application $application): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user !== null && $user->hasPermission('application.review'),
            403,
            'Only a reviewing officer may correct a filing.'
        );
        abort_unless(
            ApplicationVisibility::canView($user, $application),
            403,
            'This filing is not routed to your office.'
        );

        $business = $application->business;
        abort_unless($business !== null, 422, 'This filing has no business on the register.');

        $data = $this->validateBusiness($request, $business);

        /*
         * Read BEFORE the write, so the audit can say what each field was.
         * Only the columns this endpoint sets; the address and the lines are
         * recorded as a fact of change rather than value-by-value, because a
         * line table diffed into an audit line is unreadable.
         */
        $watched = [
            'name', 'trade_name', 'registration_type', 'registration_number',
            'tin', 'is_rented', 'lessor_name', 'lessor_address',
            'lessor_contact', 'monthly_rental',
            'emergency_contact_name', 'emergency_contact_number',
            'economic_organization', 'economic_organization_others',
            'president_officer_name', 'citizenship',
            'capital_participation_filipino', 'capital_investment',
            'has_tax_incentives',
        ];
        $before = collect($watched)->mapWithKeys(fn ($c) => [$c => $business->$c])->all();

        /*
         * The address and the named owner live on their own tables, and
         * the sheet now edits both — the barangay, the street, the block
         * and lot, the business's own telephone, mobile and e-mail, and
         * all five of the owner's name fields.
         *
         * Read the same way and for the same reason as the columns above:
         * an officer who can change WHO OWNS a business and leave nothing
         * on the record is the failure this entry exists to prevent.
         */
        $before += self::relatedSnapshot($business);

        DB::transaction(function () use ($business, $data) {
            $business->update([
                'name' => $data['name'],
                'trade_name' => $data['trade_name'] ?? null,
                'registration_type' => $data['registration_type'] ?? null,
                'form_of_organization' => self::formOfOrganization($data),
                'registration_number' => $data['registration_number'] ?? null,
                'tin' => $data['tin'] ?? null,
                'is_rented' => (bool) ($data['is_rented'] ?? false),
                'pays_rent' => (bool) ($data['is_rented'] ?? false),
                'lessor_name' => $data['lessor_name'] ?? null,
                'lessor_address' => $data['lessor_address'] ?? null,
                'lessor_contact' => $data['lessor_contact'] ?? null,
                'monthly_rental' => $data['monthly_rental'] ?? null,
                'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                'emergency_contact_number' => $data['emergency_contact_number'] ?? null,
                ...self::paperFormFields($data),
            ]);
            $this->syncAddressAndLines($business, $data);
        });

        $business->refresh();

        /*
         * One row per field that actually moved. A change nobody can trace
         * to a person is the thing that makes this endpoint dangerous, and
         * this is the whole of the answer to it.
         */
        $after = collect($watched)->mapWithKeys(fn ($c) => [$c => $business->$c])->all()
            + self::relatedSnapshot($business);

        $changed = [];
        foreach ($after as $field => $value) {
            /*
             * Loose, not strict. A decimal column comes back from the
             * database as the string '100.00' where it went in as the
             * number 100, and a strict comparison would report a change
             * every time an officer saved without touching the field.
             */
            if ($before[$field] == $value) {
                continue;
            }
            $changed[$field] = ['from' => $before[$field], 'to' => $value];
        }

        if ($changed !== []) {
            Audit::log('application.fields_corrected_by_officer', $application, [
                'officer_id' => $user->id,
                'department' => $user->department?->code,
                'changed' => $changed,
            ]);
        }

        return response()->json([
            'data' => new BusinessResource($business->fresh([
                'address.barangay', 'lines.psicCode', 'owners',
            ])),
            'meta' => ['changed' => array_keys($changed)],
        ]);
    }

    public function update(Request $request, Business $business): JsonResponse
    {
        $this->authorizeOwner($request, $business);
        $data = $this->validateBusiness($request, $business);

        DB::transaction(function () use ($business, $data) {
            $business->update([
                'name' => $data['name'],
                'trade_name' => $data['trade_name'] ?? null,
                'registration_type' => $data['registration_type'] ?? null,
                'form_of_organization' => self::formOfOrganization($data),
                'registration_number' => $data['registration_number'] ?? null,
                'tin' => $data['tin'] ?? null,
                'is_rented' => (bool) ($data['is_rented'] ?? false),
                /*
                 * The paper's item 9 maps to this column and nothing had ever
                 * written it: `pays_rent` sat in the migration and in $fillable
                 * while every reader and writer used `is_rented`. They are one
                 * fact — "do you pay rent for occupying a place of business?" —
                 * so it is filled from the one answer rather than asked twice
                 * or left reading false on a business that plainly rents.
                 * Client's decision, 16 September 2026, over dropping it.
                 */
                'pays_rent' => (bool) ($data['is_rented'] ?? false),
                'lessor_name' => $data['lessor_name'] ?? null,
                'lessor_address' => $data['lessor_address'] ?? null,
                'lessor_contact' => $data['lessor_contact'] ?? null,
                'monthly_rental' => $data['monthly_rental'] ?? null,
                'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                'emergency_contact_number' => $data['emergency_contact_number'] ?? null,
                ...self::paperFormFields($data),
            ]);
            $this->syncAddressAndLines($business, $data);
        });

        Audit::log('business.updated', $business);

        return response()->json([
            'data' => new BusinessResource($business->load($this->eager)),
        ]);
    }

    /**
     * Renewal/amendment prefill: business + renewable permits + last application.
     *
     * `last_permit` alone could not answer "which permit am I renewing"
     * (checklist item 85). A business commonly holds four — business, sanitary,
     * fire, occupancy — with four different expiry dates, so the latest one
     * issued is a guess, not the applicant's answer.
     *
     * The wizard used to build this list from `GET /permits`, the owner's whole
     * portfolio, and filter it in the browser. That is the wrong source twice
     * over: the endpoint is paginated, so a business's permit could sit on page
     * two and simply not be offered; and the browser cannot be where a revoked
     * permit is ruled out of being renewed. Both are decided here now.
     */
    public function prefill(Request $request, Business $business): JsonResponse
    {
        $this->authorizeOwner($request, $business);
        $request->validate(['type' => ['nullable', 'in:renewal,amendment']]);

        $business->load($this->eager);

        $lastPermit = $business->permits()
            ->with('permitType:id,code,name')
            ->orderByDesc('issued_at')
            ->first();

        /*
         * What may be renewed, soonest to expire first — the one somebody
         * opening a renewal came here about.
         *
         * Expired permits stay in: a lapsed permit is exactly what is renewed,
         * and hiding it would leave the applicant with nothing to pick. Revoked
         * and suspended ones go: a revoked permit is not renewed, it is
         * appealed, and offering it as a starting point invites a filing the
         * office has to refuse. `business` and `application` ride along because
         * PermitResource emits them and the wizard's picker reads the type name.
         */
        $renewablePermits = $business->permits()
            ->with(['permitType', 'business:id,name', 'application:id,tracking_id'])
            ->whereIn('status', [PermitStatus::Active->value, PermitStatus::Expired->value])
            ->orderByRaw('valid_until is null, valid_until asc')
            ->orderBy('id')
            ->get();

        $lastApplication = $business->applications()
            ->with('permitTypes:id')
            ->orderByDesc('created_at')
            ->first();

        /*
         * ── Section B, carried forward ───────────────────────────────────
         *
         * A renewal asks Section B and nothing else (MCG-BPLO-FO-002), and the
         * client asked for it to arrive answered. The figures live on the
         * business record too, but one of them does not survive the trip:
         * `delivery_units` is stored as the SUM of the paper's two counts, so
         * the register cannot say how many were motorised. The filing's own
         * profile can.
         *
         * SUBMITTED, not newest. `$lastApplication` above is ordered by
         * `created_at` and is used to suggest permit types, where a draft is a
         * fine source. Here it is not: the newest row is frequently the empty
         * renewal draft the applicant is creating right now, and prefilling
         * from that would hand back the blanks it is trying to fill.
         */
        $lastFiled = $business->applications()
            ->whereNotNull('submitted_at')
            ->orderByDesc('submitted_at')
            ->first();

        $suggested = $lastApplication
            ? $lastApplication->permitTypes->pluck('id')->values()
            : collect();

        return response()->json([
            'data' => [
                'business' => new BusinessResource($business),
                /*
                 * The wizard clears the gross sales out of this before using
                 * it — last year's receipts are not this year's declaration —
                 * which is a decision about the FORM and so is taken there.
                 */
                'last_fee_profile' => $lastFiled?->fee_profile,
                'last_permit' => $lastPermit ? [
                    'id' => $lastPermit->id,
                    'permit_number' => $lastPermit->permit_number,
                    'permit_type' => $lastPermit->permitType ? [
                        'code' => $lastPermit->permitType->code,
                        'name' => $lastPermit->permitType->name,
                    ] : null,
                    'valid_until' => optional($lastPermit->valid_until)->toDateString(),
                ] : null,
                // The full PermitResource shape, so the wizard's picker renders
                // permit number, type and expiry from the same fields the
                // Permits screen does rather than a second, thinner contract.
                'renewable_permits' => PermitResource::collection($renewablePermits),
                /*
                 * The ones a submitted renewal is already carrying. Still in
                 * the list above, so the picker can show them greyed out as
                 * `Renewal in progress` rather than drop a permit the owner
                 * can see on their own profile; the server refuses them
                 * either way (`RenewablePermit`).
                 */
                'renewal_in_progress_permit_ids' => RenewablePermit::inProgress($renewablePermits->pluck('id')->all()),
                'last_application' => $lastApplication ? [
                    'id' => $lastApplication->id,
                    'permit_type_ids' => $lastApplication->permitTypes->pluck('id')->values(),
                ] : null,
                'suggested_permit_type_ids' => $suggested,
            ],
        ]);
    }

    /**
     * The applicant's organisation structure, for the Form of Organization panel.
     *
     * Nothing in the application ever wrote `businesses.form_of_organization`, so
     * it was null on every real business and the panel could only be filled by the
     * seeder. The value was being collected all along — the wizard's "Type of
     * Registration" field offers exactly Sole Proprietorship, Partnership,
     * Corporation and Cooperative — it was just landing only in
     * `registration_type`. Copy it across so the two columns agree.
     *
     * Since item 94, `registration_type` is validated down to those same four,
     * so this is now a straight copy rather than a rescue. It stays defensive
     * anyway: an explicit form_of_organization wins if a caller sends one, and
     * anything unresolvable leaves the column null rather than guessing.
     *
     * @param  array<string, mixed>  $data
     */
    private static function formOfOrganization(array $data): ?string
    {
        $explicit = $data['form_of_organization'] ?? null;
        if (is_string($explicit) && in_array($explicit, self::ORGANIZATION_FORMS, true)) {
            return $explicit;
        }

        $registrationType = $data['registration_type'] ?? null;

        return Business::normalizeRegistrationType(is_string($registrationType) ? $registrationType : null);
    }

    /**
     * The BPLO paper-form answers that hang off the business record.
     *
     * Written on both create and update, from a payload that is allowed to omit
     * any of them — an omitted key means null here, which is the honest reading
     * for every one of these. In particular the wizard omits
     * `president_officer_name`, `citizenship` and `capital_participation_filipino`
     * for a sole proprietorship, because on the paper those three describe the
     * President/OIC (item A14 says so outright) and a sole proprietorship has
     * none. Nulling them rather than preserving them is deliberate: a business
     * that files as a corporation and later corrects itself to a sole
     * proprietorship must not keep asserting it has an officer in charge.
     *
     * `economic_organization_others` is cleared unless "others" was chosen, so
     * a specify-blank cannot outlive the answer it belonged to.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function paperFormFields(array $data): array
    {
        $economic = $data['economic_organization'] ?? null;

        return [
            'economic_organization' => $economic,
            'economic_organization_others' => $economic === 'others'
                ? ($data['economic_organization_others'] ?? null)
                : null,
            'president_officer_name' => $data['president_officer_name'] ?? null,
            'citizenship' => $data['citizenship'] ?? null,
            'capital_participation_filipino' => $data['capital_participation_filipino'] ?? null,
            'capital_investment' => $data['capital_investment'] ?? null,
            'has_tax_incentives' => (bool) ($data['has_tax_incentives'] ?? false),
        ];
    }

    /**
     * @param  Business|null  $business  the row being edited, excluded from the
     *                                   duplicate-certificate check so an
     *                                   update does not clash with itself
     */
    private function validateBusiness(Request $request, ?Business $business = null): array
    {
        /*
         * Item 94 — `registration_type` is the organisation STRUCTURE, and the
         * registering agency is derived from it, not stored beside it.
         *
         * The column used to hold either vocabulary. Older clients (and this
         * repo's own seeders) send the agency code, so translate the two that
         * translate: DTI only ever registers sole proprietors and CDA only ever
         * registers cooperatives, so nothing is lost.
         *
         * "SEC" does NOT translate — it registers partnerships and corporations
         * alike — so it is left untouched and falls through to the `in` rule
         * below, where the applicant is asked which of the two they are. That is
         * the whole point of asking for the structure first: a bare agency name
         * is not an answer.
         */
        if (is_scalar($request->input('registration_type')) && filled($request->input('registration_type'))) {
            $structure = Business::normalizeRegistrationType((string) $request->input('registration_type'));
            if ($structure !== null) {
                $request->merge(['registration_type' => $structure]);
            }
        }

        /*
         * Normalise the TIN before the rule runs, so applicants may type the
         * separators they are used to (123 456 789 000, 123.456.789, plain
         * digits) and we still store one canonical form.
         *
         * Only when it is a scalar. `(string) $request->input('tin')` on a
         * `tin[]=x` body is a TypeError against normalizeTin's string parameter,
         * so posting an array here answered 500 — a crash in the pre-validation
         * tidy-up, before the rule that would have said "enter a valid TIN" ever
         * ran. Left alone, the `string` rule below rejects it with a 422.
         */
        if (is_scalar($request->input('tin')) && filled($request->input('tin'))) {
            $request->merge(['tin' => Tin::normalize((string) $request->input('tin'))]);
        }

        /*
         * The line-of-business rule, resolved per line before the rest run.
         *
         * Built by index rather than written as `lines.*.line_of_business`
         * because the requirement depends on the SIBLING field: a line filed
         * under the catch-all 00000 must be described, and a line carrying a
         * real PSIC code need not be. A wildcard entry cannot ask that
         * question, and the obvious closure beside `nullable` never runs —
         * `nullable` tells the validator to skip every remaining rule the
         * moment the value is null, which is precisely the case to catch.
         *
         * So each index gets its own entry and the wildcard is gone. Two
         * entries for one attribute would merge, and a merged `nullable` would
         * cancel the `required` beside it.
         */
        $lineRules = [];

        foreach (is_array($request->input('lines')) ? $request->input('lines') : [] as $i => $line) {
            $psicCodeId = is_array($line) ? ($line['psic_code_id'] ?? null) : null;

            $lineRules["lines.{$i}.line_of_business"] = [
                self::isUnclassifiedPsic($psicCodeId) ? 'required' : 'nullable',
                'string',
                'max:255',
            ];
        }

        $data = $request->validate($lineRules + [
            'name' => ['required', 'string', 'max:255'],
            // Trade name stays optional: most sole proprietors have none.
            'trade_name' => ['nullable', 'string', 'max:255'],
            'registration_type' => ['required', 'string', Rule::in(self::ORGANIZATION_FORMS)],
            'form_of_organization' => ['nullable', 'string', Rule::in(self::ORGANIZATION_FORMS)],
            /*
             * Registration number — one field, three issuers, and no published
             * format for ANY of them. This rule is deliberately loose, and the
             * looseness is evidence-based rather than lazy:
             *
             * - SEC. Its own published registers (List of Lending Companies and
             *   List of Financing Companies, 31 May 2020, ~3,800 rows between
             *   them) contain more than twenty distinct shapes: CS + 8, 9, 10 or
             *   11 digits, A/B/C/D/E/G/H + 9, AS + 8 or 9, ASO/ESO + 9,
             *   CEO/IEO/BEO/DEO + 7, and bare numerics from 4 to 10 digits.
             *   Several carry trailing letters (CS200729932-A, AS9308113A) and
             *   embedded hyphens (ASO91-195123, AS094-000088). Zero-padding of
             *   the year segment is inconsistent within the same prefix and era
             *   (AS094007741 beside AS94006474).
             * - CDA. Its current masterlist (December 2024, ~21,000 rows) runs
             *   THREE formats concurrently — "9520-" plus 8, 12 or 16 digits —
             *   with stragglers at 6, 10, 11, 13, 14 and 15, one row with a
             *   double hyphen, and a separate "10744-" prefix for Credit Surety
             *   Fund cooperatives. Applicants may also quote their Cooperative
             *   Identification Number instead, which is a different 10-digit
             *   number entirely.
             * - DTI. Publishes no format at all. The Citizen's Charter and the
             *   BNRS FAQ both refer to a "reference number" without specifying
             *   length or charset, and DTI's own application form has applicants
             *   quote either the Certificate No. or the Reference Code. No
             *   authoritative specimen could be verified, so nothing about DTI's
             *   number space is asserted here.
             *
             * A regex per agency would therefore refuse certificates real
             * businesses are holding, and a refused applicant cannot file at
             * all, while a malformed number is caught by the officer who opens
             * the uploaded certificate. What adapts to the chosen structure is
             * the label, the example and the error text — see
             * registrationNumberMessage() — not what is accepted.
             *
             * So this only asserts the value LOOKS like a reference rather than
             * a sentence: the characters these numbers are printed with, and at
             * least one digit (every specimen in every register above contains
             * one). `min:4` is the shortest real reference observed anywhere in
             * those registers — SEC "1074" — so it has no margin below it, and
             * it is what stops "111" and "Test" from passing.
             */
            /*
             * ── Whose certificate is it? ─────────────────────────────────────
             *
             * NOT `unique:businesses,registration_number`, and the difference
             * is a real filing that rule would refuse.
             *
             * DTI registers a business NAME, so its numbers are effectively one
             * per business. SEC and CDA register an ENTITY — a corporation or a
             * cooperative — and one entity lawfully operates several
             * establishments. BizTrack records one premises per business (it is
             * why the zoning sheet's items IV and VI are the same address), so
             * a corporation with a main store and a branch is TWO business rows
             * citing ONE SEC number. A global unique index tells that applicant
             * their own certificate is taken, and leaves them no way past it.
             *
             * What is never legitimate is the same certificate under two
             * different owners: that is a typo, or somebody filing against a
             * company that is not theirs. So the scope is the OWNER, not the
             * row — the client's decision of 16 September 2026, over both a
             * global unique and a per-agency split.
             *
             * The same-owner case is allowed here and merely NOTED on the
             * wizard (see `registrationNumberOwnedElsewhere` in ApplyWizard),
             * so an applicant adding a second branch is not stopped, and an
             * applicant who meant to renew is told they already hold one.
             */
            'registration_number' => [
                'required', 'string', 'min:4', 'max:100',
                'regex:/^(?=.*\d)[A-Za-z0-9][A-Za-z0-9 .\-\/]*$/',
                function (string $attribute, mixed $value, Closure $fail) use ($request, $business) {
                    $key = Business::normalizeRegistrationNumber(is_scalar($value) ? (string) $value : '');
                    if ($key === '') {
                        return;
                    }

                    /*
                     * Compared in PHP rather than in SQL. The normalisation
                     * strips every non-alphanumeric character, and SQLite has no
                     * regex function to do that in a WHERE clause — a LIKE
                     * approximation would be the bypassable comparison this
                     * exists to prevent. The register is small enough that the
                     * honest version costs nothing, and it is scoped to rows
                     * belonging to OTHER owners.
                     */
                    $clash = Business::query()
                        ->whereNotNull('registration_number')
                        ->where('owner_user_id', '!=', $request->user()->id)
                        ->when($business !== null, fn ($q) => $q->whereKeyNot($business->id))
                        ->get(['id', 'registration_number'])
                        ->first(fn (Business $other) => Business::normalizeRegistrationNumber(
                            $other->registration_number,
                        ) === $key);

                    if ($clash !== null) {
                        /*
                         * The other business is NOT named. Its name and owner
                         * belong to somebody else, and echoing them back would
                         * turn this field into a lookup for whether a given
                         * certificate is registered in Malabon and to whom.
                         * BPLO can see both rows; the applicant gets the fact
                         * and the counter as the way to resolve it.
                         */
                        $fail(
                            'This registration number is already on file for another account. '
                            .'Check the number against your certificate — if it is correct, '
                            .'contact BPLO so they can sort out which record it belongs to.'
                        );
                    }
                },
            ],
            /*
             * Philippine TIN: 9 digits, plus a 3 to 5 digit branch code where
             * the taxpayer has one. Normalised above into hyphenated groups.
             *
             * `nullable` since 24 September 2026, on BPLO's instruction —
             * an applicant without their TIN to hand is asked for it under
             * Other Requirements rather than being stopped at the first step
             * of the form. `nullable` rather than `sometimes` because the
             * wizard sends the key on every autosave and an empty string
             * reaches here as null, ConvertEmptyStringsToNull having already
             * run; `sometimes` would hand the blank to the regex and fail it.
             *
             * The FORMAT rule is untouched. Optional means an applicant may
             * decline to give the number, not that a mistyped one becomes
             * acceptable — this row is what BPLO reads the TIN off months
             * later, and there is nothing to check it against by then.
             */
            'tin' => ['nullable', 'string', 'max:20', 'regex:/^\d{3}-\d{3}-\d{3}(-\d{3,5})?$/'],
            'address' => ['required', 'array'],
            /*
             * Not 'required' any more: `line1` is COMPOSED from item 5's two
             * boxes in syncAddressAndLines, so a wizard that sends the parts
             * sends no line1 at all. Still accepted, because an importer or a
             * draft saved before the split has nothing else to offer.
             */
            'address.line1' => ['nullable', 'string', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            /*
             * BPLO item 5's two boxes. `line1` becomes a composed value rather
             * than a typed one — see syncAddressAndLines — so it is nullable
             * now; these two carry the answer.
             *
             * The House/Bldg. No. is nullable on purpose. The paper prints a
             * line for it, but premises exist with no number of their own: a
             * stall inside a public market, a unit identified only by the
             * building's name. Refusing to file without one would invent a
             * requirement the paper does not make.
             */
            'address.house_bldg_no' => ['nullable', 'string', 'max:120'],
            'address.street' => ['sometimes', 'required', 'string', 'max:255'],
            /*
             * Block, Lot and lot area (client, 23 September 2026). Optional:
             * a market stall or a unit on a numbered street has no block or
             * lot. The area is the LOT, not the floor the fee engine assesses
             * (`fee_profile.floor_area_sqm`) — see the migration.
             */
            'address.block' => ['nullable', 'string', 'max:40'],
            'address.lot' => ['nullable', 'string', 'max:40'],
            'address.lot_area_sqm' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'address.barangay_id' => ['required', 'exists:barangays,id'],
            'address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            /*
             * BPLO items A5, A6 and A9. All three columns have existed since the
             * schema was aligned to the paper form and none was ever written,
             * because nothing asked for them.
             *
             * `postal_code` is accepted but the wizard never sends it: Malabon is
             * 1470 and the map pin is already refused outside the city, so
             * syncAddressAndLines defaults it rather than asking a question with
             * one possible answer. The rule stays so an importer or a future
             * out-of-city case has somewhere to put a real one.
             */
            'address.postal_code' => ['nullable', 'string', 'max:20'],
            'address.telephone' => ['nullable', 'string', 'max:40'],
            'address.website' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.psic_code_id' => ['required', 'exists:psic_codes,id'],
            'lines.*.capitalization' => ['nullable', 'numeric', 'min:0'],
            /*
             * Free text for the "Other (not listed)" PSIC row, and optional
             * detail for any line — REQUIRED on the catch-all.
             *
             * It was nullable everywhere, and the rule that a catch-all line
             * must be described lived in one screen: ApplyWizard's `needsText`
             * refuses to continue without it. ReferenceSeeder says the same
             * thing about the 00000 row — *"the escape hatch: picking it makes
             * the wizard require a free-text line"* — which is a rule about the
             * data wearing the name of the one door that happened to enforce
             * it.
             *
             * Any other caller filed a business whose only description was
             * "Other (not listed)", and that is what the certificate then
             * printed: *"pwede ba yon? make sure na meron kung ano nilagay nya
             * o ininput"* [client, 1 October 2026]. 00000 carries no
             * classification at all — PsicTaxonomy::group returns null for it —
             * so the typed line is the only thing that says what the business
             * does, to the certificate, the inspector and the reviewer alike.
             */
            // Rule built per line, above — see $lineRules.
            'lines.*.products_services' => ['nullable', 'string', 'max:1000'],
            /*
             * Unified form, lessor block. Only required once the applicant says
             * the premises are rented; an owner-occupied shop has no lessor and
             * must not be asked to invent one.
             */
            'is_rented' => ['sometimes', 'boolean'],
            /*
             * No longer `required_if:is_rented`. MCG-BPLO-FO-001 asks whether
             * rent is paid (item 9) and nothing about the lessor; the four
             * lessor fields came from the national BPLS unified form and were
             * removed from the wizard on 16 September 2026.
             *
             * The columns stay, and stay writable: MCG-CPDD-FO-003 items VIII.C
             * and VIII.D do ask for the lessor's name and address, and the
             * zoning sheet collects them. Requiring them HERE would refuse a
             * BPLO filing for want of an answer no BPLO screen asks for.
             */
            'lessor_name' => ['nullable', 'string', 'max:255'],
            'lessor_address' => ['nullable', 'string', 'max:255'],
            'lessor_contact' => ['nullable', 'string', 'max:40'],
            // Same: on no paper form BizTrack holds, and no fee rule reads it.
            'monthly_rental' => ['nullable', 'numeric', 'min:0'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_number' => ['nullable', 'string', 'max:40'],
            /*
             * BPLO item B6. Banded to the six the paper prints, so a caller
             * cannot invent a seventh economic organization the officer's sheet
             * would then have to render as a raw slug.
             */
            'economic_organization' => ['nullable', 'string', Rule::in(Business::ECONOMIC_ORGANIZATIONS)],
            'economic_organization_others' => ['nullable', 'string', 'max:255'],
            /*
             * BPLO items A13, A14, A15. Optional at this layer even though the
             * wizard only offers them to a partnership, corporation or
             * cooperative: the gate is a question of what to ASK, and the browser
             * is not the only way into this endpoint. A sole proprietorship that
             * genuinely wants to name an officer in charge is not refused here,
             * it is simply never asked.
             */
            'president_officer_name' => ['nullable', 'string', 'max:255'],
            'citizenship' => ['nullable', 'string', 'max:100'],
            // A percentage, not an amount: decimal(5,2) holds 0.00 to 100.00.
            'address.mobile_number' => ['nullable', 'string', 'max:40'],
            'address.email' => ['nullable', 'email', 'max:255'],
            'capital_participation_filipino' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // BPLO item B7 — one figure for the whole business. The per-line
            // capitalization the fee engine reads lives on the fee profile and
            // is a different question; see the note in ApplyWizard.
            'capital_investment' => ['nullable', 'numeric', 'min:0', 'max:10000000000'],
            /*
             * BPLO items 11 and 12 — the named person on the form.
             *
             * Every field is nullable. None of the three paper forms marks any
             * field required, and the wizard prefills these from the signed-in
             * account, so a blank here means the applicant cleared a prefill on
             * purpose. Refusing that would be our rule, not the city's.
             *
             * `gender` is bounded to the two the paper prints (M / F boxes).
             */
            'owner' => ['sometimes', 'array'],
            'owner.surname' => ['nullable', 'string', 'max:100'],
            'owner.given_name' => ['nullable', 'string', 'max:100'],
            'owner.middle_name' => ['nullable', 'string', 'max:100'],
            'owner.suffix' => ['nullable', 'string', 'max:20'],
            'owner.gender' => ['nullable', 'in:M,F'],
            // BPLO item B8 (new form) / B7 (renewal). Not derivable from the
            // `is_bmbe` / `is_cooperative` fee-profile flags — see the Business
            // model — so it is asked and stored on its own.
            'has_tax_incentives' => ['sometimes', 'boolean'],
        ], [
            'lessor_name.required_if' => "Enter the lessor's name, or set the premises to owner-occupied.",
            'lessor_address.required_if' => "Enter the lessor's address, or set the premises to owner-occupied.",
            'monthly_rental.required_if' => 'Enter the monthly rental, or set the premises to owner-occupied.',
            /*
             * Matched by the wildcard even though the rules are built per
             * index: Laravel falls back to the `*` form when looking up a
             * message for `lines.0.line_of_business.required`.
             */
            'lines.*.line_of_business.required' => 'Describe this line of business in your own words. "Other (not listed)" carries no classification of its own, so what you type here is the only thing that says what the business does.',
            'lines.required' => 'Add at least one line of business.',
            'lines.min' => 'Add at least one line of business.',
            'address.required' => 'A business address is required.',
            'registration_type.required' => 'Choose your type of registration.',
            'registration_type.in' => self::registrationTypeMessage($request),
            'registration_number.required' => self::registrationNumberMessage($request, 'required'),
            'registration_number.min' => self::registrationNumberMessage($request, 'format'),
            'registration_number.regex' => self::registrationNumberMessage($request, 'format'),
            'tin.required' => 'Enter your Tax Identification Number.',
            'tin.regex' => 'Enter a valid TIN: 9 digits, plus a branch code if you have one, like 123-456-789-000.',
        ]);

        self::assertPinInBarangay($data, $business);

        return $data;
    }

    /**
     * Checklist Zoning 3 — a pin outside its own barangay is refused here, not
     * only in the browser. See MalabonGeo for the geometry and the tolerance.
     *
     * Checked when the location is NEW: every create, and an update that moves
     * the pin or changes the barangay. An update that sends back the location
     * already on file is let through untouched, because most of the register
     * predates this check — only 61 of 788 stored addresses sat inside their
     * own barangay when it was measured — and a renewal re-saves that address
     * as it found it. Refusing it would charge our history to an applicant who
     * never placed the pin; the wizard says it out loud instead ("Check this
     * location"), and CPDO sees it. The moment the applicant touches either the
     * pin or the barangay, the new answer has to agree with itself.
     *
     * The error sits on both coordinates, because either the pin or the
     * barangay can be the wrong half, and the pin is what the message asks to
     * move first.
     */
    private static function assertPinInBarangay(array $data, ?Business $business): void
    {
        $lat = $data['address']['latitude'] ?? null;
        $lng = $data['address']['longitude'] ?? null;
        $barangayId = $data['address']['barangay_id'] ?? null;
        if ($lat === null || $lng === null || $barangayId === null) {
            return;
        }

        $stored = $business?->address;
        if ($stored !== null
            && (int) $stored->barangay_id === (int) $barangayId
            && $stored->latitude !== null && $stored->longitude !== null
            && abs((float) $stored->latitude - (float) $lat) < 1e-6
            && abs((float) $stored->longitude - (float) $lng) < 1e-6) {
            return;
        }

        $name = Barangay::whereKey($barangayId)->value('name');
        $problem = $name === null ? null : MalabonGeo::pinProblem((float) $lat, (float) $lng, (string) $name);
        if ($problem !== null) {
            throw ValidationException::withMessages([
                'address.latitude' => $problem,
                'address.longitude' => $problem,
            ]);
        }
    }

    /**
     * Why an unrecognised `registration_type` was refused.
     *
     * The one worth spelling out is "SEC". A client sending it is not sending
     * nonsense — it is sending the old vocabulary, where the column named the
     * agency. But the SEC registers partnerships AND corporations, so "SEC" does
     * not say which, and there is nothing else in the request that could. Say so
     * plainly instead of listing four values the caller has to map themselves.
     */
    private static function registrationTypeMessage(Request $request): string
    {
        $raw = $request->input('registration_type');
        $raw = is_scalar($raw) ? strtoupper(trim((string) $raw)) : '';

        if ($raw === 'SEC') {
            return 'The SEC registers both partnerships and corporations, so "SEC" does not say which yours is. Choose Partnership or Corporation.';
        }

        return 'Choose your type of registration: sole proprietorship, partnership, corporation, or cooperative.';
    }

    /**
     * The registration-number error, worded for the agency the chosen structure
     * is registered with (item 94).
     *
     * The field is one input, but it is asking three different questions
     * depending on what the applicant just said they are, so "enter your DTI,
     * SEC, or CDA registration number" was three-quarters noise. Once the
     * structure is known the agency is known — Business::REGISTRAR_BY_FORM — so
     * name only that one, and give an example from it.
     *
     * The examples are illustrative, not enforced. See the rules above for why
     * the format itself is left loose.
     */
    private static function registrationNumberMessage(Request $request, string $kind): string
    {
        $agency = Business::registrarFor(
            is_scalar($request->input('registration_type')) ? (string) $request->input('registration_type') : null
        );

        $noun = match ($agency) {
            'DTI' => 'DTI Business Name registration number',
            'SEC' => 'SEC registration number',
            'CDA' => 'CDA registration number',
            default => 'DTI, SEC, or CDA registration number',
        };

        if ($kind === 'required') {
            return 'Enter your '.$noun.'.';
        }

        /*
         * SEC and CDA examples are real shapes taken from those agencies' own
         * published registers. There is deliberately no DTI example: DTI
         * publishes no format and no specimen could be verified from an
         * authoritative source, so inventing one would teach applicants a shape
         * we cannot stand behind.
         */
        $example = match ($agency) {
            'SEC' => ' It looks like CS201912345.',
            'CDA' => ' It looks like 9520-15005879.',
            default => '',
        };

        return 'Enter your '.$noun.' as it is printed on the certificate — letters, numbers, spaces and dashes only.'.$example;
    }

    /**
     * Digits-and-separators in, canonical "123-456-789[-000]" out. Anything
     * that is not a recognisable TIN comes back untouched so the regex rule
     * reports it instead of us silently mangling it.
     */
    /*
     * Moved to App\Support\Tin on 27 September 2026.
     *
     * A second route now accepts a typed TIN — the automatic requirement
     * raised when item 3 is left blank, answered in a requirement reply
     * and written back by OfficerRequestController. Two callers, so the
     * shaping stopped being this controller's private business.
     */

    /**
     * The named person on the paper — BPLO item 11 / item 12.
     *
     * Writes the PRIMARY owner row and only when the caller sent an `owner`
     * key. An absent key means "this request is not about the owner" (a
     * fee-profile autosave, say) and must leave the row alone; an explicit
     * blank inside a present key means the applicant cleared a prefilled field,
     * and that is stored as the null it is.
     *
     * `updateOrCreate` on `is_primary` rather than a plain create: the wizard
     * saves the same business repeatedly as the applicant types, and a create
     * would leave one owner row per keystroke.
     */
    private function syncOwner(Business $business, array $data): void
    {
        if (! array_key_exists('owner', $data) || ! is_array($data['owner'])) {
            return;
        }

        $owner = $data['owner'];
        $clean = fn (string $key) => filled($owner[$key] ?? null) ? trim((string) $owner[$key]) : null;

        BusinessOwner::updateOrCreate(
            ['business_id' => $business->id, 'is_primary' => true],
            [
                'surname' => $clean('surname'),
                'given_name' => $clean('given_name'),
                'middle_name' => $clean('middle_name'),
                'suffix' => $clean('suffix'),
                'gender' => $clean('gender'),
            ],
        );
    }

    private function syncAddressAndLines(Business $business, array $data): void
    {
        /*
         * BPLO item 5 asks for the House/Bldg. No. and the Street in two
         * separate boxes, and `business_addresses` has carried
         * `house_bldg_no` and `street` since the schema was aligned to the
         * paper — both empty on every row, because the wizard asked one
         * combined "House No. & Street Name" question and put the answer in
         * `line1`.
         *
         * That cost more than tidiness. The officer's review page had to GUESS
         * the split back out with a regex (`splitLine1`, "24 Mabini Street" →
         * 24 / Mabini Street), and filings exist whose `line1` is just "17" —
         * the applicant read the label as asking for the number. The regex
         * cannot parse that, so BPLO was shown Street "17" and House "—", the
         * two answers exactly reversed.
         *
         * So the two boxes are asked for and stored separately now, and
         * `line1` is COMPOSED from them rather than typed. Everything that
         * reads an address as one line — the office sheets, the permit PDFs,
         * the officer list — keeps working untouched, and nothing has to guess.
         */
        $house = trim((string) ($data['address']['house_bldg_no'] ?? ''));
        $street = trim((string) ($data['address']['street'] ?? ''));
        $composed = trim($house.' '.$street);

        $address = $business->address()->updateOrCreate([], [
            /*
             * The composed line wins only when the parts were sent. A caller
             * that still sends `line1` alone — an importer, or a draft saved
             * before the split — keeps its own value rather than having it
             * blanked by two empty parts.
             */
            'line1' => $composed !== '' ? $composed : ($data['address']['line1'] ?? null),
            'line2' => $data['address']['line2'] ?? null,
            'barangay_id' => $data['address']['barangay_id'],
            'latitude' => $data['address']['latitude'] ?? null,
            'longitude' => $data['address']['longitude'] ?? null,
            /*
             * BPLO item A5's Postal Code, defaulted rather than asked.
             *
             * Every location this system will license is inside Malabon — the
             * wizard refuses a map pin outside the city bounds before it will
             * save one — and Malabon has exactly one postal code. So the answer
             * is known before the question could be put, and the column is
             * filled the same way the schema already fills `city` and
             * `province`. A caller that sends a real one wins; nothing here
             * overwrites an answer with the default.
             */
            'postal_code' => $data['address']['postal_code'] ?? $business->address?->postal_code ?? self::MALABON_POSTAL_CODE,
        ]);

        /*
         * Items A6 and A9. Set explicitly rather than through the array above
         * because neither is mass assignable on BusinessAddress — the same
         * treatment `line_of_business` and `products_services` get on
         * BusinessLine below, and for the same reason.
         *
         * Absent means null, not unchanged. Unlike a line's capitalization
         * (which the wizard genuinely no longer states, hence the preserve dance
         * further down), these two ARE stated on every save the wizard makes, so
         * an omitted key really is the applicant having cleared the field.
         */
        /*
         * Item 5's two boxes, set explicitly for the same reason as A6 and A9
         * below: neither is mass assignable on BusinessAddress.
         *
         * Only written when the caller sent them. A payload that predates the
         * split has no opinion about these columns, and writing null would
         * replace a real answer with a blank on the next autosave.
         */
        if (array_key_exists('house_bldg_no', $data['address'])) {
            $address->house_bldg_no = $house !== '' ? $house : null;
        }
        if (array_key_exists('street', $data['address'])) {
            $address->street = $street !== '' ? $street : null;
        }
        // Same only-when-sent rule, so a caller that predates these three
        // cannot blank them on its next save.
        foreach (['block', 'lot'] as $part) {
            if (array_key_exists($part, $data['address'])) {
                $value = trim((string) ($data['address'][$part] ?? ''));
                $address->{$part} = $value !== '' ? $value : null;
            }
        }
        if (array_key_exists('lot_area_sqm', $data['address'])) {
            $address->lot_area_sqm = filled($data['address']['lot_area_sqm'])
                ? (float) $data['address']['lot_area_sqm']
                : null;
        }
        $address->telephone = filled($data['address']['telephone'] ?? null)
            ? trim($data['address']['telephone'])
            : null;
        $address->website = filled($data['address']['website'] ?? null)
            ? trim($data['address']['website'])
            : null;
        /*
         * Items A7 and A8 — the BUSINESS's mobile number and e-mail.
         *
         * Both columns have been on `business_addresses` and empty on every row,
         * because nothing collected them: the officer's sheet showed the account
         * holder's details instead, which is a different fact. A corporation's
         * contact number is not whoever happens to hold the login.
         *
         * The wizard prefills them from the signed-in account, so most filings
         * will carry the same values — but they are stored HERE, on the business,
         * so editing one never edits the other.
         */
        $address->mobile_number = filled($data['address']['mobile_number'] ?? null)
            ? trim($data['address']['mobile_number'])
            : null;
        $address->email = filled($data['address']['email'] ?? null)
            ? trim($data['address']['email'])
            : null;
        $address->save();

        $this->syncOwner($business, $data);

        /*
         * The declared capital per line, as it stands before this write.
         *
         * The lines are replaced wholesale below, so anything the payload does
         * not restate is destroyed — and the wizard no longer states the
         * capital, because it is asked once on Business & Tax Profile and
         * arrives on the application's fee profile instead
         * (ApplicationController::syncLineCapitalization). Without this, the
         * autosave that saves the business a moment before the fee profile
         * would blank the figure the previous autosave had just landed, and the
         * two would take turns undoing each other for the life of the draft.
         *
         * So: a `capitalization` that is absent or null means "unchanged", and
         * only a number sent explicitly overwrites what is on record. A client
         * cannot clear the figure by omitting it, which is the right trade —
         * 785 of 790 rows carry one, and losing it is a far worse failure than
         * being unable to blank it through this endpoint.
         */
        $existingCapital = $business->lines()
            ->pluck('capitalization', 'psic_code_id');

        $business->lines()->delete();
        foreach ($data['lines'] as $line) {
            $row = $business->lines()->make([
                'psic_code_id' => $line['psic_code_id'],
                'capitalization' => $line['capitalization']
                    ?? $existingCapital[$line['psic_code_id']]
                    ?? null,
            ]);
            // Free text for the "Other (not listed)" PSIC row; not mass
            // assignable on BusinessLine, so set it explicitly.
            $row->line_of_business = filled($line['line_of_business'] ?? null)
                ? trim($line['line_of_business'])
                : null;
            $row->products_services = filled($line['products_services'] ?? null)
                ? trim($line['products_services'])
                : null;
            $row->save();
        }
    }

    /**
     * Is this PSIC selection the catch-all 00000 "Other (not listed)" row?
     *
     * By CODE rather than by id, because the id is whatever the reference
     * seeder happened to insert and differs between a fresh database and a
     * migrated one. The code is the thing the PSA publishes and the thing the
     * rest of the system matches on — see `PsicTaxonomy::group`, which returns
     * null for exactly this row.
     *
     * False for an id that does not resolve: the `exists` rule beside this one
     * is what answers an unknown code, and two rules firing on one mistake
     * tells the applicant their trade is both missing and unknown.
     */
    private static function isUnclassifiedPsic(mixed $psicCodeId): bool
    {
        if (! is_scalar($psicCodeId)) {
            return false;
        }

        return PsicCode::whereKey($psicCodeId)->value('code') === PsicCode::UNCLASSIFIED;
    }

    private function authorizeOwner(Request $request, Business $business): void
    {
        abort_unless($business->owner_user_id === $request->user()->id, 403, 'This business is not yours to manage.');
    }

    /**
     * Owner, or an officer who may read at least one of this business's
     * filings. The officer only ever reaches this route from an application
     * they already have open, so the office boundary that governs the
     * application governs the registry record behind it (checklist item 56).
     */
    private function authorizeOwnerOrOfficer(Request $request, Business $business): void
    {
        $user = $request->user();
        if ($business->owner_user_id === $user->id) {
            return;
        }
        if (ApplicationVisibility::readsEveryOffice($user)) {
            return;
        }

        $visible = $business->applications()
            ->tap(fn ($q) => ApplicationVisibility::scope($q, $user))
            ->exists();

        abort_unless($visible, 403, 'You may not view this business.');
    }
}
