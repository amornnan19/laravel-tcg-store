<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'method' => PaymentMethod::Promptpay,
            'status' => PaymentStatus::Pending,
            'amount_satang' => fake()->numberBetween(10, 5000) * 100,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => PaymentStatus::Pending]);
    }

    /**
     * A slip is uploaded and waits for staff. No file is written: `slip_path` only names where it would be.
     */
    public function submitted(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Submitted,
            'slip_path' => 'slips/example/slip.jpg',
            'submitted_at' => now(),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Confirmed,
            'confirmed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'สลิปไม่ชัด'): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Rejected,
            'rejected_reason' => $reason,
        ]);
    }
}
