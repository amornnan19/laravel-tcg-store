<?php

use App\Actions\CancelOrder;
use App\Actions\ConfirmPayment;
use App\Actions\DeliverOrder;
use App\Actions\PackOrder;
use App\Actions\RefundOrder;
use App\Actions\RejectPayment;
use App\Actions\ShipOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RejectReason;
use App\Exceptions\IllegalOrderTransition;
use App\Exceptions\PaymentRefused;
use App\Exceptions\SlipRefAlreadyCredited;
use App\Filament\Pages\PaymentDesk;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Widgets\OrdersToPackWidget;
use App\Filament\Widgets\PendingSlipsWidget;
use App\Filament\Widgets\ShortfallOrdersWidget;
use App\Jobs\CancelUnpaidOrders;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Game;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Printing;
use App\Models\User;
use App\Models\Variant;
use App\Notifications\OrderCancelled;
use App\Notifications\OrderDelivered;
use App\Notifications\OrderPacked;
use App\Notifications\OrderRefunded;
use App\Notifications\OrderShipped;
use App\Notifications\PaymentConfirmed;
use App\Notifications\PaymentRejected;
use Database\Seeders\OrderSeeder;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function staffUser(): User
{
    return User::factory()->staff()->withAppAuthentication()->create();
}

/**
 * An order awaiting payment whose only payment is submitted, for `$total` satang, with `$qty` of a variant taken out.
 */
function submittedOrder(int $total = 100000, int $variantQty = 0, int $qty = 1): array
{
    $card = Card::factory()->for(Game::factory())->create();
    $printing = Printing::factory()->for($card)->for(CardSet::factory()->for($card->game), 'set')->create();
    $variant = Variant::factory()->for($printing)->create(['qty' => $variantQty, 'price_satang' => $total]);

    $order = Order::factory()->has(OrderItem::factory()->state([
        'purchasable_type' => $variant->getMorphClass(),
        'purchasable_id' => $variant->id,
        'qty' => $qty,
        'unit_price_satang' => $total - 4000,
        'line_total_satang' => $total - 4000,
    ]), 'items')->submitted()->create(['shipping_satang' => 4000]);

    $order->payments()->update(['amount_satang' => $order->total_satang]);

    return [$order->refresh(), $order->payments()->sole(), $variant];
}

beforeEach(function () {
    Notification::fake();
    $this->actingAs(staffUser());
});

test('confirming a full slip confirms the payment, pays the order and notifies', function () {
    [$order, $payment] = submittedOrder();

    app(ConfirmPayment::class)->handle($payment);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Confirmed)
        ->and($payment->confirmed_at)->not->toBeNull()
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull();

    Notification::assertSentOnDemand(PaymentConfirmed::class, fn ($n, $c, AnonymousNotifiable $to) => $to->routes['mail'] === $order->contact_email
        && $n->toMail($to)->actionUrl === $order->signedUrl());
});

