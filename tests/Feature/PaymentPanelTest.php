<?php

use App\Actions\CancelOrder;
use App\Actions\PayShortfall;
use App\Actions\RefreshPaymentQr;
use App\Actions\RetryPayment;
use App\Actions\SubmitSlip;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentRefused;
use App\Exceptions\SlipNotStored;
use App\Livewire\Storefront\PaymentPanel;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Support\Money;
use App\Support\PaymentQr;
use App\Support\PromptPay;
use App\Support\QrImage;
use App\Support\SlipLimit;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Never read the developer's own .env: the tests say what the shop has configured.
    config([
        'shop.promptpay_id' => '0812345678',
        'shop.bank.name' => 'ธนาคารตัวอย่าง',
        'shop.bank.account_name' => 'ร้านตัวอย่าง',
        'shop.bank.account_number' => '000-0-00000-0',
    ]);
    Storage::fake('local');
});

afterEach(fn () => Carbon::setTestNow());

/**
 * An order awaiting payment whose one payment is pending, with the QR a PromptPay order is placed with, and the
 * visitor's session already knowing it.
 *
 * @param  array<string, mixed>  $payment
 */
function panelOrder(array $payment = [], string $method = 'promptpay'): Order
{
    $order = Order::factory()->create();
    $method = PaymentMethod::from($method);

    $order->payments()->sole()->update([
        'method' => $method,
        'amount_satang' => $order->total_satang,
        ...PaymentQr::attributes($method, $order->total_satang, $order->payment_due_at),
        ...$payment,
    ]);

    session(['orders' => [$order->id]]);

    return $order->refresh();
}

function panel(Order $order): Testable
{
    return Livewire::test(PaymentPanel::class, ['orderId' => $order->id]);
}

/**
 * The newest payment of the order, rejected by staff as a short slip.
 */
function rejectShort(Order $order, int $slipSatang): Payment
{
    $payment = $order->payments()->latest('id')->first();
    $payment->update([
        'status' => PaymentStatus::Rejected,
        'slip_path' => 'slips/'.$order->number.'/old.jpg',
        'slip_amount_satang' => $slipSatang,
        'rejected_reason' => 'ยอดในสลิปไม่ตรง · สลิปโอน ฿ 1 · ยอดที่ต้องชำระ ฿ 2 (ขาด ฿ 1)',
    ]);

    return $payment->refresh();
}

test('a pending PromptPay payment shows the QR, the exact amount, the shop and id, the countdown and the download', function () {
    $order = panelOrder();
    $payload = $order->payments()->sole()->qr_payload;

    expect($payload)->toBe(PromptPay::payload('0812345678', $order->total_satang));

    panel($order)
        ->assertSeeHtml('<svg xmlns="http://www.w3.org/2000/svg" class="qr-svg')
        ->assertSee('QR PromptPay ยอด '.Money::fromSatang($order->total_satang)->formatWithSatang())
        ->assertSee('081-234-5678')
        ->assertSee(config('app.name'))
        ->assertSee(__('storefront.payment.save_qr'))
        ->assertSeeHtml('data-copy="'.Money::fromSatang($order->total_satang)->toDecimalString().'"')
        ->assertSeeHtml('x-text="label"')
        ->assertSeeHtml(route('order.qr', $order->number))
        ->assertSee(__('storefront.payment.slip_button'))
        ->assertSee(__('storefront.payment.slip_hint', ['size' => SlipLimit::label(SlipLimit::kb())]))
        ->assertDontSee(__('storefront.payment.qr_expired'));
});

test('a bank transfer shows the shop bank details and no QR', function () {
    $order = panelOrder(method: 'bank_transfer');

    panel($order)
        ->assertSee('ธนาคารตัวอย่าง')
        ->assertSee('ร้านตัวอย่าง')
        ->assertSee('000-0-00000-0')
        ->assertDontSeeHtml('class="qr-svg')
        ->assertSee(__('storefront.payment.slip_button'));
});

