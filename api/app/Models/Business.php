<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Business extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'owner_user_id', 'name', 'trade_name', 'registration_type',
        // Absent from this list, mass assignment dropped it in silence: the
        // controller set it, create() ignored it, and the Form of Organization
        // panel read null on every business the application itself registered.
        'form_of_organization',
        'registration_number', 'tin', 'ban', 'is_active', 'status',
        /*
         * Written only by BusinessStatusController, and only when `status`
         * actually moves. It is what dates a blacklisting on the Business
         * Closure Trend; see the migration for why `updated_at` cannot.
         */
        'status_changed_at',
        'is_rented', 'lessor_name', 'lessor_address', 'lessor_contact',
        'monthly_rental', 'emergency_contact_name', 'emergency_contact_number',
        /*
         * Paper BPLO fields that had columns and no way in. Every one of these
         * was added by the Table 40 migration and never written by anything —
         * not the controllers, not the seeders, not the factories — because the
         * wizard had no input for it. `form_of_organization` above is the
         * cautionary tale: it was set by the controller, silently dropped by
         * mass assignment for want of a line here, and read null on every real
         * business. These are listed at the same time as the inputs that fill
         * them so the same thing cannot happen twice.
         *
         * Items B6 (economic organization), A13-A15 (president/OIC, their
         * citizenship, the Filipino share of capital) and B8/B7 (tax incentives).
         */
        'economic_organization', 'economic_organization_others',
        'president_officer_name', 'citizenship', 'capital_participation_filipino',
        // BPLO item B7. Absent from this list the column is dropped by mass
        // assignment in silence, which is how it stayed empty on every row while
        // a column for it sat on the table.
        'capital_investment',
        'has_tax_incentives',
    ];

    /**
     * BPLO item B6. What this PREMISES is to the business, which is a different
     * question from `form_of_organization` — what the business is in law.
     *
     * It is the answer that decides whether the paper's two addresses (item A5
     * Main Office Address, item B5 Business Location Address) are the same
     * place. BizTrack holds one address per business today; a Branch or an
     * Ancillary Unit is the case where that is not enough, and this column is
     * what will identify those when the second address exists.
     */
    public const ECONOMIC_ORGANIZATIONS = [
        'single_establishment',
        'branch',
        'establishment_and_main_office',
        'main_office_only',
        'ancillary_unit',
        'others',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'status_changed_at' => 'datetime',
        'is_rented' => 'boolean',
        'has_tax_incentives' => 'boolean',
        /*
         * decimal(5,2) in the schema, and cast so the JSON type is stable — the
         * same reason `monthly_rental` below is cast. Without it SQLite hands
         * back an int for "100" and a float for "60.5", so the wizard's percent
         * input would be re-hydrated from two different JSON types depending on
         * what somebody happened to type.
         */
        'capital_participation_filipino' => 'decimal:2',
        /*
         * Cast so the JSON type is stable. The column is `numeric` and had no
         * cast, so SQLite handed back an int for a whole amount and a float
         * otherwise: the same field serialized as a different type depending on
         * what someone had typed. types.ts declared `string | null`, the wizard
         * called .replace() on it, and reopening a saved draft threw
         * "raw.replace is not a function" mid-restore — which blanked the form
         * and then let autosave PUT the empty form back over the saved draft.
         * The draft survived only because `name` is required server-side.
         *
         * decimal:2 matches how every other money field is already serialized.
         */
        'monthly_rental' => 'decimal:2',
    ];

    /**
     * The four organisation structures the application collects.
     *
     * This is the canonical vocabulary for `registration_type`. It used to hold
     * two vocabularies at once — the four structures AND the three registering
     * agencies ("DTI", "SEC", "CDA") — because the wizard asked for a
     * "DTI / SEC / CDA Registration Number" and a "Type of Registration" as two
     * unrelated questions, and the seeders wrote the agency while the wizard
     * wrote the structure. Checklist item 94 is that conflation: the agency is
     * not a separate fact, it is a consequence of the structure.
     */
    public const ORGANIZATION_FORMS = ['sole_proprietorship', 'partnership', 'corporation', 'cooperative'];

    /**
     * Who registers each structure. This is the whole of item 94's mapping, and
     * it is deliberately many-to-one: the SEC registers both partnerships and
     * corporations, so an agency can never be reversed into a structure without
     * losing information. Everything that needs an agency derives it from here;
     * nothing stores it.
     */
    public const REGISTRAR_BY_FORM = [
        'sole_proprietorship' => 'DTI',
        'partnership' => 'SEC',
        'corporation' => 'SEC',
        'cooperative' => 'CDA',
    ];

    /**
     * Legacy agency codes that CAN be read back as a structure without guessing.
     *
     * "SEC" is absent on purpose. It is the one agency that registers two
     * structures, so a row saying "SEC" genuinely does not record whether the
     * business is a partnership or a corporation, and there is no other column
     * to ask. Anything that meets a legacy "SEC" must return null and put the
     * question back to the applicant rather than pick one.
     */
    private const FORM_BY_REGISTRAR = [
        'DTI' => 'sole_proprietorship',
        'CDA' => 'cooperative',
    ];

    /**
     * Read a `registration_type` as an organisation structure.
     *
     * Returns one of ORGANIZATION_FORMS, or null when the stored value cannot be
     * resolved to one — an unrecognised string, or the ambiguous legacy "SEC".
     * Null means "we do not know", never "corporation".
     */
    public static function normalizeRegistrationType(?string $raw): ?string
    {
        $value = is_string($raw) ? trim($raw) : '';
        if ($value === '') {
            return null;
        }
        if (in_array($value, self::ORGANIZATION_FORMS, true)) {
            return $value;
        }

        return self::FORM_BY_REGISTRAR[strtoupper($value)] ?? null;
    }

    /**
     * The agency a structure is registered with — "DTI", "SEC" or "CDA".
     *
     * Legacy rows that still hold an agency code answer with themselves, so a
     * screen showing "which agency issued this number" is right about the 143
     * un-migrated SEC rows even though their structure is unknown.
     */
    public static function registrarFor(?string $registrationType): ?string
    {
        $value = is_string($registrationType) ? trim($registrationType) : '';
        if ($value === '') {
            return null;
        }
        if (isset(self::REGISTRAR_BY_FORM[$value])) {
            return self::REGISTRAR_BY_FORM[$value];
        }
        $upper = strtoupper($value);

        return in_array($upper, self::REGISTRAR_BY_FORM, true) ? $upper : null;
    }

    /**
     * The moderation status that also reads as a closure.
     *
     * Named rather than spelled out because BusinessGrowthAnalytics now counts
     * it as one, and a typo in a string literal over there would quietly empty
     * the Business Closure Trend instead of failing. Suspension deliberately
     * gets no such constant: it is temporary and is not a closure.
     */
    public const STATUS_BLACKLISTED = 'blacklisted';

    /** Statuses that bar the owner from filing new applications. */
    public function isBlockedFromApplying(): bool
    {
        return in_array($this->status, ['suspended', self::STATUS_BLACKLISTED], true);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function address(): HasOne
    {
        return $this->hasOne(BusinessAddress::class);
    }

    /**
     * The named person on the paper — BPLO item 11 (Sole Proprietorship) and
     * item 12 (Corporation / Partnership / Cooperative).
     *
     * `business_owners` has held surname, given name, middle name, SUFFIX and
     * GENDER since the schema was aligned with the manuscript, and until now its
     * only writers were two seeders: no controller touched it, so a form that
     * asks for a suffix and a gender had nowhere to put either. This is that
     * relation, finally used.
     *
     * Distinct from `owner()` directly above, which is the USER ACCOUNT that
     * filed. The account holder is usually the proprietor and on the paper they
     * are two different questions — a corporation's filer is not its president.
     *
     * `hasMany` because item 12 prints two rows. The wizard writes one today
     * (the primary), and the relation is plural so the second does not need a
     * migration when it is asked for.
     */
    public function owners(): HasMany
    {
        return $this->hasMany(BusinessOwner::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BusinessLine::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function permits(): HasMany
    {
        return $this->hasMany(Permit::class);
    }
}
