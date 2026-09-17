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
        'data_privacy_consent_at', 'email_verified_at',
        'last_login_at', 'failed_login_attempts', 'locked_until',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'data_privacy_consent_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
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

    public function notifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'inspector_user_id');
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

    public function fullName(): string
    {
        return trim(collect([$this->first_name, $this->middle_name, $this->last_name, $this->suffix])
            ->filter()->implode(' '));
    }
}
