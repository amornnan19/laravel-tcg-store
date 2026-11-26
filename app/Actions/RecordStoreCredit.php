<?php

namespace App\Actions;

use App\Enums\StoreCreditReason;
use App\Exceptions\InsufficientStoreCredit;
use App\Models\StoreCreditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of `users.store_credit_satang`: appends a ledger entry and moves the cached balance in one
 * transaction. It locks the user's row and reads the balance from the locked row, so two spends cannot both see the
 * same balance. A caller that also locks orders or purchasables takes those locks first (project-wide lock order:
 * purchasables, orders, pre-orders, users, payments).
 */
class RecordStoreCredit
{
    /**
     * @throws InvalidArgumentException when the delta is zero, or an adjustment has no note.
     * @throws InsufficientStoreCredit when the new balance would be below zero.
     */
    public function handle(User $user, int $deltaSatang, StoreCreditReason $reason, ?Model $source = null, ?string $note = null, ?User $staff = null): StoreCreditEntry
    {
        $note = filled($note) ? trim($note) : null;

        if ($deltaSatang === 0) {
            throw new InvalidArgumentException('A store credit entry must change the balance.');
        }

        if ($reason === StoreCreditReason::Adjustment && $note === null) {
            throw new InvalidArgumentException('An adjustment needs a note.');
        }

        return DB::transaction(function () use ($user, $deltaSatang, $reason, $source, $note, $staff): StoreCreditEntry {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());

            $balance = $locked->store_credit_satang + $deltaSatang;

            if ($balance < 0) {
                throw new InsufficientStoreCredit($locked->store_credit_satang, $deltaSatang);
            }

            $entry = StoreCreditEntry::query()->create([
                'user_id' => $locked->getKey(),
                'delta_satang' => $deltaSatang,
                'reason' => $reason,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'note' => $note,
                'balance_after_satang' => $balance,
                'created_by' => $staff?->getKey(),
            ]);

            $locked->forceFill(['store_credit_satang' => $balance])->save();

            // The caller's copy shows the new balance too.
            $user->forceFill(['store_credit_satang' => $balance])->syncOriginalAttribute('store_credit_satang');

            return $entry;
        });
    }
}
