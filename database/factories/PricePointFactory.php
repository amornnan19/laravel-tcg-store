<?php

namespace Database\Factories;

use App\Models\PricePoint;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PricePoint>
 */
class PricePointFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $low = fake()->numberBetween(10, 2000) * 100;
        $high = $low + fake()->numberBetween(0, 500) * 100;

        return [
            'variant_id' => Variant::factory(),
            'on_date' => fake()->unique()->date(),
            'avg_satang' => intdiv($low + $high, 2),
            'low_satang' => $low,
            'high_satang' => $high,
            'sold_qty' => fake()->numberBetween(1, 20),
        ];
    }
}
