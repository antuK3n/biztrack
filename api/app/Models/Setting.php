<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One runtime switch an administrator flips, by name.
 *
 * Added for the payment gateway switch (docs/payment-gateway.md), which has to
 * change without a redeploy — somebody turns it off before a presentation and
 * on again after, from a screen or `php artisan biztrack:payment-gateway`. Env
 * config cannot do that on a running server, so the env value is only the
 * DEFAULT, used until somebody sets the row.
 *
 * Deliberately tiny: string key, string value. Anything structured belongs in
 * its own table.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function read(string $key): ?string
    {
        return static::query()->whereKey($key)->value('value');
    }

    public static function write(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
