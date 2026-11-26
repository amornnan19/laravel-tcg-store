<?php

namespace App\Livewire\Storefront;

use App\Actions\PlacePreorder;
use App\Enums\Courier;
use App\Enums\PaymentMethod;
use App\Enums\PreorderPlan;
use App\Exceptions\PreorderRefused;
use App\Livewire\Forms\CheckoutData;
use App\Models\Preorder;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The pre-order checkout: the same recipient, courier and payment fields as the cart's checkout, with a summary of what is
 * paid today and what waits for the balance request. The pre-order, quantity and plan are locked: nothing the client sends
 * changes them. The order is made by PlacePreorder, which decides again under the pre-order's lock.
 */
class PreorderCheckout extends Component
{
    public CheckoutData $form;

    #[Locked]
    public int $preorderId;

    #[Locked]
    public int $qty = 1;

    #[Locked]
    public string $plan = 'deposit';

    /** What PlacePreorder said when it refused, shown until the visitor leaves. */
    #[Locked]
    public ?string $refusal = null;

    /** The reason PlacePreorder gave, so the page knows whether the account limit stopped it. */
    #[Locked]
    public ?string $refusalReason = null;

    public function mount(int $preorderId, int $qty, string $plan): void
    {
        $this->preorderId = $preorderId;
        $this->qty = max(1, $qty);
        $this->plan = (PreorderPlan::tryFrom($plan) ?? PreorderPlan::Deposit)->value;

        $user = auth()->user();

        if ($user !== null) {
            $this->form->name = $user->name;
            $this->form->email = $user->email ?? '';
        }

        $this->form->courier = Courier::Flash->value;
        $this->form->method = PaymentMethod::offered()[0]->value ?? '';
    }

    public function updated(string $name): void
    {
        if (str_starts_with($name, 'form.')) {
            $this->form->validateOnly(substr($name, 5));
        }
    }

    public function placeOrder(PlacePreorder $placePreorder): void
    {
        $user = auth()->user();
        $preorder = $this->preorder;

        if ($user === null || $preorder === null) {
            $this->redirectRoute('login');

            return;
        }

        try {
            $this->form->validate();
        } catch (ValidationException $exception) {
            $this->dispatch('checkout-invalid');

            throw $exception;
        }

        try {
            $order = $placePreorder->handle($preorder, $user, $this->form, $this->qty, $this->planChoice());
        } catch (PreorderRefused $refused) {
            $this->refusal = $refused->message();
            $this->refusalReason = $refused->reason;
            unset($this->preorder);

            return;
        }

        session()->push('orders', $order->getKey());
        session()->push('orders_placed', $order->getKey());
        session()->flash('order_placed', $order->getKey());

        $this->redirect($order->signedUrl());
    }

    #[Computed]
    public function preorder(): ?Preorder
    {
        return Preorder::query()->with('sealedProduct')->find($this->preorderId);
    }

    public function planChoice(): PreorderPlan
    {
        return PreorderPlan::from($this->plan);
    }

    public function render(): View
    {
        $preorder = $this->preorder;
        $courier = Courier::tryFrom($this->form->courier) ?? Courier::Flash;
        $planChoice = $this->planChoice();
        $unit = $preorder === null ? 0 : ($planChoice === PreorderPlan::Full ? $preorder->fullPlanUnitSatang() : $preorder->full_satang);
        $subtotal = $unit * $this->qty;
        $shipping = $courier->rateSatang();
        $total = $subtotal + $shipping;
        $deposit = $preorder === null ? 0 : min($preorder->deposit_satang * $this->qty, $subtotal);
        $payNow = $planChoice === PreorderPlan::Deposit ? $deposit : $total;

        // Why the page cannot place the order, if it cannot: a refusal PlacePreorder gave, the state of the pre-order, or the
        // account's own limit, checked here so a customer at the limit is told before filling anything in.
        $cap = $preorder?->maxQtyFor(auth()->user()) ?? 0;
        $state = $preorder?->state() ?? 'closed';
        $blocked = match (true) {
            $this->refusal !== null => $this->refusal,
            $state !== 'open' => __('storefront.preorder.refused.'.$state),
            $cap < $this->qty => __('storefront.preorder.refused.'.PreorderRefused::key('limit', $cap), ['n' => $cap]),
            default => null,
        };

        return view('livewire.storefront.preorder-checkout', [
            'methods' => PaymentMethod::offered(),
            'couriers' => Courier::cases(),
            'courier' => $courier,
            'planChoice' => $planChoice,
            'unit' => $unit,
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'total' => $total,
            'payNow' => $payNow,
            'later' => $total - $payNow,
            'keptSatang' => $deposit,
            'blocked' => $blocked,
            // Blocked by the account limit, at zero left or short of the quantity asked for: the way out is the orders it holds.
            'atLimit' => $this->refusalReason === 'limit' || ($state === 'open' && $this->refusal === null && $cap < $this->qty),
        ]);
    }
}
