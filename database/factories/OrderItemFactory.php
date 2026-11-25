<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A snapshot line with no variant behind it, which is how an order reads after the variant is deleted.
 *
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unit = fake()->numberBetween(10, 5000) * 100;
        $qty = fake()->numberBetween(1, 3);

        return [
            'order_id' => Order::factory(),
            'purchasable_type' => null,
            'purchasable_id' => null,
            'card_name' => fake()->words(2, true),
            'set_code' => strtoupper(fake()->bothify('??##')),
            'number' => fake()->numerify('###'),
            'language' => 'en',
            'condition' => 'nm',
            'finish' => 'normal',
            'unit_price_satang' => $unit,
            'qty' => $qty,
            'line_total_satang' => $unit * $qty,
        ];
    }

    /**
     * A graded card line: no condition or finish, and the grade and masked certificate in `detail`.
     */
    public function graded(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => 'graded',
            'condition' => null,
            'finish' => null,
            'qty' => 1,
            'line_total_satang' => $attributes['unit_price_satang'],
            'detail' => 'PSA 10 · cert •••• 4821',
        ]);
    }

    /**
     * A sealed product line: a name and a detail only.
     */
    public function sealed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => 'sealed',
            'number' => null,
            'language' => null,
            'condition' => null,
            'finish' => null,
            'detail' => 'Booster Box',
        ]);
    }
}
