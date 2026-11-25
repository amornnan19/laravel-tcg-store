<?php

namespace App\Livewire\Storefront;

use App\Actions\PlaceOrder;
use App\Enums\Courier;
use App\Enums\PaymentMethod;
use App\Exceptions\CheckoutBlocked;
use App\Livewire\Forms\CheckoutData;
use App\Models\Cart;
use App\Models\CartItem;
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

    public function mount(): void
    {
        $this->form->courier = Courier::Flash->value;
        $this->form->method = PaymentMethod::offered()[0]->value ?? '';
        $this->form->protection = $this->heldLines->contains(
            fn (CartItem $line): bool => $line->unit_price_satang >= (int) config('shipping.protection_threshold_satang'),
        );
    }

    /**
     * A field that was fixed clears its error as soon as it loses focus. The property is validated, never rewritten.
     */
    public function updated(string $name): void
    {
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

        try {
            $order = $placeOrder->handle($cart, $this->form);
        } catch (CheckoutBlocked) {
            $this->refused = true;
            unset($this->cart, $this->cartLines, $this->heldLines, $this->expiresAt, $this->subtotal);

            return;
        }

        // The visitor's own session may open the order page later without the signed link.
        session()->push('orders', $order->getKey());
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
        $protection = $this->form->protection ? (int) config('shipping.protection_satang') : 0;

        return view('livewire.storefront.checkout-form', [
            'methods' => PaymentMethod::offered(),
            'couriers' => Courier::cases(),
            'courier' => $courier,
            'shipping' => $shipping,
            'protection' => $protection,
            'total' => $this->subtotal + $shipping + $protection,
        ]);
    }
}
