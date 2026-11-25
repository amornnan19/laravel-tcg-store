<?php

namespace App\Actions;

use App\Actions\Concerns\LocksOrderForPayment;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentRefused;
use App\Exceptions\SlipNotStored;
use App\Models\Payment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Attaches the customer's slip to a pending payment and hands it to staff. Runs under the order's row lock, the one
 * `CancelOrder`'s guard reads: an order that was cancelled a moment ago refuses the slip and the file is not kept.
 */
class SubmitSlip
{
    use LocksOrderForPayment;

    /**
     * @throws SlipNotStored when the file did not reach the disk; nothing is marked submitted.
     * @throws PaymentRefused when the order is no longer waiting for money, the window has closed or the payment is
     *                        not pending.
     */
    public function handle(Payment $payment, UploadedFile $slip): Payment
    {
        return DB::transaction(function () use ($payment, $slip): Payment {
            $order = $this->lockOpenOrder($payment);
            $locked = $this->lockPayment($payment, PaymentStatus::Pending);

            // Only now, with every refusal behind us, does the file reach the disk.
            $path = $slip->store('slips/'.$order->number, 'local');

            // On the `local` disk a failed write still returns the path, so the file itself is checked.
            if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
                throw new SlipNotStored("The slip for {$order->number} could not be stored.");
            }

            try {
                $locked->update([
                    'slip_path' => $path,
                    'status' => PaymentStatus::Submitted,
                    'submitted_at' => now(),
                ]);
            } catch (Throwable $exception) {
                Storage::disk('local')->delete($path);

                throw $exception;
            }

            return $locked;
        });
    }
}
