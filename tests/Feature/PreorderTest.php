<?php

require_once __DIR__.'/../Support/preorder.php';

use App\Actions\CancelOrder;
use App\Actions\PackOrder;
use App\Actions\PayShortfall;
use App\Actions\PlacePreorder;
use App\Actions\RefundOrder;
use App\Actions\RejectPayment;
use App\Actions\RequestPreorderBalance;
use App\Actions\ReschedulePreorderBalanceDeadlines;
use App\Actions\SubmitSlip;
use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PreorderPlan;
use App\Enums\RejectReason;
use App\Exceptions\PreorderNotReleased;
use App\Exceptions\PreorderRefused;
use App\Jobs\CancelUnpaidOrders;
use App\Jobs\RequestPreorderBalances;
use App\Jobs\RescheduleBalanceDeadlinesJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentConfirmed;
use App\Notifications\PreorderBalanceDue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

afterEach(fn () => gc_collect_cycles());

beforeEach(function () {
    Notification::fake();
    config(['shop.promptpay_id' => '0812345678']);
});

test('a deposit order asks for the deposit now, owes the rest later, takes a place and no stock', function () {
    $preorder = openPreorder();

    $order = placePreorder($preorder, qty: 2);

    $payment = $order->payments()->firstOrFail();

    expect($order->kind)->toBe(OrderKind::Preorder)
        ->and($order->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($order->preorder_plan)->toBe(PreorderPlan::Deposit)
        ->and($order->preorder_id)->toBe($preorder->id)
        ->and($order->subtotal_satang)->toBe(560000)
        ->and($order->total_satang)->toBe(560000 + 4000)
        ->and($order->deposit_satang)->toBe(100000)
        ->and($order->balance_due_satang)->toBe(564000 - 100000)
        ->and($payment->amount_satang)->toBe(100000)
        ->and($payment->qr_payload)->not->toBeNull()
        ->and($order->amountDueNowSatang())->toBe(100000)
        ->and($order->items()->firstOrFail()->kind)->toBe('sealed')
        ->and($order->items()->firstOrFail()->qty)->toBe(2)
        ->and($preorder->fresh()->reserved_count)->toBe(2)
        ->and($preorder->sealedProduct->fresh()->qty)->toBe(0);
});

test('the full plan charges the discounted total in one payment', function () {
    $preorder = openPreorder(['full_discount_bp' => 200]);

    $order = placePreorder($preorder, qty: 2, plan: PreorderPlan::Full);

    // 2,800.00 less 2% is 2,744.00 per box.
    expect($order->items()->firstOrFail()->unit_price_satang)->toBe(274400)
        ->and($order->subtotal_satang)->toBe(548800)
        ->and($order->total_satang)->toBe(548800 + 4000)
        ->and($order->balance_due_satang)->toBe(0)
        ->and($order->payments()->firstOrFail()->amount_satang)->toBe(552800);
});

test('the discount is rounded down to a satang', function () {
    expect(openPreorder(['full_satang' => 100001, 'full_discount_bp' => 250])->fullPlanUnitSatang())->toBe(100001 - 2500);
});

test('a second attempt is refused once the quota is taken, and nothing is written for it', function () {
    $preorder = openPreorder(['quota' => 2, 'per_account_limit' => 5]);

    placePreorder($preorder, qty: 2);

    expect(fn () => placePreorder($preorder, qty: 1))->toThrow(PreorderRefused::class)
        ->and(Order::query()->count())->toBe(1)
        ->and($preorder->fresh()->reserved_count)->toBe(2);
});

test('asking for more than the places left is refused as full, not clamped', function () {
    $preorder = openPreorder(['quota' => 3, 'per_account_limit' => 5, 'reserved_count' => 2]);

    try {
        placePreorder($preorder, qty: 2);
        $this->fail('Expected a refusal.');
    } catch (PreorderRefused $refused) {
        expect($refused->reason)->toBe('full')->and($refused->available)->toBe(1);
    }

    expect($preorder->fresh()->reserved_count)->toBe(2);
});

test('the per-account limit counts what the account already holds and ignores cancelled orders', function () {
    $preorder = openPreorder(['per_account_limit' => 2, 'quota' => 50]);
    $user = User::factory()->create();

    $first = placePreorder($preorder, $user, qty: 2);

    try {
        placePreorder($preorder, $user, qty: 1);
        $this->fail('Expected a refusal.');
    } catch (PreorderRefused $refused) {
        expect($refused->reason)->toBe('limit');
    }

    // Another account is not held to this one's orders.
    placePreorder($preorder, User::factory()->create(), qty: 2);

    app(CancelOrder::class)->handle($first, 'test');

    placePreorder($preorder, $user, qty: 2);

    expect($preorder->fresh()->reserved_count)->toBe(4);
});

test('a pre-order that is closed, not open yet, released or full refuses', function (array $state, string $reason) {
    $preorder = openPreorder($state);

    try {
        placePreorder($preorder);
        $this->fail('Expected a refusal.');
    } catch (PreorderRefused $refused) {
        expect($refused->reason)->toBe($reason);
    }

    expect(Order::query()->count())->toBe(0);
})->with([
    'closed' => [['is_open' => false], 'closed'],
    'not open yet' => [fn () => ['opens_at' => now()->addDay()], 'not_yet'],
    'released today' => [fn () => ['release_on' => now(config('shop.timezone'))->toDateString()], 'released'],
    'released' => [fn () => ['release_on' => now()->subDay()->toDateString()], 'released'],
    'full' => [['quota' => 5, 'reserved_count' => 5], 'full'],
]);

test('a product that is inactive or no longer marked as a pre-order refuses as unavailable', function (array $change) {
    $preorder = openPreorder();
    $preorder->sealedProduct->update($change);

    try {
        placePreorder($preorder);
        $this->fail('Expected a refusal.');
    } catch (PreorderRefused $refused) {
        expect($refused->reason)->toBe('unavailable');
    }

    expect(Order::query()->count())->toBe(0)->and($preorder->fresh()->reserved_count)->toBe(0);
})->with([
    'inactive' => [['is_active' => false]],
    'not a pre-order' => [['is_preorder' => false]],
]);

test('the limit message says how many more the account can order, and the full one how many places are left', function () {
    $preorder = openPreorder(['per_account_limit' => 2, 'quota' => 50]);
    $user = User::factory()->create();
    placePreorder($preorder, $user);

    try {
        placePreorder($preorder, $user, qty: 2);
        $this->fail('Expected a refusal.');
    } catch (PreorderRefused $refused) {
        expect($refused->reason)->toBe('limit')
            ->and($refused->message())->toContain('สั่งได้อีก 1 กล่อง')
            ->and($refused->message())->not->toContain('ครบจำนวน');
    }

    placePreorder($preorder, $user);

    try {
        placePreorder($preorder, $user);
        $this->fail('Expected a refusal.');
    } catch (PreorderRefused $refused) {
        expect($refused->message())->toContain('ครบจำนวนต่อบัญชีแล้ว');
    }

    $short = openPreorder(['quota' => 3, 'reserved_count' => 2, 'per_account_limit' => 5]);

    try {
        placePreorder($short, qty: 2);
        $this->fail('Expected a refusal.');
    } catch (PreorderRefused $refused) {
        expect($refused->message())->toContain('เหลือที่จองอีก 1 กล่อง');
    }
});

test('the pre-order row is the first thing the transaction locks', function () {
    $preorder = openPreorder();
    $user = User::factory()->create();
    $log = [];

    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    app(PlacePreorder::class)->handle($preorder, $user, gradedCheckoutData(), 1, PreorderPlan::Deposit);

    $begin = array_search('BEGIN', $log, true);

    expect($begin)->not->toBeFalse()
        ->and($log[$begin + 1])->toStartWith('select * from "preorders" where "preorders"."id" = ?');
});

test('confirming the deposit moves the order to awaiting balance, and the balance moves it to paid', function () {
    $order = placePreorder(openPreorder());

    $order = confirmLatestPayment($order);

    expect($order->status)->toBe(OrderStatus::AwaitingBalance)
        ->and($order->paid_at)->toBeNull()
        ->and($order->receivedSatang())->toBe(50000)
        ->and($order->remainingSatang())->toBe($order->balance_due_satang);

    $order->payments()->create(['method' => 'promptpay', 'status' => PaymentStatus::Pending, 'amount_satang' => $order->balance_due_satang]);
    $order->update(['balance_requested_at' => now(), 'payment_due_at' => now()->addDays(3)]);

    $order = confirmLatestPayment($order);

    expect($order->status)->toBe(OrderStatus::Paid)->and($order->paid_at)->not->toBeNull();
});

test('a short deposit slip leaves the order waiting for the deposit and asks only for the gap', function () {
    $order = placePreorder(openPreorder());
    $payment = $order->payments()->firstOrFail();
    $payment->update(['status' => PaymentStatus::Submitted, 'slip_path' => 'slips/x.jpg', 'submitted_at' => now()]);

    app(RejectPayment::class)->handle($payment, RejectReason::AmountMismatch, slipAmountSatang: 30000);

    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($order->amountDueNowSatang())->toBe(20000);

    $gap = app(PayShortfall::class)->handle($payment->refresh());

    expect($gap->amount_satang)->toBe(20000);

    $order = confirmLatestPayment($order);

    expect($order->status)->toBe(OrderStatus::AwaitingBalance);
});

test('the full plan goes straight to paid', function () {
    $order = confirmLatestPayment(placePreorder(openPreorder(), plan: PreorderPlan::Full));

    expect($order->status)->toBe(OrderStatus::Paid);
});

test('the balance job asks once, seven days out, with a payment and a message', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(7)->toDateString(), 'cancel_until' => now()->addDays(3)->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder));

    (new RequestPreorderBalances)->handle();
    (new RequestPreorderBalances)->handle();

    $order->refresh();
    $balance = $order->payments()->latest('id')->firstOrFail();

    expect($order->balance_requested_at)->not->toBeNull()
        ->and($order->payments()->count())->toBe(2)
        ->and($balance->status)->toBe(PaymentStatus::Pending)
        ->and($balance->amount_satang)->toBe($order->balance_due_satang)
        ->and($balance->qr_payload)->not->toBeNull()
        ->and($order->payment_due_at->format('Y-m-d H:i:s'))->toBe($preorder->refresh()->releaseDayEnd()->utc()->format('Y-m-d H:i:s'))
        ->and($order->status)->toBe(OrderStatus::AwaitingBalance);

    Notification::assertSentOnDemandTimes(PreorderBalanceDue::class, 1);
});

