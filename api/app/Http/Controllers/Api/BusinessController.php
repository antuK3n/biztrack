<?php

namespace App\Http\Controllers\Api;

use App\Enums\PermitStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Http\Resources\PermitResource;
use App\Models\Business;
use App\Support\ApplicationVisibility;
use App\Support\Audit;
use App\Support\Numbering;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

    private array $eager = ['address.barangay', 'lines.psicCode'];

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
                'lessor_name' => $data['lessor_name'] ?? null,
                'lessor_address' => $data['lessor_address'] ?? null,
                'lessor_contact' => $data['lessor_contact'] ?? null,
                'monthly_rental' => $data['monthly_rental'] ?? null,
                'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                'emergency_contact_number' => $data['emergency_contact_number'] ?? null,
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

    public function update(Request $request, Business $business): JsonResponse
    {
        $this->authorizeOwner($request, $business);
        $data = $this->validateBusiness($request);

        DB::transaction(function () use ($business, $data) {
            $business->update([
                'name' => $data['name'],
                'trade_name' => $data['trade_name'] ?? null,
                'registration_type' => $data['registration_type'] ?? null,
                'form_of_organization' => self::formOfOrganization($data),
                'registration_number' => $data['registration_number'] ?? null,
                'tin' => $data['tin'] ?? null,
                'is_rented' => (bool) ($data['is_rented'] ?? false),
                'lessor_name' => $data['lessor_name'] ?? null,
                'lessor_address' => $data['lessor_address'] ?? null,
                'lessor_contact' => $data['lessor_contact'] ?? null,
                'monthly_rental' => $data['monthly_rental'] ?? null,
                'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                'emergency_contact_number' => $data['emergency_contact_number'] ?? null,
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

        $suggested = $lastApplication
            ? $lastApplication->permitTypes->pluck('id')->values()
            : collect();

        return response()->json([
            'data' => [
                'business' => new BusinessResource($business),
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

    private function validateBusiness(Request $request): array
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
            $request->merge(['tin' => self::normalizeTin((string) $request->input('tin'))]);
        }

        return $request->validate([
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
            'registration_number' => ['required', 'string', 'min:4', 'max:100', 'regex:/^(?=.*\d)[A-Za-z0-9][A-Za-z0-9 .\-\/]*$/'],
            // Philippine TIN: 9 digits, plus a 3 to 5 digit branch code where
            // the taxpayer has one. Normalised above into hyphenated groups.
            'tin' => ['required', 'string', 'max:20', 'regex:/^\d{3}-\d{3}-\d{3}(-\d{3,5})?$/'],
            'address' => ['required', 'array'],
            'address.line1' => ['required', 'string', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            'address.barangay_id' => ['required', 'exists:barangays,id'],
            'address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.psic_code_id' => ['required', 'exists:psic_codes,id'],
            'lines.*.capitalization' => ['nullable', 'numeric', 'min:0'],
            // Free text for the "Other (not listed)" PSIC row, and optional
            // detail for any line.
            'lines.*.line_of_business' => ['nullable', 'string', 'max:255'],
            'lines.*.products_services' => ['nullable', 'string', 'max:1000'],
            /*
             * Unified form, lessor block. Only required once the applicant says
             * the premises are rented; an owner-occupied shop has no lessor and
             * must not be asked to invent one.
             */
            'is_rented' => ['sometimes', 'boolean'],
            'lessor_name' => ['nullable', 'required_if:is_rented,true', 'string', 'max:255'],
            'lessor_address' => ['nullable', 'required_if:is_rented,true', 'string', 'max:255'],
            'lessor_contact' => ['nullable', 'string', 'max:40'],
            'monthly_rental' => ['nullable', 'required_if:is_rented,true', 'numeric', 'min:0'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_number' => ['nullable', 'string', 'max:40'],
        ], [
            'lessor_name.required_if' => "Enter the lessor's name, or set the premises to owner-occupied.",
            'lessor_address.required_if' => "Enter the lessor's address, or set the premises to owner-occupied.",
            'monthly_rental.required_if' => 'Enter the monthly rental, or set the premises to owner-occupied.',
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
    private static function normalizeTin(string $raw): string
    {
        $trimmed = trim($raw);
        if (! preg_match('/^[\d\s.\-]+$/', $trimmed)) {
            return $trimmed;
        }
        $digits = preg_replace('/\D/', '', $trimmed);
        $length = strlen($digits);
        if ($length !== 9 && ($length < 12 || $length > 14)) {
            return $trimmed;
        }
        $tin = substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6, 3);

        return $length > 9 ? $tin.'-'.substr($digits, 9) : $tin;
    }

    private function syncAddressAndLines(Business $business, array $data): void
    {
        $business->address()->updateOrCreate([], [
            'line1' => $data['address']['line1'],
            'line2' => $data['address']['line2'] ?? null,
            'barangay_id' => $data['address']['barangay_id'],
            'latitude' => $data['address']['latitude'] ?? null,
            'longitude' => $data['address']['longitude'] ?? null,
        ]);

        $business->lines()->delete();
        foreach ($data['lines'] as $line) {
            $row = $business->lines()->make([
                'psic_code_id' => $line['psic_code_id'],
                'capitalization' => $line['capitalization'] ?? null,
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
