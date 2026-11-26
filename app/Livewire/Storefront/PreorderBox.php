<?php

namespace App\Livewire\Storefront;

use App\Enums\PreorderPlan;
use App\Models\Preorder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The pre-order box of a sealed product's page: the countdown to the release, how many places are taken, the two ways to
 * pay, the quantity and the refund rule. Everything is re-read on each request. "Reserve" only sends the visitor on to the
 * pre-order checkout (or to log in first): the quota and the account limit are decided by `PlacePreorder` under the lock,
 * never here.
 */
class PreorderBox extends Component
{
    #[Locked]
    public int $preorderId;

    public int $qty = 1;

    public string $plan = 'deposit';

    /** What the last press said, shown next to the button. */
    public ?string $notice = null;

    public function mount(int $preorderId): void
    {
        $this->preorderId = $preorderId;
    }

    public function increment(): void
    {
        $this->notice = null;
        $this->qty = min($this->quantity() + 1, max($this->cap, 1));
    }

    public function decrement(): void
    {
        $this->notice = null;
        $this->qty = max($this->quantity() - 1, 1);
    }

    public function reserve(): void
    {
        $this->notice = null;
        $preorder = $this->preorder;

        if ($preorder === null) {
            return;
        }

        if (! $preorder->isOpenForOrders()) {
            $this->notice = __('storefront.preorder.refused.'.$preorder->state());
            unset($this->preorder, $this->cap);

            return;
        }

        if ($this->cap < 1) {
            $this->notice = __('storefront.preorder.refused.limit_reached');

            return;
        }

        $checkout = route('preorder.checkout', [
            'preorder' => $preorder->getKey(),
            'qty' => $this->quantity(),
            'plan' => $this->planChoice()->value,
        ]);

        // A guest signs in first; `CustomerLogin::redirectIntended()` then lands them on the same checkout.
        if (auth()->guest()) {
            session()->put('url.intended', $checkout);
            $this->redirectRoute('login');

            return;
        }

        $this->redirect($checkout);
    }

    #[Computed]
    public function preorder(): ?Preorder
    {
        return Preorder::query()->with('sealedProduct.set', 'sealedProduct.game')->find($this->preorderId);
    }

    /**
     * What this visitor may still ask for: their account limit less what they hold, and the places left.
     */
    #[Computed]
    public function cap(): int
    {
        return $this->preorder?->maxQtyFor(auth()->user()) ?? 0;
    }

    public function quantity(): int
    {
        return max(1, min($this->qty, max($this->cap, 1)));
    }

    public function planChoice(): PreorderPlan
    {
        return PreorderPlan::tryFrom($this->plan) ?? PreorderPlan::Deposit;
    }

    public function render(): View
    {
        return view('livewire.storefront.preorder-box');
    }
}
