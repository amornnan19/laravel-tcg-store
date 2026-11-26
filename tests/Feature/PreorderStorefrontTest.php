<?php

require_once __DIR__.'/../Support/preorder.php';

use App\Actions\CancelOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PreorderPlan;
use App\Jobs\RequestPreorderBalances;
use App\Livewire\Storefront\PaymentPanel;
use App\Livewire\Storefront\PreorderBox;
use App\Livewire\Storefront\PreorderCheckout;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(fn () => gc_collect_cycles());

beforeEach(function () {
    Notification::fake();
    config(['shop.promptpay_id' => '0812345678']);
});

test('the product page shows the countdown, the progress, both plans, the limit and the refund rule', function () {
    $preorder = openPreorder([
        'quota' => 40,
        'reserved_count' => 12,
        'deposit_satang' => 50000,
        'full_satang' => 280000,
        'full_discount_bp' => 200,
        'release_on' => now(config('shop.timezone'))->addDays(30)->toDateString(),
        'cancel_until' => now(config('shop.timezone'))->addDays(14)->toDateString(),
    ]);

    $this->get(route('sealed.show', $preorder->sealedProduct))
        ->assertOk()
        ->assertSee('จองแล้ว 12 / 40 กล่อง')
        ->assertSee('role="timer"', false)
        ->assertSeeText('เหลือเวลาสั่งจอง 2')
        ->assertSee('มัดจำต่อกล่อง')
        ->assertSee('฿ 500')
        ->assertSee('฿ 2,800')
        ->assertSee('฿ 2,744')
        ->assertSee('ลด 2%')
        ->assertSee('ยกเลิกได้ถึง')
        ->assertSee('คืนมัดจำเต็มจำนวน')
        ->assertSee('หลังจากนั้นมัดจำไม่คืน')
        ->assertSee('ต้องเข้าสู่ระบบก่อนสั่งจอง')
        ->assertDontSee('เปิดสั่งจองเร็ว ๆ นี้');
});

test('a pre-order product without a pre-order row still says it opens soon, with no button', function () {
    $product = sealedProduct(['is_preorder' => true]);

    $this->get(route('sealed.show', $product))->assertOk()->assertSee('เปิดสั่งจองเร็ว ๆ นี้')->assertDontSee('สั่งจอง</button>', false);
});

test('a closed, full or released pre-order says so and offers no reserve button', function (array $state, string $text) {
    $preorder = openPreorder($state);

    Livewire::test(PreorderBox::class, ['preorderId' => $preorder->id])
        ->assertSee($text)
        ->assertDontSee('wire:click="reserve"', false);
})->with([
    'closed' => [['is_open' => false], 'ปิดรับสั่งจองแล้ว'],
    'full' => [['quota' => 4, 'reserved_count' => 4], 'จองเต็มแล้ว 4 / 4 กล่อง'],
    'released' => [fn () => ['release_on' => now()->subDay()->toDateString()], 'วางจำหน่ายแล้ว'],
    'not yet open' => [fn () => ['opens_at' => now()->addDay()], 'ยังไม่เปิดรับสั่งจอง'],
]);

test('a guest who reserves is sent to log in and lands on the checkout they asked for', function () {
    $preorder = openPreorder(['per_account_limit' => 3]);

    Livewire::test(PreorderBox::class, ['preorderId' => $preorder->id])
        ->set('qty', 2)
        ->set('plan', 'full')
        ->call('reserve')
        ->assertRedirectToRoute('login');

    $intended = session('url.intended');

    expect($intended)->toBe(route('preorder.checkout', ['preorder' => $preorder->id, 'qty' => 2, 'plan' => 'full']));

    $this->get(route('preorder.checkout', ['preorder' => $preorder->id]))->assertRedirectToRoute('login');
});

