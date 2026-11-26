<?php

namespace App\Models;

use App\Enums\Courier;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PreorderPlan;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\URL;

/**
 * What a customer bought, as it was when they bought it: contact, address and items are copies, so editing the
 * catalogue or an account never rewrites an order. The status timeline reads the `*_at` columns and has no state of
 * its own. `user_id` is the customer's account, or null for a guest order.
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'number', 'user_id', 'status', 'kind', 'preorder_id', 'preorder_plan', 'cancel_until', 'deposit_satang', 'balance_due_satang',
        'balance_requested_at', 'contact_name', 'contact_phone', 'contact_email', 'address', 'courier',
        'subtotal_satang', 'shipping_satang', 'protection_satang', 'insurance_satang', 'credit_used_satang',
        'refunded_satang', 'total_satang', 'tracking_number', 'staff_note', 'cancel_reason', 'refund_reason', 'payment_due_at', 'placed_at', 'paid_at', 'packed_at',
        'shipped_at', 'delivered_at', 'cancelled_at', 'refunded_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'kind' => OrderKind::class,
            'preorder_plan' => PreorderPlan::class,
            'cancel_until' => 'date',
            'deposit_satang' => 'integer',
            'balance_due_satang' => 'integer',
            'balance_requested_at' => 'datetime',
            'courier' => Courier::class,
            'address' => 'array',
            'subtotal_satang' => 'integer',
            'shipping_satang' => 'integer',
            'protection_satang' => 'integer',
            'insurance_satang' => 'integer',
            'credit_used_satang' => 'integer',
            'refunded_satang' => 'integer',
            'total_satang' => 'integer',
            'payment_due_at' => 'datetime',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'packed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * The human-facing number, `TCG-00042`, from the row id.
     */
    public static function numberFor(int $id): string
    {
        return sprintf('TCG-%05d', $id);
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    /**
     * The link every email carries: opens the order page without a login, so it never expires.
     */
    public function signedUrl(): string
    {
        return URL::signedRoute('order.show', ['order' => $this->number]);
    }

    /**
     * Money staff have seen arrive and still hold: confirmed payments in full, plus what a rejected payment's slip showed
     * when it was short of that payment's own amount, less what a refund has already returned. Never below zero.
     */
    public function receivedSatang(): int
    {
        $confirmed = (int) $this->payments()->where('status', PaymentStatus::Confirmed->value)->sum('amount_satang');
        $short = (int) $this->payments()
            ->where('status', PaymentStatus::Rejected->value)
            ->whereNotNull('slip_amount_satang')
            ->whereColumn('slip_amount_satang', '<', 'amount_satang')
            ->sum('slip_amount_satang');

        return max(0, $confirmed + $short - $this->refunded_satang);
    }

    /**
     * What is still owed: the total less what has been received, never below zero.
     */
    public function remainingSatang(): int
    {
        return max(0, $this->total_satang - $this->receivedSatang());
    }

    /**
     * What the customer is asked to pay now. For a pre-order on the deposit plan that has not yet had its deposit it is
     * what is left of the deposit, not of the whole total; in every other case it is `remainingSatang()`.
     */
    public function amountDueNowSatang(): int
    {
        if ($this->isDepositPlanAwaitingDeposit()) {
            return max(0, $this->deposit_satang - $this->receivedSatang());
        }

        return $this->remainingSatang();
    }

    /**
     * What staff owe the customer back when the order is cancelled or refunded now. A pre-order cancelled after its
     * `cancel_until` keeps the deposit: it owes only what was received beyond it, never below zero. Everything else owes
     * what was received.
     */
    public function refundOwedSatang(): int
    {
        $received = $this->receivedSatang();

        if (! $this->isPreorder() || $this->isDepositRefundable()) {
            return $received;
        }

        return max(0, $received - $this->deposit_satang);
    }

    /**
     * The last moment a cancel still gets a pre-order's deposit back, as promised when it was placed: the end of the
     * snapshot `cancel_until` day in shop time. Null for an order that is not a pre-order.
     */
    public function cancelUntilEnd(): ?CarbonImmutable
    {
        return $this->cancel_until === null
            ? null
            : CarbonImmutable::parse($this->cancel_until->format('Y-m-d'), config('shop.timezone'))->endOfDay();
    }

    public function isDepositRefundable(): bool
    {
        $end = $this->cancelUntilEnd();

        return $end === null || now()->lte($end);
    }

    /**
     * What a cancelled or refunded pre-order owes back and what the shop keeps, for the order page. A cancel keeps its
     * payments, so it is read from what was received; a refund records what it paid out. The deposit is kept when the
     * order was closed after its `cancel_until`, up to what was actually paid.
     *
     * @return array{owed: int, kept: int}
     */
    public function refundSummary(): array
    {
        $closedAt = $this->refunded_at ?? $this->cancelled_at;

        if ($closedAt === null) {
            return ['owed' => 0, 'kept' => 0];
        }

        $end = $this->cancelUntilEnd();
        $late = $end !== null && $closedAt->gt($end);

        if ($this->status === OrderStatus::Refunded) {
            return ['owed' => $this->refunded_satang, 'kept' => $late ? $this->deposit_satang : 0];
        }

        $received = $this->receivedSatang();
        $kept = $late ? min($this->deposit_satang, $received) : 0;

        return ['owed' => $received - $kept, 'kept' => $kept];
    }

    /**
     * The payment deadline as a customer reads it, in shop time: the date, and the time as well unless the deadline is
     * the very end of a day.
     */
    public function paymentDueLabel(): string
    {
        $due = $this->payment_due_at->copy()->setTimezone(config('shop.timezone'))->locale('th');

        return $due->format('H:i:s') === '23:59:59'
            ? $due->translatedFormat('j M Y')
            : __('storefront.order.date_time', ['date' => $due->translatedFormat('j M Y'), 'time' => $due->format('H:i')]);
    }

    public function isPreorder(): bool
    {
        return $this->kind === OrderKind::Preorder;
    }

    private function isDepositPlanAwaitingDeposit(): bool
    {
        return $this->isPreorder()
            && $this->preorder_plan === PreorderPlan::Deposit
            && $this->status === OrderStatus::AwaitingPayment;
    }

    /**
     * Whether a payment of this order can be paid or checked now: it waits for its first payment, or its balance was
     * requested and has not come in.
     */
    public function isWaitingForMoney(): bool
    {
        return $this->status === OrderStatus::AwaitingPayment
            || ($this->status === OrderStatus::AwaitingBalance && $this->balance_requested_at !== null);
    }

    /**
     * Pre-orders whose balance was requested and is past its deadline: nothing cancels them on its own, so the desk
     * lists them for the shop to decide.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeOverdueBalance(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::AwaitingBalance->value)
            ->whereNotNull('balance_requested_at')
            ->where('payment_due_at', '<=', now());
    }

    /**
     * Orders that ran past their payment deadline with money already in (a slip rejected as short): the shop must
     * decide, so nothing cancels them on its own.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeOverdueWithShortSlip(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::AwaitingPayment->value)
            ->where('payment_due_at', '<=', now())
            ->whereHas('payments', fn (Builder $payments) => $payments
                ->where('status', PaymentStatus::Rejected->value)
                ->whereNotNull('slip_amount_satang')
                ->whereColumn('slip_amount_satang', '<', 'amount_satang'));
    }

    /**
     * Whether a slip reference is already credited on a payment of this order other than `$exceptPaymentId`: the payment
     * was confirmed, or it was rejected as short and its slip amount counts towards `receivedSatang()`. One slip must not
     * be credited twice. `$slipRef` is compared in its normalised form (see `Payment::normaliseSlipRef()`).
     */
    public function slipRefCredited(string $slipRef, int $exceptPaymentId): bool
    {
        return $this->payments()
            ->whereKeyNot($exceptPaymentId)
            ->where('slip_ref', $slipRef)
            ->where(fn ($query) => $query
                ->where('status', PaymentStatus::Confirmed->value)
                ->orWhere(fn ($short) => $short
                    ->where('status', PaymentStatus::Rejected->value)
                    ->whereNotNull('slip_amount_satang')
                    ->whereColumn('slip_amount_satang', '<', 'amount_satang')))
            ->exists();
    }

    public function isAwaitingPayment(): bool
    {
        return $this->status === OrderStatus::AwaitingPayment;
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function isCancelled(): bool
    {
        return $this->status === OrderStatus::Cancelled;
    }

    /**
     * The order's progress, read from its timestamps only: each step that happened with when, then, while the order is
     * still on its way, the steps still to come with a null time. A cancelled or refunded order shows only what happened.
     *
     * @return list<array{key: string, at: ?CarbonInterface}>
     */
    public function timeline(): array
    {
        $reached = [
            'placed' => $this->placed_at,
            'paid' => $this->paid_at,
            'packing' => $this->packed_at,
            'shipped' => $this->shipped_at,
            'delivered' => $this->delivered_at,
            'cancelled' => $this->cancelled_at,
            'refunded' => $this->refunded_at,
        ];

        $steps = [];
        $upcoming = ['paid', 'packing', 'shipped', 'delivered'];

        // A deposit order shows when its deposit was confirmed, between placing and the paid-in-full step.
        if ($this->isPreorder() && $this->preorder_plan === PreorderPlan::Deposit) {
            $confirmedAt = $this->payments()->where('status', PaymentStatus::Confirmed->value)->min('confirmed_at');
            $reached = ['placed' => $reached['placed'], 'deposit' => $confirmedAt === null ? null : Carbon::parse($confirmedAt, config('app.timezone'))] + $reached;
            array_unshift($upcoming, 'deposit');
        }

        foreach ($reached as $key => $at) {
            if ($at !== null) {
                $steps[] = ['key' => $key, 'at' => $at];
            }
        }

        if ($this->cancelled_at === null && $this->refunded_at === null) {
            foreach ($upcoming as $key) {
                if ($reached[$key] === null) {
                    $steps[] = ['key' => $key, 'at' => null];
                }
            }
        }

        return $steps;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Preorder, $this>
     */
    public function preorder(): BelongsTo
    {
        return $this->belongsTo(Preorder::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasOne<Payment, $this>
     */
    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }
}