test('confirming the gap payment of a short slip marks the order paid', function () {
    [$order, $short] = submittedOrder(total: 100000);
    app(RejectPayment::class)->handle($short, RejectReason::AmountMismatch, slipAmountSatang: 60000);

    $gap = $order->payments()->create([
        'method' => $short->method, 'status' => PaymentStatus::Submitted, 'amount_satang' => 40000,
        'shortfall_of_payment_id' => $short->id, 'slip_path' => 'slips/x.jpg', 'submitted_at' => now(),
    ]);

    app(ConfirmPayment::class)->handle($gap);

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

test('a confirmed payment that leaves the order short keeps it awaiting payment', function () {
    [$order, $payment] = submittedOrder();
    $payment->update(['amount_satang' => 50000]);

    app(ConfirmPayment::class)->handle($payment);

    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($order->paid_at)->toBeNull();
});

test('a short slip is rejected with the two line reason and never confirmed', function () {
    [$order, $payment] = submittedOrder(total: 1935000);

    app(RejectPayment::class)->handle($payment, RejectReason::AmountMismatch, slipAmountSatang: 1930000);

    $payment->refresh();

    expect($payment->status)->toBe(PaymentStatus::Rejected)
        ->and($payment->slip_amount_satang)->toBe(1930000)
        ->and($payment->rejected_reason)->toBe("ยอดในสลิปไม่ตรง\nสลิปโอน ฿ 19,300 · ยอดที่ต้องชำระ ฿ 19,350 (ขาด ฿ 50)")
        ->and($order->refresh()->receivedSatang())->toBe(1930000);

    Notification::assertSentOnDemand(PaymentRejected::class, fn ($n, $c, $to) => str_contains(implode(' ', $n->toMail($to)->introLines), 'ยอดในสลิปไม่ตรง'));
});

test('rejecting extends the payment deadline to a full window from now', function () {
    $this->travelTo(now()->startOfMinute());
    [$order, $payment] = submittedOrder();
    $order->update(['payment_due_at' => now()->subMinutes(5)]);

    app(RejectPayment::class)->handle($payment, RejectReason::Unreadable);

    expect($order->refresh()->payment_due_at->equalTo(now()->addMinutes((int) config('shop.payment_window_minutes'))))->toBeTrue()
        ->and($order->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($payment->refresh()->rejected_reason)->toBe(__('admin.payment.reasons.unreadable'))
        ->and($payment->slip_amount_satang)->toBeNull();
});

test('reject inputs are checked for the chosen reason', function () {
    [, $payment] = submittedOrder(total: 100000);

    foreach ([
        [RejectReason::AmountMismatch, null, null],
        [RejectReason::AmountMismatch, null, 0],
        [RejectReason::AmountMismatch, null, 100000],
        [RejectReason::Other, '  ', null],
    ] as [$reason, $note, $slip]) {
        expect(fn () => app(RejectPayment::class)->handle($payment, $reason, $note, $slip))->toThrow(InvalidArgumentException::class);
    }

    expect($payment->refresh()->status)->toBe(PaymentStatus::Submitted);
});

test('a free text reason is stored verbatim, and a slip amount with another reason is not credited', function () {
    [$order, $payment] = submittedOrder();

    app(RejectPayment::class)->handle($payment, RejectReason::Other, 'โอนผิดวัน', slipAmountSatang: 5000);

    expect($payment->refresh()->rejected_reason)->toBe('โอนผิดวัน')
        ->and($payment->slip_amount_satang)->toBeNull()
        ->and($order->receivedSatang())->toBe(0);
});

test('a slip reference already credited on another payment of the order is refused', function () {
    [$order, $first] = submittedOrder(total: 100000);
    app(ConfirmPayment::class)->handle($first, ' ab12 ');
    expect($first->refresh()->slip_ref)->toBe('AB12');

    $order->refresh()->update(['status' => OrderStatus::AwaitingPayment, 'paid_at' => null]);
    $second = $order->payments()->create(['method' => $first->method, 'status' => PaymentStatus::Submitted, 'amount_satang' => 1000, 'slip_path' => 'slips/y.jpg', 'submitted_at' => now()]);

    expect(fn () => app(ConfirmPayment::class)->handle($second, 'ab12'))->toThrow(SlipRefAlreadyCredited::class);
    expect(fn () => app(RejectPayment::class)->handle($second, RejectReason::AmountMismatch, slipAmountSatang: 500, slipRef: 'AB12'))->toThrow(SlipRefAlreadyCredited::class);

    expect($second->refresh()->status)->toBe(PaymentStatus::Submitted);

    app(ConfirmPayment::class)->handle($second, 'CD34');
    expect($second->refresh()->status)->toBe(PaymentStatus::Confirmed);
});

test('a short slip reference counts as credited too', function () {
    [$order, $short] = submittedOrder(total: 100000);
    app(RejectPayment::class)->handle($short, RejectReason::AmountMismatch, slipAmountSatang: 60000, slipRef: 'ref-1');
    $gap = $order->payments()->create(['method' => $short->method, 'status' => PaymentStatus::Submitted, 'amount_satang' => 40000, 'shortfall_of_payment_id' => $short->id, 'slip_path' => 'slips/z.jpg', 'submitted_at' => now()]);

    expect(fn () => app(ConfirmPayment::class)->handle($gap, 'REF-1'))->toThrow(SlipRefAlreadyCredited::class);
});

test('a payment that is no longer submitted, or an order that is closed, refuses confirm and reject', function () {
    [$order, $payment] = submittedOrder();
    app(RejectPayment::class)->handle($payment, RejectReason::Unreadable);
    app(CancelOrder::class)->handle($order->refresh(), 'ยกเลิก');

    expect(fn () => app(ConfirmPayment::class)->handle($payment))->toThrow(PaymentRefused::class);
    expect(fn () => app(RejectPayment::class)->handle($payment, RejectReason::Unreadable))->toThrow(PaymentRefused::class);

    [$closed, $other] = submittedOrder();
    $closed->update(['status' => OrderStatus::Paid]);

    expect(fn () => app(ConfirmPayment::class)->handle($other))->toThrow(PaymentRefused::class);
});

test('every transition is legal only from its own status', function () {
    $legal = [
        PackOrder::class => [OrderStatus::Paid, fn ($a, Order $o) => $a->handle($o)],
        ShipOrder::class => [OrderStatus::Packing, fn ($a, Order $o) => $a->handle($o, 'TH123')],
        DeliverOrder::class => [OrderStatus::Shipped, fn ($a, Order $o) => $a->handle($o)],
        RefundOrder::class => [OrderStatus::Paid, fn ($a, Order $o) => $a->handle($o, 'เหตุผล')],
    ];

    foreach ($legal as $class => [$from, $call]) {
        foreach (OrderStatus::cases() as $status) {
            $order = Order::factory()->create(['status' => $status, 'paid_at' => now()]);

            if ($status === $from || ($class === RefundOrder::class && $status === OrderStatus::Packing)) {
                $call(app($class), $order);
                expect($order->refresh()->status)->not->toBe($status);

                continue;
            }

            expect(fn () => $call(app($class), $order))->toThrow(IllegalOrderTransition::class);
            expect($order->refresh()->status)->toBe($status);
        }
    }
});

test('pack and deliver stamp their timestamps', function () {
    $order = Order::factory()->paid()->create();
    app(PackOrder::class)->handle($order);
    expect($order->refresh()->status)->toBe(OrderStatus::Packing)->and($order->packed_at)->not->toBeNull();

    app(ShipOrder::class)->handle($order, 'TH1');
    app(DeliverOrder::class)->handle($order);
    expect($order->refresh()->status)->toBe(OrderStatus::Delivered)->and($order->delivered_at)->not->toBeNull();
});

test('shipping writes tracking, status and shipped_at in one transaction and notifies with the tracking link', function () {
    $order = Order::factory()->packing()->create();

    $log = [];
    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    app(ShipOrder::class)->handle($order, '  TH999  ');

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Shipped)
        ->and($order->tracking_number)->toBe('TH999')
        ->and($order->shipped_at)->not->toBeNull()
        ->and(array_search('BEGIN', $log, true))->toBeLessThan(array_key_last($log));

    Notification::assertSentOnDemand(OrderShipped::class, fn ($n, $c, $to) => str_contains(implode(' ', $n->toMail($to)->introLines), $order->courier->trackingUrl('TH999')));
});

test('shipping needs a tracking number and a failed ship writes nothing', function () {
    $order = Order::factory()->packing()->create();

    expect(fn () => app(ShipOrder::class)->handle($order, '   '))->toThrow(InvalidArgumentException::class);

    expect($order->refresh()->status)->toBe(OrderStatus::Packing);
    Notification::assertNothingSent();
});

test('refunding returns the quantity, refunds confirmed payments and reports what was received', function () {
    [$order, $payment, $variant] = submittedOrder(total: 100000, variantQty: 1, qty: 2);
    app(ConfirmPayment::class)->handle($payment);

    $result = app(RefundOrder::class)->handle($order->refresh(), 'ลูกค้าขอคืน');

    expect($variant->refresh()->qty)->toBe(3)
        ->and($result->refundedSatang)->toBe(100000)
        ->and($result->order->status)->toBe(OrderStatus::Refunded)
        ->and($order->refresh()->refunded_at)->not->toBeNull()
        ->and($order->refund_reason)->toBe('ลูกค้าขอคืน')
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Refunded);

    Notification::assertSentOnDemand(OrderRefunded::class, fn (OrderRefunded $n) => $n->refundedSatang === 100000);
});

test('a refund returns what was received, which includes a short slip that was credited', function () {
    [$order, $short] = submittedOrder(total: 100000);
    app(RejectPayment::class)->handle($short, RejectReason::AmountMismatch, slipAmountSatang: 60000);
    $gap = $order->payments()->create(['method' => $short->method, 'status' => PaymentStatus::Submitted, 'amount_satang' => 40000, 'shortfall_of_payment_id' => $short->id, 'slip_path' => 'slips/g.jpg', 'submitted_at' => now()]);
    app(ConfirmPayment::class)->handle($gap);

    $result = app(RefundOrder::class)->handle($order->refresh(), 'คืน');

    expect($result->refundedSatang)->toBe(100000)
        ->and($result->refundedSatang)->not->toBe(40000)
        ->and($order->refresh()->refunded_satang)->toBe(100000)
        ->and($order->receivedSatang())->toBe(0);
});

test('refund needs a reason and locks the variants first', function () {
    $order = Order::factory()->paid()->create();
    expect(fn () => app(RefundOrder::class)->handle($order, ' '))->toThrow(InvalidArgumentException::class);

    $log = [];
    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function ($query) use (&$log): void {
        $log[] = $query->sql;
    });
    app(RefundOrder::class)->handle($order, 'คืน');

    $begin = array_search('BEGIN', $log, true);
    $after = array_slice($log, $begin + 1);
    $firstRead = collect($after)->first(fn (string $sql) => str_starts_with($sql, 'select'));

    expect($firstRead)->toContain('variants');
});