test('the balance job leaves alone an order eight days out, one without its deposit and one already paid', function () {
    $far = confirmLatestPayment(placePreorder(openPreorder(['release_on' => now(config('shop.timezone'))->addDays(8)->toDateString()])));
    $unpaid = placePreorder(openPreorder(['release_on' => now(config('shop.timezone'))->addDays(3)->toDateString()]));
    $full = confirmLatestPayment(placePreorder(openPreorder(['release_on' => now(config('shop.timezone'))->addDays(3)->toDateString()]), plan: PreorderPlan::Full));

    (new RequestPreorderBalances)->handle();

    expect($far->refresh()->balance_requested_at)->toBeNull()
        ->and($unpaid->refresh()->balance_requested_at)->toBeNull()
        ->and($full->refresh()->balance_requested_at)->toBeNull()
        ->and(Payment::query()->count())->toBe(3);

    Notification::assertSentOnDemandTimes(PreorderBalanceDue::class, 0);
});

test('the balance payment is paid on the same panel actions as any payment', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(4)->toDateString(), 'cancel_until' => now()->addDays(2)->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder));
    (new RequestPreorderBalances)->handle();

    Storage::fake('local');
    $payment = $order->payments()->latest('id')->firstOrFail();

    app(SubmitSlip::class)->handle($payment, UploadedFile::fake()->image('slip.jpg'));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Submitted);
});

