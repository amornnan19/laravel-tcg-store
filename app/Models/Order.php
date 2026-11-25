<?php

namespace App\Models;

use App\Enums\Courier;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Carbon\CarbonInterface;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\URL;

/**
 * What a customer bought, as it was when they bought it: contact, address and items are copies, so editing the
 * catalogue or an account never rewrites an order. The status timeline reads the `*_at` columns and has no state of
 * its own. `user_id` stays null until accounts arrive in M6.
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $fillable = [
        'number', 'user_id', 'status', 'contact_name', 'contact_phone', 'contact_email', 'address', 'courier',
        'subtotal_satang', 'shipping_satang', 'protection_satang', 'insurance_satang', 'credit_used_satang',
        'total_satang', 'tracking_number', 'staff_note', 'cancel_reason', 'payment_due_at', 'placed_at', 'paid_at', 'packed_at',
        'shipped_at', 'delivered_at', 'cancelled_at', 'refunded_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'courier' => Courier::class,
            'address' => 'array',
            'subtotal_satang' => 'integer',
            'shipping_satang' => 'integer',
            'protection_satang' => 'integer',
            'insurance_satang' => 'integer',
            'credit_used_satang' => 'integer',
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
     * Money staff have seen arrive: confirmed payments in full, plus what a rejected payment's slip showed when it was
     * short of that payment's own amount.
     */
    public function receivedSatang(): int
    {
        $confirmed = (int) $this->payments()->where('status', PaymentStatus::Confirmed->value)->sum('amount_satang');
        $short = (int) $this->payments()
            ->where('status', PaymentStatus::Rejected->value)
            ->whereNotNull('slip_amount_satang')
            ->whereColumn('slip_amount_satang', '<', 'amount_satang')
            ->sum('slip_amount_satang');

        return $confirmed + $short;
    }

    /**
     * What is still owed: the total less what has been received, never below zero.
     */
    public function remainingSatang(): int
    {
        return max(0, $this->total_satang - $this->receivedSatang());
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

        foreach ($reached as $key => $at) {
            if ($at !== null) {
                $steps[] = ['key' => $key, 'at' => $at];
            }
        }

        if ($this->cancelled_at === null && $this->refunded_at === null) {
            foreach (['paid', 'packing', 'shipped', 'delivered'] as $key) {
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