test('a signed-in customer who reserves goes to the checkout, never above the cap', function () {
    $preorder = openPreorder(['per_account_limit' => 2]);
    $this->actingAs(User::factory()->create());

    Livewire::test(PreorderBox::class, ['preorderId' => $preorder->id])
        ->set('qty', 9)
        ->call('reserve')
        ->assertRedirect(route('preorder.checkout', ['preorder' => $preorder->id, 'qty' => 2, 'plan' => 'deposit']));
});

test('the stepper stops at what the account may still order and says so', function () {
    $preorder = openPreorder(['per_account_limit' => 3, 'quota' => 50]);
    $user = User::factory()->create();
    placePreorder($preorder, $user, qty: 2);
    $this->actingAs($user);

    Livewire::test(PreorderBox::class, ['preorderId' => $preorder->id])
        ->call('increment')
        ->call('increment')
        ->assertSet('qty', 1)
        ->assertSee('สั่งได้มากสุด 1 กล่อง (จำกัดต่อบัญชี)')
        ->assertDontSee('ลองลดจำนวนลง');
});

test('an account at its limit sees why, not a dead button', function () {
    $preorder = openPreorder(['per_account_limit' => 1]);
    $user = User::factory()->create();
    placePreorder($preorder, $user);
    $this->actingAs($user);

    Livewire::test(PreorderBox::class, ['preorderId' => $preorder->id])
        ->assertSee('คุณสั่งจองครบจำนวนต่อบัญชีแล้ว')
        ->assertDontSee('wire:click="reserve"', false);
});

test('the checkout places the order, remembers it for the session and opens its page', function () {
    $preorder = openPreorder();
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(PreorderCheckout::class, ['preorderId' => $preorder->id, 'qty' => 2, 'plan' => 'deposit'])
        ->assertSee('ชำระวันนี้')
        ->assertSee('฿ 1,000');

    foreach (['name' => 'สมชาย ใจดี', 'phone' => '0812345678', 'email' => 's@example.com', 'line1' => '99/9', 'subdistrict' => 'คลองเตย', 'district' => 'คลองเตย', 'province' => 'กรุงเทพมหานคร', 'postcode' => '10110'] as $field => $value) {
        $component->set('form.'.$field, $value);
    }

    $component->call('placeOrder')->assertRedirect();

    $order = Order::query()->firstOrFail();

    expect($order->user_id)->toBe($user->id)
        ->and($order->items()->firstOrFail()->qty)->toBe(2)
        ->and($preorder->fresh()->reserved_count)->toBe(2)
        ->and(session('orders_placed'))->toContain($order->id);
});

test('the checkout cannot be pointed at another plan or quantity by the client', function () {
    $preorder = openPreorder();
    $this->actingAs(User::factory()->create());

    Livewire::test(PreorderCheckout::class, ['preorderId' => $preorder->id, 'qty' => 1, 'plan' => 'deposit'])
        ->set('qty', 50);
})->throws(CannotUpdateLockedPropertyException::class);

test('the checkout refuses when the quota was taken meanwhile and shows why', function () {
    $preorder = openPreorder(['quota' => 1, 'per_account_limit' => 2]);
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(PreorderCheckout::class, ['preorderId' => $preorder->id, 'qty' => 1, 'plan' => 'deposit']);
    $preorder->update(['reserved_count' => 1]);

    foreach (['name' => 'สมชาย ใจดี', 'phone' => '0812345678', 'email' => 's@example.com', 'line1' => '99/9', 'subdistrict' => 'คลองเตย', 'district' => 'คลองเตย', 'province' => 'กรุงเทพมหานคร', 'postcode' => '10110'] as $field => $value) {
        $component->set('form.'.$field, $value);
    }

    $component->call('placeOrder')->assertNoRedirect()->assertSee('สั่งจองไม่ได้แล้ว');

    expect(Order::query()->count())->toBe(0);
});

