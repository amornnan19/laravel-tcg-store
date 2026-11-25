<?php

namespace Database\Factories;

use App\Enums\GradedStatus;
use App\Enums\Grader;
use App\Models\GradedItem;
use App\Models\Printing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A graded card that is for sale: listed, priced and with the three photo paths filled. The files themselves are not
 * written; a test that shows them stores its own.
 *
 * @extends Factory<GradedItem>
 */
class GradedItemFactory extends Factory
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
            'grader' => Grader::Psa,
            'grade' => 10,
            'grade_label' => 'Gem Mint',
            'cert_number' => fake()->numerify('########'),
            'subgrades' => null,
            'price_satang' => fake()->numberBetween(500, 90000) * 100,
            'status' => GradedStatus::Listed,
            'front_path' => 'graded-photos/front.jpg',
            'back_path' => 'graded-photos/back.jpg',
            'label_path' => 'graded-photos/label.jpg',
            'note_th' => null,
        ];
    }

    /**
     * Not for sale, and without photos: how staff first enter an item before shooting it.
     */
    public function withdrawn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GradedStatus::Withdrawn,
        ]);
    }

    public function withoutPhotos(): static
    {
        return $this->state(fn (array $attributes): array => [
            'front_path' => null,
            'back_path' => null,
            'label_path' => null,
        ]);
    }

    public function reserved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GradedStatus::Reserved,
        ]);
    }

    public function sold(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => GradedStatus::Sold,
        ]);
    }

    public function withoutCert(): static
    {
        return $this->state(fn (array $attributes): array => [
            'cert_number' => null,
        ]);
    }
}