test('cancelling before cancel_until owes everything received and returns the places, not stock', function () {
    $preorder = openPreorder(['cancel_until' => now(config('shop.timezone'))->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder, qty: 2));

    $cancelled = app(CancelOrder::class)->handle($order, 'changed my mind');

    expect($cancelled->status)->toBe(OrderStatus::Cancelled)
        ->and($cancelled->refundOwedSatang())->toBe(100000)
        ->and($preorder->fresh()->reserved_count)->toBe(0)
        ->and($preorder->sealedProduct->fresh()->qty)->toBe(0);
});

test('cancelling after cancel_until keeps the deposit', function () {
    $preorder = openPreorder(['cancel_until' => now(config('shop.timezone'))->subDay()->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder, qty: 2));

    // The deposit is all that was received: nothing is owed back.
    expect($order->refundOwedSatang())->toBe(0);

    $order->payments()->create(['method' => 'promptpay', 'status' => PaymentStatus::Confirmed, 'amount_satang' => 30000, 'confirmed_at' => now()]);

    expect($order->refundOwedSatang())->toBe(30000);

    app(CancelOrder::class)->handle($order, 'x');

    expect($preorder->fresh()->reserved_count)->toBe(0);
});

test('refunding a paid pre-order returns its places and applies the same deadline', function () {
    $before = openPreorder(['cancel_until' => now(config('shop.timezone'))->toDateString()]);
    $after = openPreorder(['cancel_until' => now(config('shop.timezone'))->subDay()->toDateString()]);
    $early = confirmLatestPayment(placePreorder($before, plan: PreorderPlan::Full));
    $late = confirmLatestPayment(placePreorder($after, plan: PreorderPlan::Full));

    $earlyResult = app(RefundOrder::class)->handle($early, 'x');
    $lateResult = app(RefundOrder::class)->handle($late, 'x');

    expect($earlyResult->refundedSatang)->toBe($early->total_satang)
        ->and($lateResult->refundedSatang)->toBe($late->total_satang - 50000)
        ->and($before->fresh()->reserved_count)->toBe(0)
        ->and($after->fresh()->reserved_count)->toBe(0);
});

