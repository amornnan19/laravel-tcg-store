<?php

namespace App\Models;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PreorderFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sealed product taken on pre-order: a quota of places, a release date, and the two ways to pay for one. `reserved_count`
 * is the number of places taken; it only moves inside the transaction that places, cancels or refunds a pre-order order
 * (which holds this row's lock), and the staff form never writes it. Dates are shop-local days: the order window closes
 * when `release_on` begins, and a cancel on `cancel_until` itself is still in time.
 */
#[Unguarded]
class Preorder extends Model
{
    /** @use HasFactory<PreorderFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'is_open' => 'boolean',
            'opens_at' => 'datetime',
            'release_on' => 'date',
            'cancel_until' => 'date',
            'deposit_satang' => 'integer',
            'full_satang' => 'integer',
            'full_discount_bp' => 'integer',
            'quota' => 'integer',
            'reserved_count' => 'integer',
            'per_account_limit' => 'integer',
        ];
    }

    /**
     * Re-reads the row under a lock; the first statement of every transaction that decides on quota.
     */
    public static function lockForPlacing(int $id): ?static
    {
        // A no-op on SQLite, which serialises writers; a row lock on MySQL and Postgres.
        return static::query()->lockForUpdate()->find($id);
    }

    /**
     * The moment the order window closes: the start of `release_on` in the shop's timezone.
     */
    public function releaseAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->release_on->format('Y-m-d'), config('shop.timezone'))->startOfDay();
    }

    /**
     * The last moment the balance can be paid in time: the end of `release_on` in the shop's timezone.
     */
    public function releaseDayEnd(): CarbonImmutable
    {
        return $this->releaseAt()->endOfDay();
    }

    /**
     * When a balance requested now is due: the end of the release day, but never less than the rest of today and all of
     * tomorrow in shop time, so a balance requested late is not born overdue and no customer gets a few minutes. In the
     * app's timezone, which is how a datetime column is stored.
     */
    public function balanceDueAt(): CarbonImmutable
    {
        $floor = CarbonImmutable::now(config('shop.timezone'))->addDay()->endOfDay();

        return $this->releaseDayEnd()->max($floor)->setTimezone(config('app.timezone'));
    }

    /**
     * Whether the release is seven days away or less (shop time): from then on a deposit order is asked for its balance.
     */
    public function isWithinBalanceWindow(): bool
    {
        return $this->release_on->format('Y-m-d') <= CarbonImmutable::now(config('shop.timezone'))->addDays(7)->toDateString();
    }

    /**
     * The last moment a cancel gets the deposit back, as the product page promises today: the end of `cancel_until` in the
     * shop's timezone. An order keeps its own copy of the date from when it was placed.
     */
    public function cancelUntilEnd(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->cancel_until->format('Y-m-d'), config('shop.timezone'))->endOfDay();
    }

    public function placesLeft(): int
    {
        return max(0, $this->quota - $this->reserved_count);
    }

    /**
     * `open` (an order can be placed), `not_yet` (`opens_at` has not come), `closed` (staff closed it), `released` (the
     * release date began) or `full` (every place is taken). Checked in that order of precedence, the same by the page
     * and by `PlacePreorder` under the lock.
     */
    public function state(): string
    {
        return match (true) {
            ! $this->is_open => 'closed',
            $this->opens_at !== null && $this->opens_at->isFuture() => 'not_yet',
            $this->releaseAt()->lte(now()) => 'released',
            $this->placesLeft() < 1 => 'full',
            default => 'open',
        };
    }

    public function isOpenForOrders(): bool
    {
        return $this->state() === 'open';
    }

    /**
     * One unit on the pay-in-full plan: the full price less the discount, the discount rounded down to a satang.
     */
    public function fullPlanUnitSatang(): int
    {
        return $this->full_satang - intdiv($this->full_satang * $this->full_discount_bp, 10000);
    }

    /**
     * What `$user` already has in orders that still reserve a place: every order of this pre-order that is not
     * cancelled or refunded. Reads orders, so call it under this row's lock.
     */
    public function reservedBy(User $user): int
    {
        return (int) OrderItem::query()
            ->whereHas('order', fn ($orders) => $orders
                ->where('preorder_id', $this->getKey())
                ->where('user_id', $user->getKey())
                ->where('kind', OrderKind::Preorder->value)
                ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value]))
            ->sum('qty');
    }

    /**
     * Whether the account limit, rather than the places left, is what caps `$user`'s next order.
     */
    public function limitBinds(?User $user): bool
    {
        $held = $user === null ? 0 : $this->reservedBy($user);

        return $this->per_account_limit - $held <= $this->placesLeft();
    }

    /**
     * How many `$user` may still order: the account limit less what they hold, and the places left.
     */
    public function maxQtyFor(?User $user): int
    {
        $left = $this->placesLeft();

        if ($user === null) {
            return max(0, min($this->per_account_limit, $left));
        }

        return max(0, min($this->per_account_limit - $this->reservedBy($user), $left));
    }

    /**
     * @return BelongsTo<SealedProduct, $this>
     */
    public function sealedProduct(): BelongsTo
    {
        return $this->belongsTo(SealedProduct::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
