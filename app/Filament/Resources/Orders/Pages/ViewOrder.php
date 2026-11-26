<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Actions\CancelOrder;
use App\Actions\DeliverOrder;
use App\Actions\PackOrder;
use App\Actions\RefundOrder;
use App\Actions\ShipOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Actions\PaymentActions;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * The order desk: one page where staff read the order, decide on its slip and move it on. A button is shown only when
 * its transition is legal for the order as loaded; every action re-checks under the order's row lock.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [
            $this->packAction(),
            $this->shipAction(),
            $this->deliverAction(),
            PaymentActions::confirm(fn (): ?Payment => $this->submittedPayment())
                ->visible(fn (): bool => $this->submittedPayment() !== null),
            PaymentActions::reject(fn (): ?Payment => $this->submittedPayment())
                ->visible(fn (): bool => $this->submittedPayment() !== null),
            $this->notesAction(),
            $this->cancelAction(),
            $this->refundAction(),
        ];

        // The actions write the order through their own locked copy; the page's record is reloaded so the buttons and
        // sections it renders next show the new state, not the one it was opened with.
        return array_map(fn (Action $action): Action => $action->after(fn () => $this->orderRecord()->refresh()), $actions);
    }

    /**
     * The order's slip waiting for staff, if any.
     */
    private function submittedPayment(): ?Payment
    {
        return $this->orderRecord()->payments()->where('status', PaymentStatus::Submitted->value)->latest('id')->first();
    }

    /**
     * For a pre-order, which side of `cancel_until` the refund falls on, so staff know what the amount above is.
     */
    private static function depositRule(Order $order): string
    {
        if (! $order->isPreorder() || $order->cancelUntilEnd() === null) {
            return '';
        }

        $date = $order->cancelUntilEnd()->locale('th')->translatedFormat('j M Y');

        return ' '.($order->isDepositRefundable()
            ? __('admin.orders.deposit_refunded', ['date' => $date])
            : __('admin.orders.deposit_kept', ['date' => $date, 'amount' => Money::fromSatang(min($order->deposit_satang, $order->receivedSatang()))->format()]));
    }

    /**
     * The store credit this order spent and has not yet got back, which goes to the customer's account when it is closed.
     */
    private static function creditRule(Order $order): string
    {
        return $order->credit_used_satang > 0 && $order->credit_returned_at === null
            ? ' '.__('admin.orders.return_credit', ['amount' => Money::fromSatang($order->credit_used_satang)->format()])
            : '';
    }

    /**
     * A pre-order cannot be packed before its release date, so its pack button waits for it; every other order is ready.
     */
    private function isReleased(Order $order): bool
    {
        return ! $order->isPreorder() || $order->preorder === null || $order->preorder->releaseAt()->lte(now());
    }

    private function orderRecord(): Order
    {
        /** @var Order $order */
        $order = $this->getRecord();

        return $order;
    }

    private function packAction(): Action
    {
        return Action::make('pack')
            ->label(__('admin.orders.actions.pack'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->visible(fn (): bool => $this->orderRecord()->status === OrderStatus::Paid && $this->isReleased($this->orderRecord()))
            ->requiresConfirmation()
            ->action(function (): void {
                Gate::authorize('update', $this->orderRecord());

                PaymentActions::attempt(function (): void {
                    app(PackOrder::class)->handle($this->orderRecord());

                    Notification::make()->success()->title(__('admin.orders.done.pack'))->send();
                });
            });
    }

    private function shipAction(): Action
    {
        return Action::make('ship')
            ->label(__('admin.orders.actions.ship'))
            ->icon(Heroicon::OutlinedTruck)
            ->visible(fn (): bool => $this->orderRecord()->status === OrderStatus::Packing)
            ->modalHeading(__('admin.orders.actions.ship_heading'))
            ->modalDescription(fn (): string => __('admin.orders.actions.ship_description', ['courier' => $this->orderRecord()->courier->label()]))
            ->schema([
                TextInput::make('tracking_number')
                    ->label(__('admin.orders.fields.tracking_number'))
                    ->required()
                    ->maxLength(100),
            ])
            ->action(function (array $data): void {
                Gate::authorize('update', $this->orderRecord());

                PaymentActions::attempt(function () use ($data): void {
                    app(ShipOrder::class)->handle($this->orderRecord(), (string) $data['tracking_number']);

                    Notification::make()->success()->title(__('admin.orders.done.ship'))->send();
                });
            });
    }

    private function deliverAction(): Action
    {
        return Action::make('deliver')
            ->label(__('admin.orders.actions.deliver'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->visible(fn (): bool => $this->orderRecord()->status === OrderStatus::Shipped)
            ->requiresConfirmation()
            ->action(function (): void {
                Gate::authorize('update', $this->orderRecord());

                PaymentActions::attempt(function (): void {
                    app(DeliverOrder::class)->handle($this->orderRecord());

                    Notification::make()->success()->title(__('admin.orders.done.deliver'))->send();
                });
            });
    }

    private function notesAction(): Action
    {
        return Action::make('editNotes')
            ->label(__('admin.orders.actions.notes'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalHeading(__('admin.orders.actions.notes'))
            ->fillForm(fn (): array => [
                'tracking_number' => $this->orderRecord()->tracking_number,
                'staff_note' => $this->orderRecord()->staff_note,
            ])
            ->schema([
                TextInput::make('tracking_number')
                    ->label(__('admin.orders.fields.tracking_number'))
                    ->helperText(__('admin.orders.hints.tracking_number'))
                    ->visible(fn (): bool => in_array($this->orderRecord()->status, [OrderStatus::Shipped, OrderStatus::Delivered], true))
                    ->required()
                    ->maxLength(100),
                Textarea::make('staff_note')
                    ->label(__('admin.orders.fields.staff_note'))
                    ->maxLength(2000)
                    ->rows(4),
            ])
            ->action(function (array $data): void {
                Gate::authorize('update', $this->orderRecord());

                $changes = ['staff_note' => filled($data['staff_note'] ?? null) ? $data['staff_note'] : null];

                if (array_key_exists('tracking_number', $data)) {
                    $changes['tracking_number'] = trim((string) $data['tracking_number']);
                }

                $this->orderRecord()->update($changes);

                Notification::make()->success()->title(__('admin.orders.done.notes'))->send();
            });
    }

    private function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('admin.orders.actions.cancel'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (): bool => in_array($this->orderRecord()->status, [OrderStatus::AwaitingPayment, OrderStatus::AwaitingBalance, OrderStatus::Paid], true)
                && $this->submittedPayment() === null)
            ->requiresConfirmation()
            ->modalHeading(__('admin.orders.actions.cancel_heading'))
            ->modalDescription(function (): string {
                $order = $this->orderRecord();
                $owed = $order->refundOwedSatang();

                return ($order->isPreorder() ? __('admin.orders.actions.cancel_description_preorder') : __('admin.orders.actions.cancel_description'))
                    .($owed > 0 ? ' '.__('admin.orders.return_money', ['amount' => Money::fromSatang($owed)->format()]) : '')
                    .self::creditRule($order)
                    .self::depositRule($order);
            })
            ->schema([
                Textarea::make('reason')
                    ->label(__('admin.orders.fields.reason'))
                    ->required()
                    ->maxLength(500)
                    ->rows(3),
            ])
            ->action(function (array $data): void {
                Gate::authorize('update', $this->orderRecord());

                PaymentActions::attempt(function () use ($data): void {
                    $cancelled = app(CancelOrder::class)->handle($this->orderRecord(), trim((string) $data['reason']));

                    $received = $cancelled?->refundOwedSatang() ?? 0;
                    $credit = $cancelled !== null && $cancelled->credit_returned_at !== null ? $cancelled->credit_used_satang : 0;

                    Notification::make()
                        ->success()
                        ->title(__('admin.orders.done.cancel'))
                        ->body(collect([
                            $received > 0 ? __('admin.orders.return_money', ['amount' => Money::fromSatang($received)->format()]) : null,
                            $credit > 0 ? __('admin.orders.return_credit', ['amount' => Money::fromSatang($credit)->format()]) : null,
                        ])->filter()->implode(' ') ?: null)
                        ->persistent($received > 0 || $credit > 0)
                        ->send();
                });
            });
    }

    private function refundAction(): Action
    {
        return Action::make('refund')
            ->label(__('admin.orders.actions.refund'))
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('danger')
            ->visible(fn (): bool => in_array($this->orderRecord()->status, [OrderStatus::Paid, OrderStatus::Packing], true))
            ->requiresConfirmation()
            ->modalHeading(__('admin.orders.actions.refund_heading'))
            ->modalDescription(fn (): string => __($this->orderRecord()->isPreorder() ? 'admin.orders.actions.refund_description_preorder' : 'admin.orders.actions.refund_description', [
                'amount' => Money::fromSatang($this->orderRecord()->refundOwedSatang())->format(),
            ]).self::creditRule($this->orderRecord()).self::depositRule($this->orderRecord()))
            ->schema([
                Textarea::make('reason')
                    ->label(__('admin.orders.fields.reason'))
                    ->required()
                    ->maxLength(500)
                    ->rows(3),
            ])
            ->action(function (array $data): void {
                Gate::authorize('update', $this->orderRecord());

                PaymentActions::attempt(function () use ($data): void {
                    $result = app(RefundOrder::class)->handle($this->orderRecord(), (string) $data['reason']);

                    Notification::make()
                        ->success()
                        ->title(__('admin.orders.done.refund'))
                        ->body(collect([
                            __('admin.orders.return_money', ['amount' => Money::fromSatang($result->refundedSatang)->format()]),
                            $result->creditReturnedSatang > 0 ? __('admin.orders.return_credit', ['amount' => Money::fromSatang($result->creditReturnedSatang)->format()]) : null,
                        ])->filter()->implode(' '))
                        ->persistent()
                        ->send();
                });
            });
    }
}