test('the QR expiry never runs past the payment window, at placing and when refreshed', function () {
    Carbon::setTestNow('2026-11-25 10:00:00');
    $due = now()->addMinutes(5);

    expect(PaymentQr::expiry($due)->equalTo($due))->toBeTrue()
        ->and(PaymentQr::expiry(now()->addMinutes(30))->equalTo(now()->addMinutes(15)))->toBeTrue();

    $order = panelOrder(['qr_expires_at' => now()->subMinute()]);
    $order->update(['payment_due_at' => $due]);

    app(RefreshPaymentQr::class)->handle($order->payments()->sole());

    expect($order->payments()->sole()->qr_expires_at->equalTo($due))->toBeTrue();
});

test('an expired QR shows the expired card with the minutes left, and refreshing gives the same payload a new expiry', function () {
    Carbon::setTestNow('2026-11-25 10:00:00');
    $order = panelOrder(['qr_expires_at' => now()->subMinute()]);
    $order->update(['payment_due_at' => now()->addMinutes(6)]);
    $payload = $order->payments()->sole()->qr_payload;

    panel($order)
        ->assertSee(__('storefront.payment.qr_expired'))
        ->assertSee(__('storefront.payment.qr_expired_hint', ['minutes' => 6]))
        ->assertDontSeeHtml('class="qr-svg')
        ->call('refreshQr')
        ->assertDontSee(__('storefront.payment.qr_expired'))
        ->assertSeeHtml('class="qr-svg')
        ->assertHasNoErrors();

    $payment = $order->payments()->sole();
    expect($payment->qr_payload)->toBe($payload)
        ->and($payment->qr_expires_at->equalTo(now()->addMinutes(6)))->toBeTrue();

    // A second click on a QR that is valid again changes nothing.
    Carbon::setTestNow('2026-11-25 10:01:00');
    app(RefreshPaymentQr::class)->handle($payment);
    expect($payment->refresh()->qr_expires_at->equalTo(Carbon::parse('2026-11-25 10:06:00')))->toBeTrue();
});

test('past the payment window the panel says so and offers neither a QR nor an upload', function () {
    $order = panelOrder();
    $order->update(['payment_due_at' => now()->subMinute()]);

    panel($order)
        ->assertSee(__('storefront.payment.window_closed'))
        ->assertDontSeeHtml('class="qr-svg')
        ->assertDontSee(__('storefront.payment.slip_button'));
});