test('the order page of a deposit order explains the balance before and after it is requested', function () {
    // Ten days out: the deposit is in, and the balance waits for the seven-day mark.
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(10)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDays(2)->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder));

    $this->get($order->signedUrl())
        ->assertOk()
        ->assertSee('รอชำระส่วนที่เหลือ')
        ->assertSee('ร้านได้รับมัดจำแล้ว')
        ->assertSee('ร้านจะส่งข้อความขอส่วนที่เหลือก่อนวางจำหน่าย 7 วัน')
        ->assertSee('ยกเลิกได้ถึง');

    $preorder->update(['release_on' => now(config('shop.timezone'))->addDays(4)->toDateString()]);
    (new RequestPreorderBalances)->handle();

    $this->get($order->signedUrl())
        ->assertOk()
        ->assertSee('ถึงเวลาชำระส่วนที่เหลือแล้ว')
        ->assertSee('ชำระภายในวันที่')
        ->assertSee('แนบสลิปโอนเงิน');

    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingBalance);
});

test('the payment panel of a requested balance opens for its owner and answers a slip', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(4)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDays(2)->toDateString()]);
    $user = User::factory()->create();
    $order = confirmLatestPayment(placePreorder($preorder, $user));
    (new RequestPreorderBalances)->handle();
    $this->actingAs($user);

    Livewire::test(PaymentPanel::class, ['orderId' => $order->id])
        ->assertSee('ยอดที่ต้องชำระ')
        ->assertSee(Money::fromSatang($order->refresh()->balance_due_satang)->format())
        ->assertSee('ชำระภายในวันที่');

    Storage::fake('local');

    Livewire::test(PaymentPanel::class, ['orderId' => $order->id])
        ->set('slip', UploadedFile::fake()->image('slip.jpg'))
        ->assertHasNoErrors();

    expect($order->payments()->latest('id')->firstOrFail()->status)->toBe(PaymentStatus::Submitted);
});

test('a cancelled pre-order says its places were returned, not stock', function () {
    $order = placePreorder(openPreorder());
    app(CancelOrder::class)->handle($order, 'x');

    $this->get($order->refresh()->signedUrl())->assertSee('ที่จองไว้คืนแล้ว')->assertDontSee('การ์ดคืนเข้าร้านแล้ว');
});

test('a balance past its deadline says the shop will get in touch, not the stock-order text', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(4)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDays(2)->toDateString()]);
    $user = User::factory()->create();
    $order = confirmLatestPayment(placePreorder($preorder, $user));
    (new RequestPreorderBalances)->handle();
    $order->refresh()->update(['payment_due_at' => now()->subHour()]);
    $this->actingAs($user);

    Livewire::test(PaymentPanel::class, ['orderId' => $order->id])
        ->assertSee('เลยกำหนดชำระส่วนที่เหลือแล้ว ร้านจะติดต่อกลับเพื่อคุยต่อ')
        ->assertDontSee('กำลังจะถูกยกเลิก');
});

test('the checkout names the plan that was chosen', function (string $plan, string $shown, string $hidden) {
    $preorder = openPreorder();
    $this->actingAs(User::factory()->create());

    Livewire::test(PreorderCheckout::class, ['preorderId' => $preorder->id, 'qty' => 1, 'plan' => $plan])
        ->assertSee($shown)
        ->assertDontSee($hidden);
})->with([
    'deposit' => ['deposit', 'มัดจำวันนี้', 'จ่ายเต็มวันนี้'],
    'full' => ['full', 'จ่ายเต็มวันนี้', 'มัดจำวันนี้'],
]);

test('an account at its limit is told at the checkout before filling anything in, with a way to its orders', function () {
    $preorder = openPreorder(['per_account_limit' => 1]);
    $user = User::factory()->create();
    placePreorder($preorder, $user);
    $this->actingAs($user);

    Livewire::test(PreorderCheckout::class, ['preorderId' => $preorder->id, 'qty' => 1, 'plan' => 'deposit'])
        ->assertSee('คุณสั่งจองครบจำนวนต่อบัญชีแล้ว')
        ->assertSee(route('account'), false)
        ->assertSee('คำสั่งซื้อของฉัน')
        ->assertDontSee('wire:submit="placeOrder"', false);
});

