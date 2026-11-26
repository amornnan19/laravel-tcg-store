<?php

namespace Database\Factories;

use App\Models\Preorder;
use App\Models\SealedProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open pre-order of a pre-order sealed product that releases in 30 days: a ฿500 deposit against ฿2,800, 2% off for
 * paying in full, forty places and two per account.
 *
 * @extends Factory<Preorder>
 */
class PreorderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sealed_product_id' => SealedProduct::factory()->preorder(),
            'is_open' => true,
            'opens_at' => null,
            'release_on' => now()->addDays(30)->toDateString(),
            'cancel_until' => now()->addDays(14)->toDateString(),
            'deposit_satang' => 50000,
            'full_satang' => 280000,
            'full_discount_bp' => 200,
            'quota' => 40,
            'reserved_count' => 0,
            'per_account_limit' => 2,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => ['is_open' => false]);
    }

    public function full(): static
    {
        return $this->state(fn (array $attributes): array => ['reserved_count' => $attributes['quota']]);
    }

    public function releasingIn(int $days): static
    {
        return $this->state(fn (): array => ['release_on' => now()->addDays($days)->toDateString()]);
    }
}
