<?php

namespace Database\Factories;

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Models\BuylistPrice;
use App\Models\Printing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BuylistPrice>
 */
class BuylistPriceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cash = fake()->numberBetween(5, 500) * 100;

        return [
            'printing_id' => Printing::factory(),
            'condition' => CardCondition::Nm,
            'finish' => Finish::Normal,
            'cash_satang' => $cash,
            'credit_satang' => BuylistPrice::creditFor($cash),
            'weekly_quota' => null,
            'accepted_this_week' => 0,
            'is_active' => true,
        ];
    }

    public function foil(): static
    {
        return $this->state(fn (): array => ['finish' => Finish::Foil]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * A weekly cap, with some of it already taken.
     */
    public function quota(int $quota, int $taken = 0): static
    {
        return $this->state(fn (): array => ['weekly_quota' => $quota, 'accepted_this_week' => $taken]);
    }
}
