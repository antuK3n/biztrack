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
        'is_rented', 'lessor_name', 'lessor_address', 'lessor_contact',
        'monthly_rental', 'emergency_contact_name', 'emergency_contact_number',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_rented' => 'boolean',
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

    /** Statuses that bar the owner from filing new applications. */
    public function isBlockedFromApplying(): bool
    {
        return in_array($this->status, ['suspended', 'blacklisted'], true);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function address(): HasOne
    {
        return $this->hasOne(BusinessAddress::class);
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