test('the slip route streams to staff only', function () {
    Storage::fake('local');
    [, $payment] = submittedOrder();
    Storage::disk('local')->put('slips/x/slip.jpg', 'jpegbytes');
    $payment->update(['slip_path' => 'slips/x/slip.jpg']);

    $this->get(route('admin.slip', $payment))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    Storage::disk('local')->put('slips/x/slip.heic', 'heicbytes');
    $payment->update(['slip_path' => 'slips/x/slip.heic']);

    expect($this->get(route('admin.slip', $payment))->headers->get('Content-Disposition'))->toContain('attachment');

    $payment->update(['slip_path' => 'slips/x/missing.jpg']);
    $this->get(route('admin.slip', $payment))->assertNotFound();
});

test('the slip route is forbidden to customers and refused to guests', function () {
    Storage::fake('local');
    [, $payment] = submittedOrder();
    Storage::disk('local')->put('slips/x/slip.jpg', 'jpegbytes');
    $payment->update(['slip_path' => 'slips/x/slip.jpg']);

    $this->actingAs(User::factory()->create())->get(route('admin.slip', $payment))->assertForbidden();

    auth()->logout();
    app('auth')->forgetGuards();

    $this->get(route('admin.slip', $payment))->assertRedirect();
});

test('the payment desk lists only submitted payments, oldest first', function () {
    [, $newer] = submittedOrder();
    [, $older] = submittedOrder();
    $newer->update(['submitted_at' => now()]);
    $older->update(['submitted_at' => now()->subHour()]);
    Payment::factory()->pending()->create();
    Payment::factory()->confirmed()->create();

    $ids = Livewire::test(PaymentDesk::class)->assertOk()->instance()->submittedPayments()->pluck('id')->all();

    expect($ids)->toBe([$older->id, $newer->id]);
});