test('an unpaid deposit is cancelled after its window and the place is returned', function () {
    $preorder = openPreorder();
    $order = placePreorder($preorder);
    $order->update(['payment_due_at' => now()->subMinute()]);

    (new CancelUnpaidOrders)->handle(app(CancelOrder::class));

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($preorder->fresh()->reserved_count)->toBe(0);
});

test('an order waiting for its balance is never cancelled automatically', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(2)->toDateString(), 'cancel_until' => now()->addDay()->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder));
    (new RequestPreorderBalances)->handle();
    $order->refresh()->update(['payment_due_at' => now()->subDay()]);

    (new CancelUnpaidOrders)->handle(app(CancelOrder::class));

    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingBalance)
        ->and($preorder->fresh()->reserved_count)->toBe(1)
        ->and(Order::query()->overdueBalance()->count())->toBe(1);
});

test('the timeline of a deposit order shows the deposit step, reached once the deposit is confirmed', function () {
    $order = placePreorder(openPreorder());

    $before = collect($order->timeline())->pluck('at', 'key');

    expect($before->keys()->all())->toBe(['placed', 'deposit', 'paid', 'packing', 'shipped', 'delivered'])
        ->and($before['deposit'])->toBeNull();

    $after = collect(confirmLatestPayment($order)->timeline())->pluck('at', 'key');

    expect($after['deposit'])->not->toBeNull()->and($after['paid'])->toBeNull();
});

test('a full-plan order has no deposit step', function () {
    expect(collect(placePreorder(openPreorder(), plan: PreorderPlan::Full)->timeline())->pluck('key')->all())->not->toContain('deposit');
});

test('an order keeps the cancel deadline it was placed with, whatever staff change later', function () {
    $preorder = openPreorder(['cancel_until' => now(config('shop.timezone'))->subDay()->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder, plan: PreorderPlan::Full));

    expect($order->cancel_until->format('Y-m-d'))->toBe(now(config('shop.timezone'))->subDay()->toDateString())
        ->and($order->isDepositRefundable())->toBeFalse()
        ->and($order->refundOwedSatang())->toBe($order->total_satang - 50000);

    $preorder->update(['cancel_until' => now(config('shop.timezone'))->addDays(9)->toDateString()]);

    expect($order->refresh()->isDepositRefundable())->toBeFalse()
        ->and($order->refundOwedSatang())->toBe($order->total_satang - 50000);
});

test('a pre-order cannot be packed before its release day, and can on it', function () {
    $early = confirmLatestPayment(placePreorder(openPreorder(['release_on' => now(config('shop.timezone'))->addDays(3)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDay()->toDateString()]), plan: PreorderPlan::Full));
    $ready = confirmLatestPayment(placePreorder(openPreorder(['release_on' => now(config('shop.timezone'))->addDays(3)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDay()->toDateString()]), plan: PreorderPlan::Full));
    $ready->preorder->update(['release_on' => now(config('shop.timezone'))->toDateString()]);

    expect(fn () => app(PackOrder::class)->handle($early))->toThrow(PreorderNotReleased::class)
        ->and($early->refresh()->status)->toBe(OrderStatus::Paid)
        ->and(app(PackOrder::class)->handle($ready->refresh())->status)->toBe(OrderStatus::Packing);
});

