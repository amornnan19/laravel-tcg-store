<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to pay an order. A slip that is short is rejected and a second payment for the gap points back at it
 * through `shortfall_of_payment_id`.
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'order_id', 'method', 'status', 'amount_satang', 'qr_payload', 'qr_expires_at', 'slip_path',
        'slip_amount_satang', 'slip_ref', 'slip_paid_at', 'submitted_at', 'rejected_reason', 'gateway_ref',
        'shortfall_of_payment_id', 'confirmed_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount_satang' => 'integer',
            'slip_amount_satang' => 'integer',
            'qr_expires_at' => 'datetime',
            'slip_paid_at' => 'datetime',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The short payment this one pays the gap of.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function shortfallOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'shortfall_of_payment_id');
    }
}
