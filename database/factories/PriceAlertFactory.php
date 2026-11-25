<?php

namespace Database\Factories;

use App\Enums\AlertChannel;
use App\Models\PriceAlert;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceAlert>
 */
class PriceAlertFactory extends Factory
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
            'target_satang' => fake()->numberBetween(10, 5000) * 100,
            'channel' => AlertChannel::Email,
            'is_active' => true,
            'last_fired_at' => null,
        ];
    }

    /**
     * Indicate that the alert already fired.
     */
    public function fired(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'last_fired_at' => now(),
        ]);
    }
}
