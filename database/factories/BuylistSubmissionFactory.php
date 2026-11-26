<?php

namespace Database\Factories;

use App\Enums\BuylistPayout;
use App\Enums\BuylistStatus;
use App\Models\BuylistSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BuylistSubmission>
 */
class BuylistSubmissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'new-'.Str::ulid(),
            'user_id' => User::factory(),
            'payout' => BuylistPayout::Credit,
            'status' => BuylistStatus::Submitted,
            'quoted_total_satang' => 100000,
            'quote_expires_at' => now()->addDays(7),
        ];
    }

    /**
     * Gives the row its `BUY-` number, as `SubmitBuylist` does after the insert.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (BuylistSubmission $submission): void {
            if (str_starts_with($submission->number, 'new-')) {
                $submission->update(['number' => BuylistSubmission::numberFor($submission->getKey())]);
            }
        });
    }
}
