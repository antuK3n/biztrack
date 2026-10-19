<?php

namespace App\Support;

use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Which answers an office may change in Edit mode, in three groups.
 *
 * Client, 19 October 2026: *"it is told to us to have restrictions on what
 * certain fields are allowed to be edited only by the admin."* The choice
 * taken was three groups, and this class is the ONE place they are listed.
 * The API enforces them (BusinessController::updateAsOfficer for the
 * business permit form, OfficeFormController::upsert for an office's own
 * sheet) and the review screen reads them from
 * `GET /reference/officer-edit-rules`, so the lock icons cannot drift from
 * what the server will accept.
 *
 *  1. OFFICE USE — what the office itself fills in: the issuance dates on its
 *     sheet, the evaluator remarks that ride with a decision. Free to the
 *     office that owns them, no reason asked. (The assessed fee is the
 *     server's computation and is typed by nobody.)
 *  2. CLERICAL — a misspelt business or trade name, the house number or
 *     street, a contact number, an e-mail or website. The office that owns
 *     the form may fix one, but only with a short reason, which is written to
 *     the audit log beside the old and new value and the officer's name, and
 *     the owner is told ("BPLO corrected your …").
 *  3. LOCKED — everything the applicant DECLARED: TIN, registration number,
 *     the owner's name, form of organization, line of business, capital,
 *     gross sales, employees, floor area, the rent answer, the documents.
 *     Nobody but the applicant changes these; the office uses Return.
 *
 * Every key not listed under 1 or 2 is 3. That default is the point: a field
 * added to the form tomorrow is locked until somebody decides otherwise here.
 *
 * Whose form is whose: the business permit form is BPLO's, so a clearance
 * office cannot correct it even where the field is clerical (the answers it
 * carries onto its own sheet are BPLO's too). Each office corrects only the
 * sheet of the permit it issues.
 */
final class OfficerEditRules
{
    /** The office that owns the business permit form. */
    public const BUSINESS_FORM_OFFICE = 'BPLO';

    /**
     * Group 2 on the business permit form, by the path the officer endpoint
     * takes (`address.street`, not the column alone).
     *
     * Block, lot and the landmark go with the house number and street: they
     * are the same line of the address, and a typo in one is the same kind of
     * slip. The lot AREA is not here — it is a measurement the applicant
     * declared.
     */
    public const BUSINESS_CLERICAL = [
        'name',
        'trade_name',
        'address.house_bldg_no',
        'address.street',
        // Composed from the two above by the writer, so it moves with them.
        'address.line1',
        'address.block',
        'address.lot',
        'address.line2',
        'address.telephone',
        'address.mobile_number',
        'address.email',
        'address.website',
        'emergency_contact_name',
        'emergency_contact_number',
    ];

    /**
     * Group 1 on the business permit form. Informational: neither is written
     * through the officer endpoint — the remarks travel with Approve/Return,
     * and the fee is computed.
     */
    public const BUSINESS_OFFICE_USE = ['evaluator_remarks'];

    /** Group 1 on an office's own sheet: the dates only the office can know. */
    public const SHEET_OFFICE_USE = ['building_permit_date', 'fsec_date', 'date_issued'];

    /**
     * Group 2 on each office's own sheet: the contact and address lines the
     * sheet itself asks (not the answers carried from the business permit
     * form, which are BPLO's and arrive derived).
     *
     * @var array<string, list<string>>
     */
    public const SHEET_CLERICAL = [
        'OCCUPANCY' => ['project_name', 'project_location', 'owner_address', 'owner_zip', 'owner_tel'],
        'CEC' => ['owner_address'],
        'ZONING' => [],
        'SANITARY' => [],
        'FSIC' => [],
    ];

    /** Words the owner reads in "BPLO corrected your …". */
    public const LABELS = [
        'name' => 'business name',
        'trade_name' => 'trade name',
        'address.house_bldg_no' => 'house / building no.',
        'address.street' => 'street',
        'address.line1' => 'address',
        'address.block' => 'block',
        'address.lot' => 'lot',
        'address.line2' => 'landmark',
        'address.telephone' => 'telephone',
        'address.mobile_number' => 'mobile number',
        'address.email' => 'e-mail address',
        'address.website' => 'website',
        'emergency_contact_name' => 'emergency contact person',
        'emergency_contact_number' => 'emergency contact number',
        'project_name' => 'project name',
        'project_location' => 'project location',
        'owner_address' => 'owner address',
        'owner_zip' => 'ZIP code',
        'owner_tel' => 'telephone',
    ];

    /** The 422s, one line each, under fifty characters. */
    public const LOCKED_MESSAGE = 'Only the applicant can change this. Use Return.';

    public const REASON_MESSAGE = 'Give a short reason for this correction.';

    public const NOT_YOUR_FORM_MESSAGE = 'Only BPLO can correct the business form.';

    /**
     * The refusal, as ONE line naming every refused key under `errors`.
     *
     * Not a ValidationException: with two locked keys Laravel's message
     * becomes "… (and 1 more error)", and the client asked for one short
     * line. Thrown inside the officer endpoints' transactions, so the write
     * it refuses is rolled back with it.
     *
     * @param  list<string>  $keys
     */
    public static function refusal(string $message, array $keys): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => array_fill_keys($keys, [$message]),
        ], 422));
    }

    /** 'clerical' or 'locked' for a business permit form path. */
    public static function businessGroup(string $key): string
    {
        return in_array($key, self::BUSINESS_CLERICAL, true) ? 'clerical' : 'locked';
    }

    /** 'office_use', 'clerical' or 'locked' for a key on an office's sheet. */
    public static function sheetGroup(string $code, string $key): string
    {
        if (in_array($key, self::SHEET_OFFICE_USE, true)) {
            return 'office_use';
        }

        return in_array($key, self::SHEET_CLERICAL[$code] ?? [], true) ? 'clerical' : 'locked';
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? str_replace('_', ' ', $key);
    }

    /** What the review screen reads, so it locks exactly what the server refuses. */
    public static function toArray(): array
    {
        return [
            'business' => [
                'office' => self::BUSINESS_FORM_OFFICE,
                'office_use' => self::BUSINESS_OFFICE_USE,
                'clerical' => self::BUSINESS_CLERICAL,
            ],
            'sheets' => collect(self::SHEET_CLERICAL)
                ->map(fn (array $clerical) => [
                    'office_use' => self::SHEET_OFFICE_USE,
                    'clerical' => $clerical,
                ])
                ->all(),
            'messages' => [
                'locked' => self::LOCKED_MESSAGE,
                'reason' => self::REASON_MESSAGE,
                'not_your_form' => self::NOT_YOUR_FORM_MESSAGE,
            ],
        ];
    }
}
