<?php

namespace Database\Factories;

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Models\BuylistSubmission;
use App\Models\BuylistSubmissionItem;
use App\Models\Printing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BuylistSubmissionItem>
 */
class BuylistSubmissionItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => BuylistSubmission::factory(),
            'printing_id' => Printing::factory(),
            'buylist_price_id' => null,
            'card_name' => fake()->words(2, true),
            'display_code' => 'OP05-'.fake()->numberBetween(1, 120),
            'language' => Language::En,
            'finish' => Finish::Normal,
            'claimed_condition' => CardCondition::Nm,
            'qty' => 1,
            'quoted_unit_satang' => 50000,
            'quoted_line_satang' => 50000,
        ];
    }
}
