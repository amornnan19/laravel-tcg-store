<?php

namespace Database\Factories;

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Models\Printing;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variant>
 */
class VariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'printing_id' => Printing::factory(),
            'condition' => CardCondition::Nm,
            'finish' => Finish::Normal,
            'price_satang' => fake()->numberBetween(10, 5000) * 100,
            'compare_at_satang' => null,
            'qty' => fake()->numberBetween(0, 8),
            'note_th' => null,
            'has_real_photo' => false,
        ];
    }

    /**
     * Indicate that this is the foil finish.
     */
    public function foil(): static
    {
        return $this->state(fn (array $attributes) => [
            'finish' => Finish::Foil,
        ]);
    }

    /**
     * Indicate that the variant has no price yet.
     */
    public function unpriced(): static
    {
        return $this->state(fn (array $attributes) => [
            'price_satang' => null,
        ]);
    }
}
