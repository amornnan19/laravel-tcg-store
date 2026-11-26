<?php

namespace App\Livewire\Storefront;

use App\Actions\PlaceOrder;
use App\Enums\Courier;
use App\Enums\PaymentMethod;
use App\Exceptions\CheckoutBlocked;
use App\Exceptions\StoreCreditChanged;
use App\Livewire\Forms\CheckoutData;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Variant;
use App\Support\CartLines;
use App\Support\CurrentCart;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The checkout page: the recipient and address form, the courier, packing and payment choices, and the summary.
 * The cart is read from the session on every request; the order is built by PlaceOrder from the cart and the
 * validated form, never from anything the client sends beyond the form fields.
 */
class CheckoutForm extends Component
{
    public CheckoutData $form;

    /** Set when PlaceOrder refused the cart, so the page says so until the visitor goes back to the cart. */
    #[Locked]
    public bool $refused = false;

    /** Set when PlaceOrder refused because the store credit balance changed after the page rendered. */
    #[Locked]
    public bool $creditChanged = false;

    public function mount(): void
    {
        $user = auth()->user();

        if ($user !== null) {
            $this->form->name = $user->name;
            $this->form->email = $user->email ?? '';
        }

        $this->form->courier = Courier::Flash->value;
        $this->form->method = PaymentMethod::offered()[0]->value ?? '';
        // Only a single card is worth packing: a graded card or a sealed product never turns this on.
        $this->form->protection = $this->heldLines->contains(
            fn (CartItem $line): bool => $line->purchasable instanceof Variant && $line->unit_price_satang >= (int) config('shipping.protection_threshold_satang'),
        );
    }

    /**
     * A field that was fixed clears its error as soon as it loses focus. The property is validated, never rewritten.
     */
    public function updated(string $name): void
    {
        if ($name === 'form.useCredit') {
            $this->creditChanged = false;
        }

        if (str_starts_with($name, 'form.')) {
            $this->form->validateOnly(substr($name, 5));
        }
    }

    public function placeOrder(PlaceOrder $placeOrder): void
    {
        $cart = $this->cart;

        if ($cart === null) {
            $this->redirectRoute('cart');

            return;
        }

        try {
            $this->form->validate();
        } catch (ValidationException $exception) {
            $this->dispatch('checkout-invalid');

            throw $exception;
        }

        $this->creditChanged = false;

        try {
            $order = $placeOrder->handle($cart, $this->form, auth()->user());
        } catch (StoreCreditChanged) {
            // The render that follows shows the new balance and its figures; the next confirm is against those.
            $this->creditChanged = true;

            return;
        } catch (CheckoutBlocked) {
            $this->refused = true;
            unset($this->cart, $this->cartLines, $this->heldLines, $this->expiresAt, $this->subtotal);

            return;
        }

        // The visitor's own session may open the order page later without the signed link.
        session()->push('orders', $order->getKey());
        session()->push('orders_placed', $order->getKey());
        session()->flash('order_placed', $order->getKey());

        $this->redirect($order->signedUrl());
    }

    #[Computed]
    public function cart(): ?Cart
    {
        return app(CurrentCart::class)->find();
    }

    /**
     * The cart's lines and the rules about them, shared with the cart page and PlaceOrder.
     */
    #[Computed]
    public function cartLines(): CartLines
    {
        return new CartLines($this->cart);
    }

    /**
     * The lines that hold stock and can still be bought.
     *
     * @return Collection<int, CartItem>
     */
    #[Computed]
    public function heldLines(): Collection
    {
        return $this->cartLines->heldLines();
    }

    /**
     * Whether an order can be placed from what the cart holds now: it is not empty and every line can be ordered.
     */
    public function canPlace(): bool
    {
        return ! $this->refused && $this->cartLines->canPlaceOrder();
    }

    #[Computed]
    public function expiresAt(): ?CarbonInterface
    {
        return $this->cartLines->expiresAt();
    }

    /**
     * Satang: unit price times quantity over the held lines.
     */
    #[Computed]
    public function subtotal(): int
    {
        return $this->cartLines->subtotal();
    }

    public function render(): View
    {
        $courier = Courier::tryFrom($this->form->courier) ?? Courier::Flash;
        $shipping = $courier->rateSatang();
        $packable = $this->cartLines->hasPackableLines();
        $protection = $this->form->protection && $packable ? (int) config('shipping.protection_satang') : 0;
        $insuredCount = $this->cartLines->insuredCount();
        $insurance = $insuredCount * (int) config('shipping.graded_insurance_satang');
        $total = $this->subtotal + $shipping + $protection + $insurance;

        // Only what the card shows and the summary previews: PlaceOrder reads the balance again under the user's lock.
        $creditBalance = (int) auth()->user()?->store_credit_satang;
        $creditUsable = min($creditBalance, $total);
        $creditApplied = $this->form->useCredit ? $creditUsable : 0;
        // What this render shows is what the next confirm is guarded against.
        $this->form->shownCreditSatang = $creditApplied;

        return view('livewire.storefront.checkout-form', [
            'methods' => PaymentMethod::offered(),
            'couriers' => Courier::cases(),
            'courier' => $courier,
            'shipping' => $shipping,
            'protection' => $protection,
            'packable' => $packable,
            'insuredCount' => $insuredCount,
            'insurance' => $insurance,
            'total' => $total,
            'creditBalance' => $creditBalance,
            'creditUsable' => $creditUsable,
            'creditApplied' => $creditApplied,
            'payable' => $total - $creditApplied,
        ]);
    }
}