test('the desk confirms and rejects through its actions', function () {
    [$order, $payment] = submittedOrder();
    [$otherOrder, $other] = submittedOrder();

    Livewire::test(PaymentDesk::class)
        ->callAction('confirm', ['slip_ref' => 'R1'], ['payment' => $payment->id])
        ->assertHasNoActionErrors()
        ->callAction('reject', ['reason' => 'amount_mismatch', 'slip_amount' => '100'], ['payment' => $other->id])
        ->assertHasNoActionErrors();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Confirmed)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($other->refresh()->status)->toBe(PaymentStatus::Rejected)
        ->and($other->slip_amount_satang)->toBe(10000)
        ->and($other->rejected_reason)->toContain('ยอดในสลิปไม่ตรง');
});

test('the desk refuses a duplicate slip reference without confirming', function () {
    [$order, $first] = submittedOrder(total: 100000);
    $first->update(['amount_satang' => 50000]);
    app(ConfirmPayment::class)->handle($first, 'DUP');
    $second = $order->payments()->create(['method' => $first->method, 'status' => PaymentStatus::Submitted, 'amount_satang' => 50000, 'slip_path' => 'slips/d.jpg', 'submitted_at' => now()]);

    Livewire::test(PaymentDesk::class)
        ->callAction('confirm', ['slip_ref' => 'dup'], ['payment' => $second->id]);

    expect($second->refresh()->status)->toBe(PaymentStatus::Submitted);
});

