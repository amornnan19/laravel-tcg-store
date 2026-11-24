<?php

namespace Database\Factories;

use App\Models\Variant;
use App\Models\VariantPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VariantPhoto>
 */
class VariantPhotoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'variant_id' => Variant::factory(),
            'path' => 'variant-photos/'.fake()->uuid().'.jpg',
            'sort' => 0,
        ];
    }
}
