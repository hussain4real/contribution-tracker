<?php

namespace Database\Factories;

use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\RecoveryCode;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => encrypt(app(TwoFactorAuthenticationProvider::class)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(array_map(fn () => RecoveryCode::generate(), range(1, 8)))),
            'two_factor_confirmed_at' => now(),
            'role' => Role::Member,
            'category' => MemberCategory::Employed,
            'family_id' => Family::factory(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model does not have two-factor authentication configured.
     */
    public function withoutTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }

    // =========================================================================
    // Role States
    // =========================================================================

    /**
     * Create a Family Admin user.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Admin,
            'category' => null, // Admin doesn't pay contributions
        ]);
    }

    /**
     * Create a platform Super Admin user.
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_super_admin' => true,
        ]);
    }

    /**
     * Create a Financial Secretary user.
     */
    public function financialSecretary(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::FinancialSecretary,
        ]);
    }

    /**
     * Create a regular Member user.
     */
    public function member(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::Member,
        ]);
    }

    // =========================================================================
    // Category States
    // =========================================================================

    /**
     * Create an Employed member (₦4,000/month).
     */
    public function employed(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => MemberCategory::Employed,
        ]);
    }

    /**
     * Create an Unemployed member (₦2,000/month).
     */
    public function unemployed(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => MemberCategory::Unemployed,
        ]);
    }

    /**
     * Create a Student member (₦1,000/month).
     */
    public function student(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => MemberCategory::Student,
        ]);
    }

    // =========================================================================
    // Status States
    // =========================================================================

    /**
     * Create an archived (soft-deleted) user.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'archived_at' => now(),
        ]);
    }

    /**
     * Create a user without a category (non-paying).
     */
    public function nonPaying(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => null,
        ]);
    }
}