test('the desk preview shows the prefilled reason and the modal rejects a slip that is not short', function () {
    [, $payment] = submittedOrder(total: 1935000);

    Livewire::test(PaymentDesk::class)
        ->mountAction('reject', ['payment' => $payment->id])
        ->assertMountedActionModalSee('กรอกยอดในสลิป')
        ->setActionData(['reason' => 'amount_mismatch', 'slip_amount' => '19300'])
        ->assertMountedActionModalSee('สลิปโอน ฿ 19,300 · ยอดที่ต้องชำระ ฿ 19,350 (ขาด ฿ 50)')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($payment->refresh()->slip_amount_satang)->toBe(1930000);

    [, $second] = submittedOrder(total: 100000);

    Livewire::test(PaymentDesk::class)
        ->callAction('reject', ['reason' => 'amount_mismatch', 'slip_amount' => '1000'], ['payment' => $second->id])
        ->assertHasActionErrors(['slip_amount']);

    expect($second->refresh()->status)->toBe(PaymentStatus::Submitted);
});

test('the pages render and a customer cannot reach them', function () {
    [$order] = submittedOrder();

    Livewire::test(ListOrders::class)->assertOk()->assertCanSeeTableRecords([$order]);
    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertOk();

    $this->get(OrderResource::getUrl('view', ['record' => $order]))->assertOk();
    $this->get(PaymentDesk::getUrl())->assertOk();
    $this->get('/admin')->assertOk()->assertDontSee('Filament v');

    $this->actingAs(User::factory()->create());
    $this->get(OrderResource::getUrl('index'))->assertForbidden();
    $this->get(PaymentDesk::getUrl())->assertForbidden();
});

test('the order list filters by status and the widgets count what they promise', function () {
    Order::factory()->paid()->create();
    Order::factory()->paid()->create();
    Order::factory()->create();
    Payment::factory()->submitted()->create();

    Livewire::test(ListOrders::class)
        ->filterTable('status', 'paid')
        ->assertCountTableRecords(2);

    $stat = fn (string $widget) => trim(preg_replace('/\s+/', ' ', strip_tags(Livewire::test($widget)->assertOk()->html())));

    expect($stat(PendingSlipsWidget::class))->toBe(__('admin.widgets.pending_slips').' 1')
        ->and($stat(OrdersToPackWidget::class))->toBe(__('admin.widgets.orders_to_pack').' 2')
        ->and(Livewire::test(OrdersToPackWidget::class)->html())->toContain('filters');
});