test('asking at the checkout for more than the account may still order says how many it can', function () {
    $preorder = openPreorder(['per_account_limit' => 2]);
    $user = User::factory()->create();
    placePreorder($preorder, $user);
    $this->actingAs($user);

    Livewire::test(PreorderCheckout::class, ['preorderId' => $preorder->id, 'qty' => 2, 'plan' => 'deposit'])
        ->assertSee('สั่งได้อีก 1 กล่อง')
        ->assertDontSee('ครบจำนวน')
        ->assertDontSee('wire:submit="placeOrder"', false);
});

test('the open box is the panel: three countdown tiles, key value rows, a thin progress bar and the nearly full note', function () {
    $preorder = openPreorder(['quota' => 40, 'reserved_count' => 37]);

    Livewire::test(PreorderBox::class, ['preorderId' => $preorder->id])
        ->assertSeeHtml('rounded-card border border-line bg-surface p-4')
        ->assertSeeHtml('flex-1 rounded-control bg-sunken py-4 text-center')
        ->assertSeeHtml('h-1.5 overflow-hidden rounded-full bg-sunken')
        ->assertSeeHtml('font-medium text-stock')
        ->assertSee('ชั่วโมง')
        ->assertSee('จองแล้ว 37 / 40 กล่อง')
        ->assertSee('เหลือ 3 กล่อง')
        ->assertDontSee('text-low');
});

test('the box does not say few are left while plenty are', function () {
    Livewire::test(PreorderBox::class, ['preorderId' => openPreorder(['quota' => 40, 'reserved_count' => 10])->id])->assertDontSee('เหลือ 30 กล่อง');
});

test('every state that is not open says so and offers a way to other products', function (array $state, string $text) {
    $product = sealedProductInSet(['is_preorder' => true]);
    $preorder = openPreorder($state, $product);

    Livewire::test(PreorderBox::class, ['preorderId' => $preorder->id])
        ->assertSee($text)
        ->assertSee('ดูสินค้าอื่น')
        ->assertSee(route('search', ['q' => $product->set->name_en]), false)
        ->assertDontSee('wire:click="reserve"', false);
})->with([
    'full' => [['quota' => 4, 'reserved_count' => 4], 'จองเต็มแล้ว 4 / 4 กล่อง'],
    'closed' => [['is_open' => false], 'ปิดรับสั่งจองแล้ว'],
    'released' => [fn () => ['release_on' => now()->subDay()->toDateString()], 'วางจำหน่ายแล้ว'],
    'not yet open' => [fn () => ['opens_at' => now()->addDay()], 'ยังไม่เปิดรับสั่งจอง'],
]);

test('once released the page hides the badge, the deposit and the progress', function () {
    $preorder = openPreorder(['release_on' => now()->subDay()->toDateString(), 'reserved_count' => 7]);

    $this->get(route('sealed.show', $preorder->sealedProduct))
        ->assertOk()
        ->assertDontSee('พรีออเดอร์ · ส่ง')
        ->assertDontSee('มัดจำต่อกล่อง')
        ->assertDontSee('จองแล้ว 7')
        ->assertSee('วางจำหน่ายแล้ว');
});

test('the refund rule keeps its closing clause on one line', function () {
    $this->get(route('sealed.show', openPreorder()->sealedProduct))->assertSee('whitespace-nowrap">หลังจากนั้นมัดจำไม่คืน<', false);
});

