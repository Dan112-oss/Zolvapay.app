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
            'phone' => fake()->unique()->e164PhoneNumber(),
            'country_code' => fake()->randomElement(['NG', 'KE', 'CM', 'US', 'GB']),
            'password' => static::$password ??= Hash::make('password'),
            'kyc_tier' => 0,
            'status' => 'active',
            'role' => 'customer',
            'email_verified_at' => now(),
        ];
    }

    /**
     * Convenience state used throughout tests — most feature tests need
     * a Tier 1+ user to exercise money-moving endpoints at all (every
     * controller since Phase 3 gates on kyc_tier).
     */
    public function kycTier(int $tier): static
    {
        return $this->state(fn () => ['kyc_tier' => $tier]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin']);
    }
}