test('a slip of the wrong type or over the size limit is refused, nothing is stored and the payment stays pending', function (string $case) {
    [$file, $message] = match ($case) {
        'pdf' => [UploadedFile::fake()->create('slip.pdf', 100, 'application/pdf'), __('storefront.payment.errors.slip.mimes')],
        'too big' => [UploadedFile::fake()->create('slip.jpg', 6000, 'image/jpeg'), __('storefront.payment.errors.slip.max', ['size' => SlipLimit::label(SlipLimit::kb())])],
    };
    $order = panelOrder();

    panel($order)
        ->set('slip', $file)
        ->assertHasErrors('slip')
        ->assertSee($message)
        ->assertSet('slip', null);

    expect($order->payments()->sole()->status)->toBe(PaymentStatus::Pending)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['pdf', 'too big']);

test('a valid slip is stored on the private disk under the order number and the payment becomes submitted', function (string $name, string $mime) {
    $order = panelOrder();

    panel($order)
        ->set('slip', UploadedFile::fake()->create($name, 500, $mime))
        ->assertHasNoErrors()
        ->assertSet('slip', null)
        ->assertSee(__('storefront.payment.checking_title'))
        ->assertSee(__('storefront.payment.checking'));

    $payment = $order->payments()->sole();

    expect($payment->status)->toBe(PaymentStatus::Submitted)
        ->and($payment->submitted_at)->not->toBeNull()
        ->and($payment->slip_path)->toStartWith('slips/'.$order->number.'/');

    Storage::disk('local')->assertExists($payment->slip_path);
    expect(config('filesystems.disks.public.root'))->not->toContain($order->number);
})->with([
    'jpg' => ['slip.jpg', 'image/jpeg'],
    'png' => ['slip.png', 'image/png'],
    'heic' => ['slip.heic', 'image/heic'],
]);

test('the panel polls only while a slip is submitted', function () {
    $order = panelOrder();

    panel($order)->assertDontSeeHtml('wire:poll');

    $order->payments()->sole()->update(['status' => PaymentStatus::Submitted, 'slip_path' => 'slips/x.jpg', 'submitted_at' => now()]);
    panel($order)->assertSeeHtml('wire:poll.15s="checkStatus"');

    $order->payments()->sole()->update(['status' => PaymentStatus::Rejected, 'rejected_reason' => 'สลิปไม่ชัด']);
    panel($order)->assertDontSeeHtml('wire:poll');
});

test('the poll reloads the order page when staff paid or cancelled it, and does nothing while it waits', function () {
    $order = panelOrder();
    $order->payments()->sole()->update(['status' => PaymentStatus::Submitted, 'slip_path' => 'slips/x.jpg', 'submitted_at' => now()]);

    $component = panel($order)->call('checkStatus')->assertNoRedirect();

    $order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);
    $component->call('checkStatus')->assertRedirect(route('order.show', $order->number));
});

test('a paid order shows when it was paid, in shop time', function () {
    $order = panelOrder();
    $order->update(['status' => OrderStatus::Paid, 'paid_at' => Carbon::parse('2026-11-25 10:05:00', 'UTC')]);

    panel($order)
        ->assertSee(__('storefront.payment.paid'))
        ->assertSee('17:05')
        ->assertDontSee(__('storefront.payment.slip_button'));
});

test('a short slip shows its reason, both buttons, and paying the gap opens a payment for the gap pointing at the short one', function () {
    $order = panelOrder();
    $rejected = rejectShort($order, $order->total_satang - 5000);

    panel($order)
        ->assertSee($rejected->rejected_reason)
        ->assertSee(__('storefront.payment.rejected_pay_shortfall'))
        ->assertSee(__('storefront.payment.rejected_new_slip'))
        ->call('payShortfall')
        ->assertHasNoErrors()
        ->assertSee(__('storefront.payment.shortfall'))
        ->assertSeeHtml('class="qr-svg')
        ->assertDontSee(__('storefront.payment.rejected_pay_shortfall'));

    $gap = $order->payments()->latest('id')->first();

    expect($order->payments()->count())->toBe(2)
        ->and($gap->status)->toBe(PaymentStatus::Pending)
        ->and($gap->amount_satang)->toBe(5000)
        ->and($gap->shortfall_of_payment_id)->toBe($rejected->id)
        ->and($gap->method)->toBe($rejected->method)
        ->and($gap->qr_payload)->toBe(PromptPay::payload('0812345678', 5000));
});

test('a rejection that is not short offers only a new slip, and retrying opens a pending payment for the full amount', function () {
    $order = panelOrder();
    $rejected = rejectShort($order, $order->total_satang);
    $rejected->update(['slip_amount_satang' => null]);

    panel($order)
        ->assertDontSee(__('storefront.payment.rejected_pay_shortfall'))
        ->assertSee(__('storefront.payment.rejected_new_slip'))
        ->call('retryPayment')
        ->assertHasNoErrors()
        ->assertSee(__('storefront.payment.slip_button'));

    $retry = $order->payments()->latest('id')->first();

    expect($retry->status)->toBe(PaymentStatus::Pending)
        ->and($retry->amount_satang)->toBe($order->total_satang)
        ->and($retry->shortfall_of_payment_id)->toBeNull()
        ->and($retry->qr_payload)->not->toBeNull();
});

