<?php

require_once __DIR__.'/sealed.php';

use App\Actions\ConfirmPayment;
use App\Actions\PlacePreorder;
use App\Enums\PaymentStatus;
use App\Enums\PreorderPlan;
use App\Models\Order;
use App\Models\Preorder;
use App\Models\SealedProduct;
use App\Models\User;

/**
 * Shared by the pre-order tests (they also use the checkout helpers of tests/Support/graded.php).
 *
 * An open pre-order of a fresh pre-order product: a ฿500 deposit against ฿2,800, 2% off in full, forty places and two
 * per account, releasing in 30 days with a cancel deadline in 14, unless attributes say otherwise.
 *
 * @param  array<string, mixed>  $attributes
 */
function openPreorder(array $attributes = [], ?SealedProduct $product = null): Preorder
{
    $product ??= sealedProductInSet(['is_preorder' => true, 'price_satang' => 280000, 'qty' => 0]);

    return Preorder::factory()->create(['sealed_product_id' => $product->id, ...$attributes]);
}

function placePreorder(Preorder $preorder, ?User $user = null, int $qty = 1, PreorderPlan $plan = PreorderPlan::Deposit): Order
{
    return app(PlacePreorder::class)->handle($preorder, $user ?? User::factory()->create(), gradedCheckoutData(), $qty, $plan);
}

/**
 * Staff confirm the order's newest payment, which is first marked as a submitted slip.
 */
function confirmLatestPayment(Order $order): Order
{
    $payment = $order->payments()->latest('id')->firstOrFail();
    $payment->update(['status' => PaymentStatus::Submitted, 'slip_path' => 'slips/x.jpg', 'submitted_at' => now()]);

    app(ConfirmPayment::class)->handle($payment);

    return $order->refresh();
}