test('a cancelled pre-order after its deadline says what comes back and what is kept, and leads back to the product', function () {
    $preorder = openPreorder(['cancel_until' => now(config('shop.timezone'))->subDay()->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder, plan: PreorderPlan::Full));
    app(CancelOrder::class)->handle($order, 'x');

    $this->get($order->refresh()->signedUrl())
        ->assertSee('ร้านจะโอนคืน ฿ 2,284')
        ->assertSee('ร้านหัก ฿ 500 ตามเงื่อนไข')
        ->assertDontSee('มัดจำ ฿ 500')
        ->assertSee(route('sealed.show', $preorder->sealedProduct), false)
        ->assertDontSee('href="'.route('search').'"', false)
        ->assertDontSee('ส่วนที่เหลือ')
        ->assertDontSee('หลังจากนั้นมัดจำไม่คืน');
});

test('a full-plan order words the refund rule as what the shop deducts, not as a deposit', function () {
    $preorder = openPreorder(['cancel_until' => now(config('shop.timezone'))->addDays(3)->toDateString()]);
    $order = placePreorder($preorder, plan: PreorderPlan::Full);

    $this->get($order->signedUrl())
        ->assertSee('หลังจากนั้นร้านหัก ฿ 500')
        ->assertDontSee('หลังจากนั้นมัดจำไม่คืน');
});

test('a requested balance shows one heading, not two stacked', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(4)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDays(2)->toDateString()]);
    $order = confirmLatestPayment(placePreorder($preorder));
    (new RequestPreorderBalances)->handle();

    $html = $this->get($order->refresh()->signedUrl())->getContent();

    expect(substr_count($html, 'ถึงเวลาชำระส่วนที่เหลือแล้ว'))->toBe(1)
        ->and(substr_count($html, 'ร้านได้รับมัดจำแล้ว'))->toBe(0);
});

test('the full-plan refund terms read the same on the box, the checkout and the cancelled order', function () {
    $preorder = openPreorder();
    $user = User::factory()->create();
    $this->actingAs($user);

    $box = Livewire::test(PreorderBox::class, ['preorderId' => $preorder->id]);
    $box->assertSee('หลังจากนั้นมัดจำไม่คืน')->assertDontSee('ร้านหัก');
    $box->set('plan', 'full')->assertSee('หลังจากนั้นร้านหัก ฿ 500')->assertDontSee('มัดจำไม่คืน');

    Livewire::test(PreorderCheckout::class, ['preorderId' => $preorder->id, 'qty' => 2, 'plan' => 'full'])
        ->assertSee('หลังจากนั้นร้านหัก ฿ 1,000')
        ->assertDontSee('มัดจำไม่คืน');

    Livewire::test(PreorderCheckout::class, ['preorderId' => $preorder->id, 'qty' => 2, 'plan' => 'deposit'])
        ->assertSee('หลังจากนั้นมัดจำไม่คืน')
        ->assertDontSee('ร้านหัก');
});

test('the payment panel names a deposit a deposit, and an ordinary amount an amount', function () {
    $user = User::factory()->create();
    $deposit = placePreorder(openPreorder(), $user);
    $full = placePreorder(openPreorder(), $user, plan: PreorderPlan::Full);
    $stock = Order::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test(PaymentPanel::class, ['orderId' => $deposit->id])->assertSee('มัดจำที่ต้องชำระ');
    Livewire::test(PaymentPanel::class, ['orderId' => $full->id])->assertSee('ยอดที่ต้องชำระ')->assertDontSee('มัดจำที่ต้องชำระ');
    Livewire::test(PaymentPanel::class, ['orderId' => $stock->id])->assertSee('ยอดที่ต้องชำระ')->assertDontSee('มัดจำที่ต้องชำระ');
});

test('the order total is not gold while a deposit order still waits for its deposit', function () {
    $deposit = placePreorder(openPreorder());
    $full = placePreorder(openPreorder(), plan: PreorderPlan::Full);

    $this->get($deposit->signedUrl())->assertSeeHtml('tabular-nums text-h3 font-medium text-ink');
    expect($this->get($deposit->signedUrl())->getContent())->not->toContain('class="tabular-nums text-h3 font-medium text-accent">฿ 5,640');
    $this->get($full->signedUrl())->assertSeeHtml('tabular-nums text-h3 font-medium text-accent');
});