test('the order owes the total less what staff saw arrive: a short gap payment is paid again for what is still owed', function () {
    $order = panelOrder();
    $order->update(['total_satang' => 100000]);
    $order->payments()->sole()->update(['amount_satang' => 100000]);

    $first = rejectShort($order, 90000);
    expect($order->receivedSatang())->toBe(90000)->and($order->remainingSatang())->toBe(10000);

    $gap = app(PayShortfall::class)->handle($first);
    expect($gap->amount_satang)->toBe(10000)->and($gap->shortfall_of_payment_id)->toBe($first->id);

    $gap->update(['status' => PaymentStatus::Rejected, 'slip_amount_satang' => 5000, 'rejected_reason' => 'x']);
    expect($order->receivedSatang())->toBe(95000)->and($order->remainingSatang())->toBe(5000);

    panel($order)->assertSee(__('storefront.payment.rejected_pay_shortfall'));

    $next = app(PayShortfall::class)->handle($gap);
    expect($next->amount_satang)->toBe(5000)->and($next->shortfall_of_payment_id)->toBe($gap->id);
});

test('a confirmed payment counts as received in full and a rejected one that was not short counts for nothing', function () {
    $order = panelOrder();
    $order->update(['total_satang' => 100000]);
    $payment = $order->payments()->sole();
    $payment->update(['amount_satang' => 100000, 'status' => PaymentStatus::Rejected, 'slip_amount_satang' => 100000, 'rejected_reason' => 'x']);

    expect($order->receivedSatang())->toBe(0);

    Payment::factory()->for($order)->confirmed()->create(['amount_satang' => 30000]);
    expect($order->receivedSatang())->toBe(30000)->and($order->remainingSatang())->toBe(70000);

    $order->update(['total_satang' => 10000]);
    expect($order->remainingSatang())->toBe(0);
});

test('retrying after a short slip asks only for what is still owed, and after a plain rejection for the full amount', function () {
    $order = panelOrder();
    $order->update(['total_satang' => 100000]);
    $order->payments()->sole()->update(['amount_satang' => 100000]);

    $short = rejectShort($order, 90000);
    $retry = app(RetryPayment::class)->handle($short);
    expect($retry->amount_satang)->toBe(10000)->and($retry->shortfall_of_payment_id)->toBe($short->id);

    $retry->update(['status' => PaymentStatus::Rejected, 'rejected_reason' => 'สลิปไม่ชัด']);
    $again = app(RetryPayment::class)->handle($retry);
    expect($again->amount_satang)->toBe(10000)->and($again->shortfall_of_payment_id)->toBeNull();

    $plain = panelOrder();
    $plain->update(['total_satang' => 50000]);
    $plain->payments()->sole()->update(['amount_satang' => 50000, 'status' => PaymentStatus::Rejected, 'rejected_reason' => 'สลิปไม่ชัด']);
    expect(app(RetryPayment::class)->handle($plain->payments()->sole())->amount_satang)->toBe(50000);
});

test('the order page asks for what is still owed and says what the shop already received', function () {
    $order = panelOrder();
    $order->update(['total_satang' => 100000]);
    $order->payments()->sole()->update(['amount_satang' => 100000]);

    $this->get($order->signedUrl())->assertDontSee(__('storefront.order.received'));

    rejectShort($order, 90000);

    $this->get($order->signedUrl())
        ->assertSeeInOrder([__('storefront.order.amount_due'), '฿ 100', __('storefront.order.received'), '฿ 900']);
});

test('a slip that did not reach the disk is not marked submitted, and the customer is told', function () {
    $order = panelOrder();
    $payment = $order->payments()->sole();
    $ghost = Mockery::mock(UploadedFile::fake()->create('slip.jpg', 10))->makePartial();
    $ghost->shouldReceive('store')->andReturn('slips/ghost.jpg');

    expect(fn () => app(SubmitSlip::class)->handle($payment, $ghost))->toThrow(SlipNotStored::class);

    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Pending)->and($payment->slip_path)->toBeNull();
});

