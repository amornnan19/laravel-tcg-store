<?php

namespace Database\Factories;

use App\Enums\Courier;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Orders default to `awaiting_payment` with one item and a pending payment, the totals adding up. Factories write
 * snapshot rows only: they never touch variant stock.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $placedAt = now();

        return [
            'number' => 'T'.fake()->unique()->numerify('########'),
            'user_id' => null,
            'status' => OrderStatus::AwaitingPayment,
            'contact_name' => fake()->name(),
            'contact_phone' => '08'.fake()->numerify('########'),
            'contact_email' => fake()->unique()->safeEmail(),
            'address' => [
                'recipient' => fake()->name(),
                'phone' => '08'.fake()->numerify('########'),
                'line1' => fake()->numerify('## ซอย ').fake()->numerify('#'),
                'subdistrict' => 'คลองเตย',
                'district' => 'คลองเตย',
                'province' => 'กรุงเทพมหานคร',
                'postcode' => '10110',
            ],
            'courier' => Courier::Flash,
            'subtotal_satang' => 0,
            'shipping_satang' => Courier::Flash->rateSatang(),
            'protection_satang' => 0,
            'insurance_satang' => 0,
            'credit_used_satang' => 0,
            'total_satang' => 0,
            'payment_due_at' => $placedAt->copy()->addMinutes(30),
            'placed_at' => $placedAt,
        ];
    }

    /**
     * After the order exists: give it one item when it has none (`has()` items win), make the totals match the items
     * and the shipping and packing already set, set the number from the id, and give it a payment when it has none.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Order $order): void {
            if (! $order->items()->exists()) {
                OrderItem::factory()->for($order)->create();
            }

            $subtotal = (int) $order->items()->sum('line_total_satang');

            $order->forceFill([
                'number' => Order::numberFor($order->id),
                'subtotal_satang' => $subtotal,
                'total_satang' => $subtotal + $order->shipping_satang + $order->protection_satang,
            ])->save();

            if (! $order->payments()->exists()) {
                Payment::factory()->for($order)->create([
                    'amount_satang' => $order->total_satang,
                    'status' => match (true) {
                        $order->isPaid() => PaymentStatus::Confirmed,
                        $order->isCancelled() => PaymentStatus::Rejected,
                        default => PaymentStatus::Pending,
                    },
                    'confirmed_at' => $order->paid_at,
                    'method' => PaymentMethod::Promptpay,
                ]);
            }
        });
    }

    public function awaitingPayment(): static
    {
        return $this->state(fn (): array => ['status' => OrderStatus::AwaitingPayment]);
    }

    /**
     * Awaiting payment, with a slip waiting for staff on its payment.
     */
    public function submitted(): static
    {
        return $this->afterCreating(function (Order $order): void {
            $order->payments()->update([
                'status' => PaymentStatus::Submitted,
                'slip_path' => 'slips/'.$order->number.'/slip.jpg',
                'submitted_at' => now(),
            ]);
        });
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Paid,
            'paid_at' => now()->subHour(),
        ]);
    }

    public function packing(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Packing,
            'paid_at' => now()->subHours(3),
            'packed_at' => now()->subHour(),
        ]);
    }

    public function shipped(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Shipped,
            'paid_at' => now()->subDay(),
            'packed_at' => now()->subHours(20),
            'shipped_at' => now()->subHours(18),
            'tracking_number' => 'TH'.fake()->numerify('##########'),
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Delivered,
            'placed_at' => now()->subDays(4),
            'paid_at' => now()->subDays(4),
            'packed_at' => now()->subDays(3),
            'shipped_at' => now()->subDays(3),
            'delivered_at' => now()->subDay(),
            'tracking_number' => 'TH'.fake()->numerify('##########'),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Past its payment deadline, still unpaid.
     */
    public function overdue(): static
    {
        return $this->state(fn (): array => [
            'placed_at' => now()->subMinutes(45),
            'payment_due_at' => now()->subMinutes(15),
        ]);
    }
}