test('an overdue amount is ink with no gap where a heading would be, for a stock order too', function () {
    $user = User::factory()->create();
    $order = Order::factory()->overdue()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $html = Livewire::test(PaymentPanel::class, ['orderId' => $order->id])->html();

    expect($html)->toContain('<p class="flex items-baseline justify-between gap-4">')
        ->and($html)->toContain('tabular-nums text-h3 font-medium text-ink')
        ->and($html)->not->toContain('tabular-nums text-h3 font-medium text-accent');
});

test('the checkout offers the way to the orders whenever the account limit stops it, also when a race did', function () {
    $preorder = openPreorder(['per_account_limit' => 2, 'quota' => 50]);
    $user = User::factory()->create();
    placePreorder($preorder, $user);
    $this->actingAs($user);

    Livewire::test(PreorderCheckout::class, ['preorderId' => $preorder->id, 'qty' => 2, 'plan' => 'deposit'])
        ->assertSee('คำสั่งซื้อของฉัน')
        ->assertSee(route('account'), false);

    // One more is placed between the page and the click: PlacePreorder refuses, and the page says why and where to go.
    $component = Livewire::test(PreorderCheckout::class, ['preorderId' => $preorder->id, 'qty' => 1, 'plan' => 'deposit']);
    placePreorder($preorder, $user);

    foreach (['name' => 'สมชาย ใจดี', 'phone' => '0812345678', 'email' => 's@example.com', 'line1' => '99/9', 'subdistrict' => 'คลองเตย', 'district' => 'คลองเตย', 'province' => 'กรุงเทพมหานคร', 'postcode' => '10110'] as $field => $value) {
        $component->set('form.'.$field, $value);
    }

    $component->call('placeOrder')->assertSee('คำสั่งซื้อของฉัน');
});

test('a full box says it is full once, and a box not yet open says when it opens', function () {
    $full = openPreorder(['quota' => 4, 'reserved_count' => 4]);

    $html = Livewire::test(PreorderBox::class, ['preorderId' => $full->id])->html();

    expect(substr_count($html, 'จองเต็มแล้ว 4 / 4 กล่อง'))->toBe(1)
        ->and($html)->toContain('จองเต็มแล้ว');

    $soon = openPreorder(['opens_at' => CarbonImmutable::parse('2030-01-05 08:30:00', 'UTC')]);

    Livewire::test(PreorderBox::class, ['preorderId' => $soon->id])->assertSee('เปิดสั่งจอง 5 ม.ค. 2030 15:30 น.');
});

test('dates and the return clause never break across lines', function () {
    $preorder = openPreorder();
    $this->actingAs(User::factory()->create());

    Livewire::test(PreorderCheckout::class, ['preorderId' => $preorder->id, 'qty' => 1, 'plan' => 'deposit'])
        ->assertSeeHtml('whitespace-nowrap">พรีออเดอร์ · วางจำหน่าย')
        ->assertSeeHtml('whitespace-nowrap">คืนที่จอง<');

    $order = placePreorder($preorder);
    app(CancelOrder::class)->handle($order, 'x');

    $this->get($order->refresh()->signedUrl())->assertSeeHtml('whitespace-nowrap">ที่จองไว้คืนแล้ว<');
});

test('the visible confirm button is a submit button inside the form and is never hidden while a field has focus', function () {
    $preorder = openPreorder();
    $this->actingAs(User::factory()->create());

    $html = Livewire::test(PreorderCheckout::class, ['preorderId' => $preorder->id, 'qty' => 1, 'plan' => 'deposit'])->html();

    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath = new DOMXPath($document);

    $buttons = $xpath->query('//form[@*[name()="wire:submit"]="placeOrder"]//button[@type="submit"]');
    $bar = $xpath->query('//form[@*[name()="wire:submit"]="placeOrder"]//button[@type="submit"]/ancestor::div[contains(@class, "fixed")]');

    expect($buttons->length)->toBe(1)
        ->and($bar->length)->toBe(1)
        ->and($bar->item(0)->getAttribute('class'))->not->toContain('hidden')
        ->and($html)->not->toContain('data-text-input]:focus]:hidden');
});