test('the panel shows the store failure as a slip error', function () {
    $order = panelOrder();
    $this->mock(SubmitSlip::class)->shouldReceive('handle')->andThrow(new SlipNotStored('x'));

    panel($order)
        ->set('slip', UploadedFile::fake()->create('slip.jpg', 100, 'image/jpeg'))
        ->assertHasErrors('slip')
        ->assertSee(__('storefront.payment.errors.slip_store_failed'));
});

test('a heif slip is accepted, and the upload errors Livewire raises itself are in Thai', function () {
    $order = panelOrder();

    panel($order)
        ->assertSeeHtml('.heif,image/jpeg,image/png,image/heic,image/heif')
        ->set('slip', UploadedFile::fake()->create('slip.heif', 100, 'image/heif'))
        ->assertHasNoErrors();

    expect($order->payments()->sole()->status)->toBe(PaymentStatus::Submitted)
        ->and(trans('validation.uploaded', ['attribute' => 'files.0']))->toBe('อัปโหลดไม่สำเร็จ ไฟล์อาจใหญ่เกินไปหรือการเชื่อมต่อขัดข้อง')
        ->and(trans('validation.max.file', ['attribute' => 'files.0', 'max' => 12288]))->toContain('12288');
});

test('the slip limit is the smallest of the shop limit and what PHP accepts', function () {
    config(['shop.slip_max_kb' => 100]);
    expect(SlipLimit::kb())->toBe(100);

    config(['shop.slip_max_kb' => 99999999]);
    expect(SlipLimit::kb())->toBe(min(
        intdiv(SlipLimit::bytes((string) ini_get('upload_max_filesize')), 1024) ?: PHP_INT_MAX,
        intdiv(SlipLimit::bytes((string) ini_get('post_max_size')), 1024) ?: PHP_INT_MAX,
    ))
        ->and(SlipLimit::bytes('2M'))->toBe(2 * 1024 * 1024)
        ->and(SlipLimit::bytes('8m'))->toBe(8 * 1024 * 1024)
        ->and(SlipLimit::bytes('512K'))->toBe(512 * 1024)
        ->and(SlipLimit::bytes('1G'))->toBe(1024 ** 3)
        ->and(SlipLimit::bytes('1048576'))->toBe(1048576)
        ->and(SlipLimit::bytes('-1'))->toBe(-1)
        ->and(SlipLimit::label(5120))->toBe('5 MB');
});

test('a quick second click on pay-the-gap or retry shows no error, while a real refusal still does', function () {
    $order = panelOrder();
    rejectShort($order, 1000);

    panel($order)
        ->call('payShortfall')
        ->call('payShortfall')
        ->call('retryPayment')
        ->assertHasNoErrors();

    expect($order->payments()->count())->toBe(2);

    $other = panelOrder();
    rejectShort($other, 1000);
    $other->update(['status' => OrderStatus::Paid]);

    panel($other)->call('payShortfall')->assertHasErrors('action');
});

test('a double click on pay-the-gap opens one payment, the second click is refused', function () {
    $order = panelOrder();
    $rejected = rejectShort($order, 1000);

    app(PayShortfall::class)->handle($rejected);

    expect(fn () => app(PayShortfall::class)->handle($rejected))->toThrow(PaymentRefused::class)
        ->and(fn () => app(RetryPayment::class)->handle($rejected))->toThrow(PaymentRefused::class)
        ->and($order->payments()->count())->toBe(2);
});