test('cancelling and refunding take the pre-order lock before the first statement on payments', function (string $action) {
    $preorder = openPreorder(['cancel_until' => now(config('shop.timezone'))->addDay()->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder, plan: PreorderPlan::Full));
    $log = [];

    DB::listen(function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    $action === 'cancel'
        ? app(CancelOrder::class)->handle($order, 'x')
        : app(RefundOrder::class)->handle($order, 'x');

    $firstPreorder = collect($log)->search(fn (string $sql): bool => str_contains($sql, 'from "preorders"'));
    $firstPayments = collect($log)->search(fn (string $sql): bool => str_contains($sql, '"payments"'));

    expect($firstPreorder)->not->toBeFalse()
        ->and($firstPayments)->not->toBeFalse()
        ->and($firstPreorder)->toBeLessThan($firstPayments)
        ->and($log[$firstPreorder - 1])->toContain('from "orders"');
})->with(['cancel', 'refund']);

test('a balance requested late on the release day gets the rest of today and all of tomorrow', function () {
    // Ten days out when the deposit comes in, so nothing is requested yet.
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(10)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDay()->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder, plan: PreorderPlan::Deposit));

    // The release date is then brought forward to today, and it is ten to midnight: the end of today is minutes away.
    $preorder->update(['release_on' => now(config('shop.timezone'))->toDateString()]);
    $this->travelTo(CarbonImmutable::parse(now(config('shop.timezone'))->format('Y-m-d').' 23:50:00', config('shop.timezone')));

    (new RequestPreorderBalances)->handle();

    $order->refresh();
    $floor = CarbonImmutable::now(config('shop.timezone'))->addDay()->endOfDay()->utc();

    expect($order->balance_requested_at)->not->toBeNull()
        ->and($order->payment_due_at->format('Y-m-d H:i:s'))->toBe($floor->format('Y-m-d H:i:s'))
        ->and($order->payment_due_at->gt(now()->addHours(24)))->toBeTrue();
});

test('a deposit confirmed inside the seven days asks for the balance at once, and only once', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(4)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDays(2)->toDateString()]);

    $order = confirmLatestPayment(placePreorder($preorder));

    expect($order->status)->toBe(OrderStatus::AwaitingBalance)
        ->and($order->balance_requested_at)->not->toBeNull()
        ->and($order->payments()->count())->toBe(2)
        ->and($order->payments()->latest('id')->firstOrFail()->amount_satang)->toBe($order->balance_due_satang);

    (new RequestPreorderBalances)->handle();

    expect($order->payments()->count())->toBe(2);

    Notification::assertSentOnDemandTimes(PreorderBalanceDue::class, 1);
});

test('a deposit confirmed outside the seven days waits for the job', function () {
    $order = confirmLatestPayment(placePreorder(openPreorder(['release_on' => now(config('shop.timezone'))->addDays(9)->toDateString()])));

    expect($order->status)->toBe(OrderStatus::AwaitingBalance)
        ->and($order->balance_requested_at)->toBeNull()
        ->and($order->payments()->count())->toBe(1);

    Notification::assertSentOnDemandTimes(PreorderBalanceDue::class, 0);
});

test('a deadline that is not the end of a day shows its time, one that is shows only the date', function () {
    $order = placePreorder(openPreorder());

    $order->update(['payment_due_at' => CarbonImmutable::parse('2026-12-05 08:30:00', 'UTC')]);

    expect($order->refresh()->paymentDueLabel())->toContain('5 ธ.ค. 2026')->toContain('15:30 น.');

    $order->update(['payment_due_at' => CarbonImmutable::parse('2026-12-05 23:59:59', config('shop.timezone'))->utc()]);

    expect($order->refresh()->paymentDueLabel())->toBe('5 ธ.ค. 2026');

    $order->update(['payment_due_at' => CarbonImmutable::parse('2026-12-05 08:30:00', 'UTC')]);

    expect((new PreorderBalanceDue($order->refresh()))->toLine($order))->toContain('15:30 น.');
});

