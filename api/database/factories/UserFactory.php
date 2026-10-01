<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            /*
             * A complete home address, as every owner registered since
             * 28 September 2026 has [checklist Register 2]. `withoutHomeAddress()`
             * is the owner who registered before it was asked.
             */
            'home_street' => fake()->buildingNumber().' '.fake()->streetName(),
            'home_barangay' => 'Longos',
            'home_city' => 'Malabon',
            'home_province' => 'Metro Manila',
            'home_postal_code' => '1472',
        ];
    }

    public function withoutHomeAddress(): static
    {
        return $this->state(fn (array $attributes) => [
            'home_street' => null,
            'home_barangay' => null,
            'home_city' => null,
            'home_province' => null,
            'home_postal_code' => null,
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