test('the panel is a 404 for a session that did not place or open the order, on mount and on every later call', function () {
    $order = panelOrder();
    $other = Order::factory()->create();

    session(['orders' => []]);
    Livewire::test(PaymentPanel::class, ['orderId' => $order->id])->assertNotFound();

    session(['orders' => [$other->id]]);
    Livewire::test(PaymentPanel::class, ['orderId' => $order->id])->assertNotFound();

    session(['orders' => [$order->id]]);
    $component = panel($order)->assertOk();

    // The session lost the order between two requests of the same component (the id itself is locked).
    session(['orders' => []]);
    $component->call('refreshQr')->assertNotFound();
    expect((new ReflectionProperty(PaymentPanel::class, 'orderId'))->getAttributes(Locked::class))->not->toBeEmpty();

    Livewire::test(PaymentPanel::class, ['orderId' => 999999])->assertNotFound();
});

test('the order page renders the panel for an order awaiting payment, and opens it by the signed link', function () {
    $order = panelOrder();
    session()->forget('orders');

    $this->get($order->signedUrl())
        ->assertOk()
        ->assertSee(__('storefront.order.pay_by', ['time' => $order->payment_due_at->copy()->setTimezone('Asia/Bangkok')->format('H:i')]))
        ->assertSee(__('storefront.order.emailed', ['email' => $order->contact_email]))
        ->assertSeeHtml('class="qr-svg');
});

test('the QR download is an attachment PNG for the order owner or a signed link, and a 404 otherwise', function () {
    $order = panelOrder();
    $url = route('order.qr', $order->number);

    $response = $this->withSession(['orders' => [$order->id]])->get($url)->assertOk();
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename="promptpay-'.$order->number.'.png"')
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and(substr($response->getContent(), 0, 8))->toBe("\x89PNG\r\n\x1a\n");

    session()->forget('orders');
    $this->get(URL::signedRoute('order.qr', ['order' => $order->number]))->assertOk();
    $this->get($url)->assertNotFound();
    $this->get(URL::signedRoute('order.qr', ['order' => 'TCG-99999']))->assertNotFound();

    $bank = panelOrder(method: 'bank_transfer');
    $this->get(URL::signedRoute('order.qr', ['order' => $bank->number]))->assertNotFound();

    $order->update(['status' => OrderStatus::Cancelled]);
    $this->get(URL::signedRoute('order.qr', ['order' => $order->number]))->assertNotFound();
});