test('the view page shows only the legal buttons', function () {
    [$awaiting] = submittedOrder();
    $paid = Order::factory()->paid()->create();
    $packing = Order::factory()->packing()->create();
    $shipped = Order::factory()->shipped()->create();
    $cancelled = Order::factory()->cancelled()->create();

    $page = fn (Order $o) => Livewire::test(ViewOrder::class, ['record' => $o->getRouteKey()]);

    $page($awaiting)->assertActionVisible('confirmPayment')->assertActionVisible('rejectPayment')->assertActionHidden('cancel')->assertActionHidden('pack')->assertActionHidden('refund');
    $page($paid)->assertActionVisible('pack')->assertActionVisible('refund')->assertActionVisible('cancel')->assertActionHidden('ship')->assertActionHidden('confirmPayment');
    $page($packing)->assertActionVisible('ship')->assertActionVisible('refund')->assertActionHidden('pack')->assertActionHidden('cancel');
    $page($shipped)->assertActionVisible('deliver')->assertActionHidden('refund')->assertActionHidden('cancel');
    $page($cancelled)->assertActionHidden('pack')->assertActionHidden('cancel')->assertActionHidden('refund');
});

test('the view page actions run', function () {
    $order = Order::factory()->paid()->create();

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->callAction('pack')
        ->assertHasNoActionErrors()
        ->callAction('ship', ['tracking_number' => 'TH777'])
        ->assertHasNoActionErrors()
        ->callAction('deliver')
        ->callAction('editNotes', ['tracking_number' => 'TH778', 'staff_note' => 'โน้ต']);

    expect($order->refresh()->status)->toBe(OrderStatus::Delivered)
        ->and($order->tracking_number)->toBe('TH778')
        ->and($order->staff_note)->toBe('โน้ต');
});

test('cancelling an order with money in shows staff the amount to return', function () {
    [$order, $short] = submittedOrder(total: 100000);
    app(RejectPayment::class)->handle($short, RejectReason::AmountMismatch, slipAmountSatang: 60000);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->mountAction('cancel')
        ->assertMountedActionModalSee('ต้องคืนเงินให้ลูกค้า ฿ 600')
        ->setActionData(['reason' => 'ลูกค้าขอยกเลิก'])
        ->callMountedAction()
        ->assertNotified();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and((new OrderCancelled($order))->toMail($order)->render()->toHtml())
        ->toContain(__('notifications.order_cancelled.refund_line', ['amount' => '฿ 600']));
});

test('the cancel email of an order with no money in says nothing about a refund', function () {
    $order = Order::factory()->create();

    expect((new OrderCancelled($order))->toMail($order)->render()->toHtml())
        ->not->toContain('จะโอนคืนให้');
});

test('refunding shows the received amount in the modal and the notification', function () {
    [$order, $payment] = submittedOrder(total: 100000);
    app(ConfirmPayment::class)->handle($payment);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->mountAction('refund')
        ->assertMountedActionModalSee('ต้องคืนเงินให้ลูกค้า ฿ 1,000')
        ->setActionData(['reason' => 'คืน'])
        ->callMountedAction()
        ->assertNotified(
            Filament\Notifications\Notification::make()->success()->title(__('admin.orders.done.refund'))
                ->body(__('admin.orders.return_money', ['amount' => '฿ 1,000']))->persistent(),
        );

    expect($order->refresh()->status)->toBe(OrderStatus::Refunded);
});

test('cancel is refused while a slip is submitted, under the lock, and nothing moves', function () {
    [$order, $payment, $variant] = submittedOrder(variantQty: 1, qty: 1);

    expect(fn () => app(CancelOrder::class)->handle($order, 'ยกเลิก'))->toThrow(PaymentRefused::class, 'slip_submitted');

    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Submitted)
        ->and($variant->refresh()->qty)->toBe(1);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('cancel');
});

