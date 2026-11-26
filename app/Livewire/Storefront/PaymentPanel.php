<?php

namespace App\Livewire\Storefront;

use App\Actions\PayShortfall;
use App\Actions\RefreshPaymentQr;
use App\Actions\RetryPayment;
use App\Actions\SubmitSlip;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentRefused;
use App\Exceptions\SlipNotStored;
use App\Models\Order;
use App\Models\Payment;
use App\Support\OrderAccess;
use App\Support\SlipLimit;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The payment section of the order page: the PromptPay QR or the bank details, the slip upload, and what happened to
 * the slip. The only state is the order's id (locked) and the upload being handled: the order and its newest payment
 * are read on every request, and every request checks again that this session may see the order, exactly as the order
 * page does. Nothing a client sends names a payment; the actions work on the newest one.
 */
class PaymentPanel extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $orderId;

    /** @var mixed A Livewire temporary upload while a slip is being handled, otherwise null. */
    public $slip = null;

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;

        // Every later request reads the order through this same computed, so the access rule runs on each of them.
        $this->order;
    }

    /**
     * @throws NotFoundHttpException when the order does not exist or this session may not see it.
     */
    #[Computed]
    public function order(): Order
    {
        $order = Order::query()->find($this->orderId);

        abort_if($order === null || ! OrderAccess::canView($order), 404);

        return $order;
    }

    #[Computed]
    public function payment(): ?Payment
    {
        return $this->order->payments()->latest('id')->first();
    }

    /**
     * The slip is stored and handed to staff as soon as it has arrived and passed validation. After a rejection a fresh
     * payment is opened for it first.
     */
    public function updatedSlip(SubmitSlip $submitSlip, RetryPayment $retryPayment): void
    {
        $maxKb = SlipLimit::kb();

        try {
            $this->validate(
                ['slip' => ['required', 'file', 'mimes:jpg,jpeg,png,heic,heif', 'max:'.$maxKb]],
                [
                    'slip.required' => __('storefront.payment.errors.slip.required'),
                    'slip.file' => __('storefront.payment.errors.slip.file'),
                    'slip.mimes' => __('storefront.payment.errors.slip.mimes'),
                    'slip.max' => __('storefront.payment.errors.slip.max', ['size' => SlipLimit::label($maxKb)]),
                    'slip.uploaded' => __('storefront.payment.errors.slip.uploaded'),
                ],
            );
        } catch (ValidationException $exception) {
            $this->discardSlip();

            throw $exception;
        }

        $payment = $this->payment;

        try {
            if ($payment === null) {
                throw new PaymentRefused('changed');
            }

            // A rejected payment is retried first, so the customer who already paid just attaches the slip again.
            if ($payment->status === PaymentStatus::Rejected) {
                try {
                    $payment = $retryPayment->handle($payment);
                } catch (PaymentRefused $refused) {
                    // Another tab or device retried first: carry on with the payment it opened, if it is still waiting.
                    unset($this->payment);
                    $payment = $this->payment;

                    if ($payment?->status !== PaymentStatus::Pending) {
                        throw $refused;
                    }
                }
            }

            $submitSlip->handle($payment, $this->slip);
        } catch (PaymentRefused $refused) {
            $this->addError('action', $refused->message());
        } catch (SlipNotStored) {
            $this->addError('slip', __('storefront.payment.errors.slip_store_failed'));
        } finally {
            $this->discardSlip();
            unset($this->order, $this->payment);
        }
    }

    public function refreshQr(RefreshPaymentQr $refreshPaymentQr): void
    {
        $this->run(fn (Payment $payment) => $refreshPaymentQr->handle($payment));
    }

    public function payShortfall(PayShortfall $payShortfall): void
    {
        $this->run(fn (Payment $payment) => $payShortfall->handle($payment), quietIfPending: true);
    }

    public function retryPayment(RetryPayment $retryPayment): void
    {
        $this->run(fn (Payment $payment) => $retryPayment->handle($payment), quietIfPending: true);
    }

    /**
     * Polled while a slip waits for staff. When the order has left the states that wait for money (paid, cancelled, or a
     * deposit in and the balance not yet asked for) the whole page
     * is reloaded, so its badge and timeline follow.
     */
    public function checkStatus(): void
    {
        unset($this->order, $this->payment);

        if (! $this->order->isWaitingForMoney()) {
            $this->redirectRoute('order.show', ['order' => $this->order->number]);
        }
    }

    public function render(): View
    {
        $order = $this->order;
        $payment = $this->payment;
        $due = $order->payment_due_at;

        // Cancelled (or otherwise closed) while the panel was open: reload, so the page shows why instead of offering
        // to pay. The page only renders the panel for awaiting or paid orders, so a first render never gets here.
        if (! $order->isWaitingForMoney() && $order->status !== OrderStatus::Paid && app('livewire')->isLivewireRequest()) {
            $this->redirectRoute('order.show', ['order' => $order->number]);
        }

        // The first `.slip_path`-free state that applies decides what the customer sees and which controls exist.
        $state = match (true) {
            $order->status === OrderStatus::Paid || $payment?->status === PaymentStatus::Confirmed => 'paid',
            $payment === null => 'none',
            $payment->status === PaymentStatus::Submitted => 'submitted',
            $due->lte(now()) => 'window_closed',
            $payment->status === PaymentStatus::Rejected => 'rejected',
            default => 'pending',
        };

        $canPayShortfall = $payment !== null
            && $payment->status === PaymentStatus::Rejected
            && $payment->slip_amount_satang !== null
            && $payment->slip_amount_satang > 0
            && $payment->slip_amount_satang < $payment->amount_satang;

        return view('livewire.storefront.payment-panel', [
            'order' => $order,
            'payment' => $payment,
            'state' => $state,
            'canPayShortfall' => $canPayShortfall,
            'slipSize' => SlipLimit::label(SlipLimit::kb()),
        ]);
    }

    /**
     * Runs a payment action on the newest payment under the visitor's own access, and shows why it was refused.
     *
     * With `$quietIfPending`, a refusal because the payment already has a successor that waits for money is not shown:
     * the first of two quick clicks did what was asked.
     *
     * @param  callable(Payment): mixed  $action
     */
    private function run(callable $action, bool $quietIfPending = false): void
    {
        $payment = $this->payment;

        try {
            if ($payment === null) {
                throw new PaymentRefused('changed');
            }

            $action($payment);
        } catch (PaymentRefused $refused) {
            unset($this->order, $this->payment);

            if (! ($quietIfPending && $refused->reason === 'changed' && $this->payment?->status === PaymentStatus::Pending)) {
                $this->addError('action', $refused->message());
            }
        } finally {
            unset($this->order, $this->payment);
        }
    }

    private function discardSlip(): void
    {
        // The temporary upload is never needed again: the stored copy, when there is one, was written by SubmitSlip.
        if ($this->slip !== null && method_exists($this->slip, 'delete')) {
            $this->slip->delete();
        }

        $this->reset('slip');
    }
}
