<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BusinessResource;
use App\Models\Business;
use App\Support\Audit;
use App\Support\Numbering;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Business registry. Owners manage their own; officers with application.view_all
 * may read any (via the application context on the frontend).
 */
class BusinessController extends Controller
{
    private array $eager = ['address.barangay', 'lines.psicCode'];

    public function index(Request $request): JsonResponse
    {
        $businesses = Business::with($this->eager)
            ->where('owner_user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => BusinessResource::collection($businesses)]);
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

    /** Renewal/amendment prefill: business + last permit + last application. */
    public function prefill(Request $request, Business $business): JsonResponse
    {
        $this->authorizeOwner($request, $business);
        $request->validate(['type' => ['nullable', 'in:renewal,amendment']]);

        $business->load($this->eager);

        $lastPermit = $business->permits()
            ->with('permitType:id,code,name')
            ->orderByDesc('issued_at')
            ->first();

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
                'last_application' => $lastApplication ? [
                    'id' => $lastApplication->id,
                    'permit_type_ids' => $lastApplication->permitTypes->pluck('id')->values(),
                ] : null,
                'suggested_permit_type_ids' => $suggested,
            ],
        ]);
    }

    private function validateBusiness(Request $request): array
    {
        // Normalise the TIN before the rule runs, so applicants may type the
        // separators they are used to (123 456 789 000, 123.456.789, plain
        // digits) and we still store one canonical form.
        if (filled($request->input('tin'))) {
            $request->merge(['tin' => self::normalizeTin((string) $request->input('tin'))]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Trade name stays optional: most sole proprietors have none.
            'trade_name' => ['nullable', 'string', 'max:255'],
            'registration_type' => ['required', 'string', 'max:50'],
            'registration_number' => ['required', 'string', 'max:100'],
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
            'registration_number.required' => 'Enter your DTI, SEC, or CDA registration number.',
            'tin.required' => 'Enter your Tax Identification Number.',
            'tin.regex' => 'Enter a valid TIN: 9 digits, plus a branch code if you have one, like 123-456-789-000.',
        ]);
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

    private function authorizeOwnerOrOfficer(Request $request, Business $business): void
    {
        if ($business->owner_user_id === $request->user()->id) {
            return;
        }
        abort_unless($request->user()->hasPermission('application.view_all'), 403, 'You may not view this business.');
    }
}
