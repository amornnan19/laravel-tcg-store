<?php

namespace Database\Seeders;

use App\Actions\RecordStoreCredit;
use App\Enums\StoreCreditReason;
use App\Models\User;
use Illuminate\Database\Seeder;

class StoreCreditSeeder extends Seeder
{
    /**
     * Gives the demo customer ฿500 of store credit, through the same action the shop uses (local only: the demo customer
     * has a known password). Skipped when that customer already has a ledger.
     */
    public function run(): void
    {
        if (! app()->isLocal()) {
            return;
        }

        $customer = User::query()->where('email', 'customer@example.com')->first()
            ?? User::factory()->create(['name' => 'Demo Customer', 'email' => 'customer@example.com']);

        if ($customer->storeCreditEntries()->exists()) {
            return;
        }

        app(RecordStoreCredit::class)->handle($customer, 50000, StoreCreditReason::Adjustment, note: 'เครดิตทดลอง');
    }
}