test('a balance due after the release day keeps the end of the release day', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(3)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDay()->toDateString()]);

    expect($preorder->balanceDueAt()->format('Y-m-d H:i:s'))->toBe($preorder->releaseDayEnd()->utc()->format('Y-m-d H:i:s'));
});

test('moving the release date gives every requested balance its new deadline and tells the customer, never below the floor', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(3)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDay()->toDateString()]);
    $requested = confirmLatestPayment(placePreorder($preorder));
    $waiting = confirmLatestPayment(placePreorder($preorder));
    Order::query()->whereKey($waiting->id)->update(['balance_requested_at' => null]);
    $paidOrder = confirmLatestPayment(placePreorder($preorder, plan: PreorderPlan::Full));
    $before = $paidOrder->payment_due_at;
    Notification::assertSentOnDemandTimes(PreorderBalanceDue::class, 2);

    $preorder->update(['release_on' => now(config('shop.timezone'))->addDays(9)->toDateString()]);
    $moved = app(ReschedulePreorderBalanceDeadlines::class)->handle($preorder->refresh());

    expect($moved)->toBe(1)
        ->and($requested->refresh()->payment_due_at->format('Y-m-d H:i:s'))->toBe($preorder->releaseDayEnd()->utc()->format('Y-m-d H:i:s'))
        ->and($paidOrder->refresh()->payment_due_at->equalTo($before))->toBeTrue();

    Notification::assertSentOnDemandTimes(PreorderBalanceDue::class, 3);
    Notification::assertSentOnDemand(PreorderBalanceDue::class, fn (PreorderBalanceDue $notification): bool => $notification->moved && $notification->dueOn === $requested->paymentDueLabel());

    // Brought forward into the past: the deadline stops at the floor, and the customer is told it.
    $preorder->update(['release_on' => now(config('shop.timezone'))->subDay()->toDateString()]);
    app(ReschedulePreorderBalanceDeadlines::class)->handle($preorder->refresh());

    $floor = CarbonImmutable::now(config('shop.timezone'))->addDay()->endOfDay()->utc();

    expect($requested->refresh()->payment_due_at->format('Y-m-d H:i:s'))->toBe($floor->format('Y-m-d H:i:s'));

    Notification::assertSentOnDemandTimes(PreorderBalanceDue::class, 4);

    // Nothing changed: nothing is sent again.
    app(ReschedulePreorderBalanceDeadlines::class)->handle($preorder->refresh());

    Notification::assertSentOnDemandTimes(PreorderBalanceDue::class, 4);
});

test('the reschedule takes the order lock and then the pre-order lock, the order CancelOrder takes them in', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(3)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDay()->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder));
    $preorder->update(['release_on' => now(config('shop.timezone'))->addDays(9)->toDateString()]);
    $log = [];

    DB::listen(function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    app(ReschedulePreorderBalanceDeadlines::class)->handle($preorder->refresh());

    $orderLock = collect($log)->search(fn (string $sql): bool => str_contains($sql, 'from "orders" where "orders"."id" = ?'));
    $preorderLock = collect($log)->search(fn (string $sql): bool => str_contains($sql, 'from "preorders" where "preorders"."id" = ?'));

    expect($orderLock)->not->toBeFalse()
        ->and($preorderLock)->not->toBeFalse()
        ->and($orderLock)->toBeLessThan($preorderLock);
});

test('the order page of a cancelled pre-order says what is owed back and what is kept', function () {
    $preorder = openPreorder(['cancel_until' => now(config('shop.timezone'))->subDay()->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder, plan: PreorderPlan::Full));
    app(CancelOrder::class)->handle($order, 'x');

    expect($order->refresh()->refundSummary())->toBe(['owed' => $order->total_satang - 50000, 'kept' => 50000]);
});

test('asking for a balance twice through the action asks once', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(9)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDays(2)->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder));
    $preorder->update(['release_on' => now(config('shop.timezone'))->addDays(4)->toDateString()]);

    $first = app(RequestPreorderBalance::class)->handle($order);
    $second = app(RequestPreorderBalance::class)->handle($order);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and($order->payments()->count())->toBe(2);

    Notification::assertSentOnDemandTimes(PreorderBalanceDue::class, 1);
});

