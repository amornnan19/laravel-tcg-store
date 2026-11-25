<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

test('a valid signature opens the order page', function () {
    $order = Order::factory()->create();

    $this->get($order->signedUrl())
        ->assertOk()
        ->assertSee($order->number)
        ->assertSee(OrderStatus::AwaitingPayment->label())
        ->assertSee(__('storefront.order.awaiting_payment'))
        ->assertSee($order->items->first()->card_name)
        ->assertSee($order->address['line1']);
});

test('the session that placed the order opens it without the signature', function () {
    $order = Order::factory()->create();

    $this->withSession(['orders' => [$order->id]])->get(route('order.show', $order->number))->assertOk()->assertSee($order->number);
});

test('neither a signature nor the session is a 404, not a 403, and so is an unknown number', function () {
    $order = Order::factory()->create();
    $other = Order::factory()->create();

    $this->get(route('order.show', $order->number))->assertNotFound();
    $this->withSession(['orders' => [$other->id]])->get(route('order.show', $order->number))->assertNotFound();
    $this->get(route('order.show', 'TCG-99999'))->assertNotFound();
    $this->get(URL::signedRoute('order.show', ['order' => 'TCG-99999']))->assertNotFound();
});

test('a signature made for another order or a tampered one does not open the order', function () {
    $order = Order::factory()->create();
    $other = Order::factory()->create();

    $this->get(str_replace($other->number, $order->number, $other->signedUrl()))->assertNotFound();
    $this->get($order->signedUrl().'0')->assertNotFound();
});

test('the timeline lists what happened from the timestamps, then what is still to come', function () {
    $paid = Order::factory()->paid()->create();
    $this->get($paid->signedUrl())
        ->assertSeeInOrder([__('storefront.order.placed'), __('storefront.order.paid'), __('storefront.order.packing'), __('storefront.order.shipped'), __('storefront.order.delivered')])
        ->assertDontSee(__('storefront.order.awaiting_payment'));

    expect(collect($paid->timeline())->map(fn (array $step): array => [$step['key'], $step['at'] !== null])->all())
        ->toBe([['placed', true], ['paid', true], ['packing', false], ['shipped', false], ['delivered', false]]);
});

test('a cancelled order shows only what happened and a shipped one links to the courier tracking page', function () {
    $cancelled = Order::factory()->cancelled()->create();

    expect(collect($cancelled->timeline())->pluck('key')->all())->toBe(['placed', 'cancelled']);
    $this->get($cancelled->signedUrl())->assertOk()->assertSee(__('storefront.order.cancelled'))->assertDontSee(__('storefront.order.delivered'));

    $shipped = Order::factory()->shipped()->create(['tracking_number' => 'TH123']);
    $this->get($shipped->signedUrl())
        ->assertSee('https://www.flashexpress.co.th/fle/tracking?se=TH123', false)
        ->assertSee(__('storefront.order.track'));
});

test('the order page prints the snapshot and the totals', function () {
    $order = Order::factory()->create(['protection_satang' => 3000]);
    $order->items()->update(['card_name' => 'Pikachu', 'unit_price_satang' => 150000, 'qty' => 1, 'line_total_satang' => 150000]);
    $order->update(['subtotal_satang' => 150000, 'total_satang' => 157000]);

    $this->get($order->signedUrl())
        ->assertSee('Pikachu')
        ->assertSee('฿ 1,500')
        ->assertSee('฿ 40')
        ->assertSee('฿ 30')
        ->assertSee('฿ 1,570');
});

test('arriving with a valid signature remembers the order in the session, once', function () {
    $order = Order::factory()->create();

    $this->get($order->signedUrl())->assertOk();
    expect(session('orders'))->toBe([$order->id]);

    $this->get($order->signedUrl())->assertOk();
    expect(session('orders'))->toBe([$order->id]);

    // And the session alone now opens it, as the payment panel's unsigned requests will need.
    $this->get(route('order.show', $order->number))->assertOk();
});

test('a signed link made for https verifies behind a proxy that terminates TLS', function () {
    $order = Order::factory()->create();

    URL::forceScheme('https');
    $signed = $order->signedUrl();
    URL::forceScheme('http');

    $this->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->get(preg_replace('#^https:#', 'http:', $signed))
        ->assertOk();
});

test('a pending order says by when to pay in shop time and that the link was emailed', function () {
    Carbon::setTestNow('2026-11-25 09:00:00 UTC');
    $order = Order::factory()->create(['payment_due_at' => Carbon::parse('2026-11-25 10:05:00', 'UTC'), 'contact_email' => 'buyer@example.com']);

    $this->get($order->signedUrl())
        ->assertSee(__('storefront.order.pay_by', ['time' => '17:05']))
        ->assertSee(__('storefront.order.emailed', ['email' => 'buyer@example.com']))
        ->assertSee($order->latestPayment->method->label())
        ->assertDontSee(__('storefront.order.placed_flash'));

    Carbon::setTestNow();
});

test('the placed flash shows only when checkout flashed it', function () {
    $order = Order::factory()->create();

    $this->withSession(['orders' => [$order->id]])->get(route('order.show', $order->number))->assertDontSee(__('storefront.order.placed_flash'));
    $this->flushSession();

    $this->withSession(['orders' => [$order->id], 'order_placed' => $order->id])
        ->get(route('order.show', $order->number))
        ->assertSee(__('storefront.order.placed_flash'));

});

test('a cancelled order shows the reason verbatim, that the cards went back and a search button', function () {
    $order = Order::factory()->cancelled()->create(['cancel_reason' => 'ไม่ได้ชำระเงินภายในเวลาที่กำหนด']);

    $this->get($order->signedUrl())
        ->assertSee('ไม่ได้ชำระเงินภายในเวลาที่กำหนด')
        ->assertSee(__('storefront.order.stock_returned'))
        ->assertSee(route('search'))
        ->assertDontSee(__('storefront.order.pay_by', ['time' => '']), false);
});
