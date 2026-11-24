<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

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
            'is_staff' => false,
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
     * Indicate that the user is a staff member who may access the admin panel.
     */
    public function staff(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_staff' => true,
        ]);
    }

    /**
     * Indicate that the user has set up app (TOTP) authentication with a fresh secret.
     *
     * Recovery codes are stored bcrypt-hashed, as Filament stores and checks them; the plain
     * codes are `recovery-code-one` and `recovery-code-two`.
     */
    public function withAppAuthentication(): static
    {
        return $this->state(fn (array $attributes) => [
            'app_authentication_secret' => (new Google2FA)->generateSecretKey(),
            'app_authentication_recovery_codes' => [
                Hash::make('recovery-code-one'),
                Hash::make('recovery-code-two'),
            ],
        ]);
    }
}
