<?php

namespace Database\Factories;

use App\Enums\StoreCreditReason;
use App\Models\StoreCreditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Writes a bare ledger line: it does not touch `users.store_credit_satang`. Tests that need a real balance go through
 * `RecordStoreCredit`.
 *
 * @extends Factory<StoreCreditEntry>
 */
class StoreCreditEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'delta_satang' => 50000,
            'reason' => StoreCreditReason::Adjustment,
            'note' => fake()->sentence(3),
            'balance_after_satang' => 50000,
        ];
    }
}