test('the deposit-confirmed message promises the seven-day request only while the release is outside the window', function () {
    $far = confirmLatestPayment(placePreorder(openPreorder(['release_on' => now(config('shop.timezone'))->addDays(20)->toDateString()])));
    $near = confirmLatestPayment(placePreorder(openPreorder(['release_on' => now(config('shop.timezone'))->addDays(4)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDays(2)->toDateString()])));

    $farMail = (new PaymentConfirmed($far))->toMail($far)->render()->toHtml();
    $nearMail = (new PaymentConfirmed($near))->toMail($near)->render()->toHtml();

    expect($farMail)->toContain('ก่อนวางจำหน่าย 7 วัน')
        ->and($nearMail)->not->toContain('7 วัน')
        ->and($nearMail)->toContain('ส่งคำขอชำระให้คุณพร้อมกัน')
        ->and((new PaymentConfirmed($near))->toLine($near))->not->toContain('7 วัน')
        ->and((new PaymentConfirmed($far))->toLine($far))->toContain('7 วัน');
});

test('the reschedule lists every live deposit order and decides on the request under its lock', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(9)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDay()->toDateString()]);
    $unpaid = placePreorder($preorder);
    $unrequested = confirmLatestPayment(placePreorder($preorder));
    $full = placePreorder($preorder, plan: PreorderPlan::Full);
    $preorder->update(['release_on' => now(config('shop.timezone'))->addDays(4)->toDateString()]);
    $requested = confirmLatestPayment(placePreorder($preorder));
    $log = [];

    DB::listen(function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    $moved = app(ReschedulePreorderBalanceDeadlines::class)->handle($preorder->refresh());

    $listing = collect($log)->first(fn (string $sql): bool => str_contains($sql, '"preorder_plan" = ?') && str_contains($sql, 'order by "id"'));

    expect($moved)->toBe(0)
        ->and($listing)->not->toBeNull()
        ->and($listing)->not->toContain('balance_requested_at')
        ->and($listing)->toContain('"status" in (?, ?)');

    // Each listed order was locked and looked at, the full plan one was not listed at all.
    $locks = collect($log)->filter(fn (string $sql): bool => str_contains($sql, 'from "orders" where "orders"."id" = ?'))->count();

    expect($locks)->toBeGreaterThanOrEqual(3)
        ->and($full->refresh()->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($unpaid->refresh()->balance_requested_at)->toBeNull();
});

test('the reschedule job completes the rest on a retry without messaging anyone twice', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(3)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDay()->toDateString()]);
    $first = confirmLatestPayment(placePreorder($preorder));
    $second = confirmLatestPayment(placePreorder($preorder));
    $third = confirmLatestPayment(placePreorder($preorder));
    $preorder->update(['release_on' => now(config('shop.timezone'))->addDays(10)->toDateString()]);
    Notification::assertSentOnDemandTimes(PreorderBalanceDue::class, 3);

    // The second order fails once, part way through the run.
    $failures = 1;
    Order::updating(function (Order $order) use ($second, &$failures): void {
        if ($order->getKey() === $second->getKey() && $failures-- > 0) {
            throw new RuntimeException('Simulated failure part way through.');
        }
    });

    $job = new RescheduleBalanceDeadlinesJob($preorder->id);

    expect(fn () => $job->handle(app(ReschedulePreorderBalanceDeadlines::class)))->toThrow(RuntimeException::class);

    // The first was moved and told before the failure; the others still wait.
    Notification::assertSentOnDemandTimes(PreorderBalanceDue::class, 4);
    expect($first->refresh()->payment_due_at->format('Y-m-d'))->toBe($preorder->refresh()->releaseDayEnd()->utc()->format('Y-m-d'))
        ->and($second->refresh()->payment_due_at->lt($preorder->releaseDayEnd()))->toBeTrue();

    $job->handle(app(ReschedulePreorderBalanceDeadlines::class));

    // The retry moved the other two and sent two more: three orders, three messages for the move, none repeated.
    Notification::assertSentOnDemandTimes(PreorderBalanceDue::class, 6);

    foreach ([$first, $second, $third] as $order) {
        expect($order->refresh()->payment_due_at->format('Y-m-d H:i:s'))->toBe($preorder->releaseDayEnd()->utc()->format('Y-m-d H:i:s'));
    }

    $job->handle(app(ReschedulePreorderBalanceDeadlines::class));

    Notification::assertSentOnDemandTimes(PreorderBalanceDue::class, 6);
    expect($job->tries)->toBe(3)->and($job->backoff())->not->toBeEmpty();
});
