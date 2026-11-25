<?php

namespace Database\Factories;

use App\Enums\AlertChannel;
use App\Models\RestockAlert;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestockAlert>
 */
class RestockAlertFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'variant_id' => Variant::factory(),
            'channel' => AlertChannel::Email,
            'is_active' => true,
            'notified_at' => null,
        ];
    }

    /**
     * Indicate that the alert already fired.
     */
    public function notified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'notified_at' => now(),
        ]);
    }
}