test('a slip for a cancelled order is refused with a clear message and the file is not kept', function () {
    Notification::fake();
    $order = panelOrder();
    $component = panel($order);

    app(CancelOrder::class)->handle($order, 'ยกเลิก');

    $component
        ->set('slip', UploadedFile::fake()->create('slip.jpg', 100, 'image/jpeg'))
        ->assertHasErrors('action')
        ->assertSee(__('storefront.payment.refused.order_cancelled'))
        ->assertSet('slip', null);

    $payment = $order->payments()->sole();

    expect($payment->status)->toBe(PaymentStatus::Rejected)
        ->and($payment->slip_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('SubmitSlip refuses a payment that is not pending, an order that is not awaiting payment and a closed window', function () {
    $order = panelOrder();
    $payment = $order->payments()->sole();

    $payment->update(['status' => PaymentStatus::Submitted]);
    expect(fn () => app(SubmitSlip::class)->handle($payment, UploadedFile::fake()->image('slip.jpg')))->toThrow(PaymentRefused::class);

    $payment->update(['status' => PaymentStatus::Pending]);
    $order->update(['payment_due_at' => now()->subSecond()]);
    expect(fn () => app(SubmitSlip::class)->handle($payment, UploadedFile::fake()->image('slip.jpg')))->toThrow(PaymentRefused::class);

    $order->update(['payment_due_at' => now()->addMinutes(5), 'status' => OrderStatus::Paid]);
    expect(fn () => app(SubmitSlip::class)->handle($payment, UploadedFile::fake()->image('slip.jpg')))->toThrow(PaymentRefused::class)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('every payment action takes the order row lock first, before any other read of its transaction', function (Closure $run) {
    Notification::fake();
    $order = panelOrder();
    $log = [];

    $payment = $run($order);

    Event::listen(TransactionBeginning::class, function () use (&$log): void {
        $log[] = 'BEGIN';
    });
    DB::listen(function ($query) use (&$log): void {
        $log[] = $query->sql;
    });

    $payment['act']($payment['payment']);

    $begin = array_search('BEGIN', $log, true);

    expect($begin)->not->toBeFalse()
        ->and($log[$begin + 1])->toStartWith('select * from "orders" where "orders"."id" = ?');
})->with([
    'submit slip' => [fn (Order $order) => ['payment' => $order->payments()->sole(), 'act' => fn (Payment $payment) => app(SubmitSlip::class)->handle($payment, UploadedFile::fake()->image('slip.jpg'))]],
    'refresh qr' => [fn (Order $order) => ['payment' => tap($order->payments()->sole(), fn (Payment $payment) => $payment->update(['qr_expires_at' => now()->subMinute()])), 'act' => fn (Payment $payment) => app(RefreshPaymentQr::class)->handle($payment)]],
    'pay shortfall' => [fn (Order $order) => ['payment' => rejectShort($order, 1000), 'act' => fn (Payment $payment) => app(PayShortfall::class)->handle($payment)]],
    'retry payment' => [fn (Order $order) => ['payment' => rejectShort($order, 1000), 'act' => fn (Payment $payment) => app(RetryPayment::class)->handle($payment)]],
]);

test('a submitted slip makes the unpaid-order guard stand down, because SubmitSlip took the same order lock', function () {
    Notification::fake();
    $this->freezeTime();
    $order = panelOrder();
    $order->update(['payment_due_at' => now()->addSecond()]);
    app(SubmitSlip::class)->handle($order->payments()->sole(), UploadedFile::fake()->image('slip.jpg'));
    $order->update(['payment_due_at' => now()->subMinute()]);

    $cancelled = app(CancelOrder::class)->handle(
        $order,
        'x',
        fn (Order $locked): bool => ! $locked->payments()->where('status', PaymentStatus::Submitted->value)->exists(),
    );

    expect($cancelled)->toBeNull()->and($order->refresh()->status)->toBe(OrderStatus::AwaitingPayment);
});

test('the global Thai validation lines stay generic: no file or slip wording on a plain required rule or a renamed attribute', function () {
    foreach (['validation.required', 'validation.uploaded', 'validation.file', 'validation.max.file'] as $key) {
        expect(trans($key, ['attribute' => 'ชื่อ', 'max' => 10]))->not->toContain('สลิป');
    }

    expect(trans('validation.required', ['attribute' => 'ชื่อ']))->toBe('กรอกชื่อ')
        ->and(trans('validation.required', ['attribute' => 'ชื่อ']))->not->toContain('ไฟล์')
        ->and(trans('validation.attributes.files.0'))->toBe('validation.attributes.files.0');

    expect(validator(['name' => ''], ['name' => 'required'], [], ['name' => 'ชื่อ'])->errors()->first('name'))->toBe('กรอกชื่อ');
});

test('the header lines live in the panel and follow its state: deadline while paying, none once the slip is submitted', function () {
    Carbon::setTestNow('2026-11-25 09:00:00 UTC');
    $order = panelOrder();
    $order->update(['payment_due_at' => Carbon::parse('2026-11-25 09:20:00', 'UTC')]);

    panel($order)
        ->assertSee(__('storefront.order.pay_by', ['time' => '16:20']))
        ->assertSee(__('storefront.order.amount_due'));

    $order->payments()->sole()->update(['status' => PaymentStatus::Submitted, 'slip_path' => 'slips/x.jpg', 'submitted_at' => now()]);

    panel($order)
        ->assertSee(__('storefront.payment.submitted_heading'))
        ->assertSee(__('storefront.payment.transferred'))
        ->assertDontSee(__('storefront.order.pay_by', ['time' => '16:20']))
        ->assertDontSee(__('storefront.order.amount_due'));
});

test('a rejection without a reason still has a heading', function () {
    $order = panelOrder();
    $order->payments()->sole()->update(['status' => PaymentStatus::Rejected, 'rejected_reason' => null]);

    panel($order)->assertSee(__('storefront.payment.rejected_generic'))->assertDontSeeHtml('text-low"></h3>');
});

test('picking a file after a plain rejection opens a new payment and submits the slip on it, without a second click', function () {
    $order = panelOrder();
    $rejected = rejectShort($order, $order->total_satang);
    $rejected->update(['slip_amount_satang' => null]);

    panel($order)
        ->assertSee(__('storefront.payment.show_qr_again'))
        ->set('slip', UploadedFile::fake()->create('slip.jpg', 100, 'image/jpeg'))
        ->assertHasNoErrors()
        ->assertSee(__('storefront.payment.checking_title'));

    $latest = $order->payments()->latest('id')->first();

    expect($order->payments()->count())->toBe(2)
        ->and($latest->id)->not->toBe($rejected->id)
        ->and($latest->status)->toBe(PaymentStatus::Submitted)
        ->and($latest->amount_satang)->toBe($order->total_satang);
    Storage::disk('local')->assertExists($latest->slip_path);
});

test('the short-rejected case keeps both buttons and a new slip attaches to a payment for what is owed', function () {
    $order = panelOrder();
    $order->update(['total_satang' => 100000]);
    $order->payments()->sole()->update(['amount_satang' => 100000]);
    rejectShort($order, 90000);

    panel($order)
        ->assertSee(__('storefront.payment.rejected_pay_shortfall'))
        ->assertSee(__('storefront.payment.rejected_new_slip'))
        ->assertDontSee(__('storefront.payment.show_qr_again'))
        ->set('slip', UploadedFile::fake()->create('slip.jpg', 100, 'image/jpeg'));

    expect($order->payments()->latest('id')->first()->amount_satang)->toBe(10000);
});

test('the QR sits on a fixed white ground and its PNG has a four-module quiet zone', function () {
    $order = panelOrder();

    panel($order)->assertSeeHtml('bg-white')->assertDontSeeHtml('rounded-control bg-ink');

    $payload = PromptPay::payload('0812345678', 10000);
    preg_match('/viewBox="0 0 (\d+) /', QrImage::svg($payload), $box);
    $modules = (int) $box[1] - 4;

    // The SVG carries a two-module margin; the PNG is drawn at ten pixels a module with four.
    expect(getimagesizefromstring(QrImage::png($payload))[0])->toBe(($modules + 8) * 10);
});

test('a paid order reads ชำระเงินแล้ว once, with the time after a dot', function () {
    $order = panelOrder();
    $order->update(['status' => OrderStatus::Paid, 'paid_at' => Carbon::parse('2026-11-25 10:05:00', 'UTC')]);

    $html = panel($order)->assertSee('ชำระเงินแล้ว · ')->html();

    expect(substr_count($html, 'ชำระเงินแล้ว'))->toBe(1);
});

test('an order cancelled while its panel is open reloads the page instead of offering to pay again', function () {
    $order = panelOrder();

    $component = panel($order)->assertNoRedirect();

    $order->update(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()]);
    $order->payments()->sole()->update(['status' => PaymentStatus::Rejected]);

    $component->call('$refresh')->assertRedirect(route('order.show', $order->number));
});

test('the owner, signed in and without the session or a signature, downloads the QR of their own pending order', function () {
    $owner = User::factory()->create();
    $order = panelOrder();
    $order->update(['user_id' => $owner->id]);
    session()->forget('orders');

    $response = $this->actingAs($owner)->get(route('order.qr', $order->number))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename="promptpay-'.$order->number.'.png"')
        ->and(substr($response->getContent(), 0, 8))->toBe("\x89PNG\r\n\x1a\n");
});
