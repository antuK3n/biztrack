<?php

namespace App\Models;

use App\Notifications\VerifyEmailAddress;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * `MustVerifyEmail` is a CONTRACT, not a gate.
 *
 * Declaring it gives the model `hasVerifiedEmail()`, `markEmailAsVerified()`
 * and `sendEmailVerificationNotification()` — the trait is already mixed in by
 * Illuminate\Foundation\Auth\User — and it is what makes the framework's
 * `verified` middleware and the `Verified` event mean something here. It does
 * NOT by itself stop an unverified account from signing in: nothing in this
 * app is wrapped in `verified`, and login enforcement is a config switch that
 * ships off (config/auth.php → auth.verification.required_at_login, and the
 * note there explains why).
 *
 * The column has existed since the first migration; what was missing until
 * item #61 was anything that ever set it honestly.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'first_name', 'middle_name', 'last_name', 'suffix', 'gender',
        'email', 'mobile_number', 'password', 'department_id', 'is_active',
        'home_street', 'home_barangay', 'home_city', 'home_province', 'home_postal_code',
        'data_privacy_consent_at', 'email_verified_at',
        'last_login_at', 'failed_login_attempts', 'locked_until',
        'blacklisted_at', 'blacklist_reason', 'blacklisted_by',
    ];

    protected $hidden = ['password', 'remember_token'];

    /**
     * The parts of a home address an owner has to give for it to count as
     * given [checklist 2026-09-28, Register 2]. ZIP is left out on purpose —
     * many people do not know theirs, and nothing downstream reads it. One list,
     * so registration, the profile form and the "complete your profile" prompt
     * cannot disagree about what complete means.
     */
    public const HOME_ADDRESS_REQUIRED = ['home_street', 'home_barangay', 'home_city', 'home_province'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'data_privacy_consent_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'blacklisted_at' => 'datetime',
        ];
    }

    /**
     * Is this owner barred from the register altogether?
     *
     * ── Why the question is asked of the person ──────────────────────────────
     *
     * Blacklisting used to be a status on ONE business, so an owner barred for
     * falsified documents could file for their other two the same afternoon,
     * and register a fourth. A suspension is about a premises; a blacklisting
     * is a judgement about whoever is filing, and it only means anything if it
     * follows them [client, 27 September 2026: *"once na naka blacklist,
     * mismong owner na tlga yan"*].
     *
     * Every business they own is set to `blacklisted` in the same act, so the
     * roster, the certificates and the counter's QR check keep reading one
     * column. This is the cause; that is its consequence.
     */
    public function isBlacklisted(): bool
    {
        return $this->blacklisted_at !== null;
    }

    /** The officer who imposed the bar, for the register that lists it. */
    public function blacklistedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'blacklisted_by');
    }

    // --- relationships -------------------------------------------------------
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'owner_user_id');
    }

    /**
     * The general enquiries this person owns: one per office they have written
     * to, with no filing behind any of them.
     *
     * Only the enquiry threads are theirs. A filing's conversation belongs to
     * the application, not to a person, which is why `user_id` is null on one -
     * so this relation cannot accidentally hand somebody another party's
     * correspondence on a permit.
     */
    public function messageThreads(): HasMany
    {
        return $this->hasMany(MessageThread::class, 'user_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'inspector_user_id');
    }

    /**
     * The office reviews this officer is named on — held AND finished.
     *
     * Deliberately unfiltered. "What is this officer holding" is a narrower
     * question than "which assignments carry their name", and the narrowing
     * lives in `App\Support\Caseload` where one rule serves every screen that
     * asks. A relation that pre-filtered would be a second answer to the same
     * question, and the two would drift.
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ApplicationAssignment::class, 'officer_user_id');
    }

    // --- RBAC helpers --------------------------------------------------------
    public function roleNames(): array
    {
        return $this->roles->pluck('name')->all();
    }

    public function permissionNames(): array
    {
        return $this->roles
            ->loadMissing('permissions')
            ->flatMap(fn (Role $r) => $r->permissions->pluck('name'))
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(string $name): bool
    {
        return in_array($name, $this->permissionNames(), true);
    }

    public function hasRole(string $name): bool
    {
        return in_array($name, $this->roleNames(), true);
    }

    /**
     * Send OUR verification email rather than Laravel's stock one.
     *
     * Overriding here instead of in a service provider's `VerifyEmail::toMailUsing`
     * keeps the choice next to the model that owns the address: anything that
     * calls `$user->sendEmailVerificationNotification()` — registration, the
     * resend endpoint, a future admin action — gets the same message without
     * having to know a closure was registered somewhere at boot.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailAddress);
    }

    /**
     * Whether every required part of the home address is on file.
     *
     * All four or nothing counts: a street with no city is not an address
     * anyone can use, and treating it as one would switch the prompt off for
     * an owner whose record is still unusable.
     */
    public function hasHomeAddress(): bool
    {
        foreach (self::HOME_ADDRESS_REQUIRED as $column) {
            if (blank($this->{$column})) {
                return false;
            }
        }

        return true;
    }

    public function fullName(): string
    {
        return trim(collect([$this->first_name, $this->middle_name, $this->last_name, $this->suffix])
            ->filter()->implode(' '));
    }
}