test('the full plan never says deposit in its refund line, the deposit plan does', function () {
    $preorder = openPreorder();
    $this->actingAs(User::factory()->create());

    $box = Livewire::test(PreorderBox::class, ['preorderId' => $preorder->id]);
    $box->assertSee('คืนมัดจำเต็มจำนวน');
    $box->set('plan', 'full')->assertSee('คืนเงินเต็มจำนวน')->assertDontSee('คืนมัดจำ');

    Livewire::test(PreorderCheckout::class, ['preorderId' => $preorder->id, 'qty' => 1, 'plan' => 'full'])
        ->assertSee('คืนเงินเต็มจำนวน')
        ->assertDontSee('คืนมัดจำ');
});

test('the seven-day promise is made only while the release is outside the balance window', function () {
    $far = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(20)->toDateString()]);
    $near = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(4)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDays(2)->toDateString()]);
    $this->actingAs(User::factory()->create());

    Livewire::test(PreorderBox::class, ['preorderId' => $far->id])->assertSee('ร้านจะแจ้งก่อนวางจำหน่าย 7 วัน')->assertDontSee('ร้านจะแจ้งทันทีหลังรับมัดจำ');
    Livewire::test(PreorderBox::class, ['preorderId' => $near->id])->assertSee('ร้านจะแจ้งทันทีหลังรับมัดจำ')->assertDontSee('7 วัน');

    Livewire::test(PreorderCheckout::class, ['preorderId' => $far->id, 'qty' => 1, 'plan' => 'deposit'])->assertSee('ร้านแจ้งก่อนวางจำหน่าย 7 วัน');
    Livewire::test(PreorderCheckout::class, ['preorderId' => $near->id, 'qty' => 1, 'plan' => 'deposit'])->assertSee('ร้านจะแจ้งทันทีหลังรับมัดจำ')->assertDontSee('7 วัน');

    $order = confirmLatestPayment(placePreorder($far));
    $this->get($order->signedUrl())->assertSee('ก่อนวางจำหน่าย 7 วัน');

    $far->update(['release_on' => now(config('shop.timezone'))->addDays(4)->toDateString()]);
    $this->get($order->signedUrl())->assertSee('ร้านจะส่งข้อความขอส่วนที่เหลือให้ทันที')->assertDontSee('7 วัน');
});

test('the date and time of a balance deadline stay on one line', function () {
    $preorder = openPreorder(['release_on' => now(config('shop.timezone'))->addDays(4)->toDateString(), 'cancel_until' => now(config('shop.timezone'))->addDays(2)->toDateString()]);
    $user = User::factory()->create();
    $order = confirmLatestPayment(placePreorder($preorder, $user));
    $order->update(['payment_due_at' => CarbonImmutable::parse('2026-12-05 08:30:00', 'UTC')]);
    $this->actingAs($user);

    Livewire::test(PaymentPanel::class, ['orderId' => $order->id])->assertSeeHtml('whitespace-nowrap">5 ธ.ค. 2026 15:30 น.<');
});

test('both checkout bars carry the id the phone scroll padding rule is keyed on', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(PreorderCheckout::class, ['preorderId' => openPreorder()->id, 'qty' => 1, 'plan' => 'deposit'])->assertSeeHtml('id="checkout-bar"');

    expect(file_get_contents(resource_path('css/app.css')))->toContain('html:has(#deck-add-bar, #checkout-bar) { scroll-padding-bottom: 7rem; }')
        ->and(file_get_contents(resource_path('views/livewire/storefront/checkout-form.blade.php')))->toContain('id="checkout-bar"');
});