test('the unpaid-orders job leaves an overdue order that holds a short slip alone', function () {
    [$order, $payment, $variant] = submittedOrder(variantQty: 0);
    app(RejectPayment::class)->handle($payment, RejectReason::AmountMismatch, slipAmountSatang: 60000);
    $order->refresh()->update(['payment_due_at' => now()->subMinute()]);

    [$plain] = submittedOrder(variantQty: 0);
    $plain->payments()->update(['status' => PaymentStatus::Pending, 'submitted_at' => null]);
    $plain->update(['payment_due_at' => now()->subMinute()]);

    app()->call([new CancelUnpaidOrders, 'handle']);

    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($plain->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($variant->refresh()->qty)->toBe(0);
});

test('the overdue-with-money filter and widget count only overdue orders with a short slip', function () {
    [$short, $payment] = submittedOrder();
    app(RejectPayment::class)->handle($payment, RejectReason::AmountMismatch, slipAmountSatang: 60000);
    $short->refresh()->update(['payment_due_at' => now()->subMinute()]);

    [$notYet, $second] = submittedOrder();
    app(RejectPayment::class)->handle($second, RejectReason::AmountMismatch, slipAmountSatang: 60000);

    [, $unreadable] = submittedOrder();
    app(RejectPayment::class)->handle($unreadable, RejectReason::Unreadable);
    $unreadable->order->update(['payment_due_at' => now()->subMinute()]);

    Livewire::test(ListOrders::class)
        ->filterTable('overdue_with_money')
        ->assertCanSeeTableRecords([$short])
        ->assertCanNotSeeTableRecords([$notYet]);

    expect(Order::query()->overdueWithShortSlip()->count())->toBe(1);

    $text = trim(preg_replace('/\s+/', ' ', strip_tags(Livewire::test(ShortfallOrdersWidget::class)->assertOk()->html())));
    expect($text)->toBe(__('admin.widgets.shortfall_orders').' 1');
});

test('a refund leaves the order showing what was returned and nothing received', function () {
    [$order, $payment] = submittedOrder(total: 100000);
    app(ConfirmPayment::class)->handle($payment);

    app(RefundOrder::class)->handle($order->refresh(), 'คืน');

    $order->refresh();
    expect($order->refunded_satang)->toBe(100000)
        ->and($order->receivedSatang())->toBe(0);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertOk()->assertSee('฿ 1,000');
});

test('packing and delivering tell the customer with the signed link', function () {
    $order = Order::factory()->paid()->create();

    app(PackOrder::class)->handle($order);
    Notification::assertSentOnDemand(OrderPacked::class, fn ($n, $c, $to) => $n->toMail($to)->actionUrl === $order->signedUrl());

    $order->update(['status' => OrderStatus::Shipped]);
    app(DeliverOrder::class)->handle($order);
    Notification::assertSentOnDemand(OrderDelivered::class, fn ($n, $c, $to) => $n->toMail($to)->actionUrl === $order->signedUrl());
});

test('every order and payment notification is queued after commit', function () {
    foreach (glob(app_path('Notifications/*.php')) as $file) {
        $class = 'App\\Notifications\\'.basename($file, '.php');

        expect(is_subclass_of($class, ShouldQueueAfterCommit::class))->toBeTrue($class);
    }
});

test('the seeded waiting slip is a real image on the private disk and no timestamp precedes placed_at', function () {
    Storage::fake('local');
    foreach (range(1, 7) as $unused) {
        [, , $variant] = submittedOrder(variantQty: 5);
        $variant->update(['qty' => 5]);
    }
    Order::query()->delete();

    app()['env'] = 'local';
    app(OrderSeeder::class)->run();

    $payment = Payment::query()->where('status', PaymentStatus::Submitted->value)->sole();
    expect(Storage::disk('local')->exists($payment->slip_path))->toBeTrue()
        ->and(getimagesizefromstring(Storage::disk('local')->get($payment->slip_path))[2])->toBe(IMAGETYPE_PNG);

    foreach (Order::query()->get() as $order) {
        foreach (['paid_at', 'packed_at', 'shipped_at', 'delivered_at', 'cancelled_at'] as $column) {
            if ($order->{$column} !== null) {
                expect($order->{$column}->gte($order->placed_at))->toBeTrue("{$order->number} {$column}");
            }
        }
    }
});
