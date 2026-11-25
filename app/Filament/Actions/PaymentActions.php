<?php

namespace App\Filament\Actions;

use App\Actions\ConfirmPayment;
use App\Actions\RejectPayment;
use App\Enums\PaymentStatus;
use App\Enums\RejectReason;
use App\Exceptions\IllegalOrderTransition;
use App\Exceptions\PaymentRefused;
use App\Exceptions\SlipRefAlreadyCredited;
use App\Filament\Forms\Components\MoneyInput;
use App\Models\Payment;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The two decisions on a submitted slip, shared by the order's page and the payment desk. Each action is built with a
 * resolver that turns the action's arguments into the payment it is about: the desk passes the id as an argument, which
 * the client controls, so the payment is always re-read here, must still be `submitted`, and is authorised before use.
 */
final class PaymentActions
{
    /**
     * @param  Closure(array<string, mixed>): ?Payment  $resolve
     */
    public static function confirm(Closure $resolve, string $name = 'confirmPayment'): Action
    {
        return Action::make($name)
            ->label(__('admin.payment.confirm'))
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->modalHeading(__('admin.payment.confirm_heading'))
            ->modalDescription(fn (array $arguments): ?string => self::dueDescription(self::open($resolve, $arguments)))
            ->modalSubmitActionLabel(__('admin.payment.confirm'))
            ->schema([
                TextInput::make('slip_ref')
                    ->label(__('admin.payment.slip_ref'))
                    ->helperText(__('admin.payment.slip_ref_hint'))
                    ->maxLength(100),
            ])
            ->action(function (array $data, array $arguments) use ($resolve): void {
                self::attempt(function () use ($resolve, $arguments, $data): void {
                    $payment = self::open($resolve, $arguments, authorize: true);

                    if ($payment === null) {
                        throw new PaymentRefused('changed');
                    }

                    $confirmed = app(ConfirmPayment::class)->handle($payment, $data['slip_ref'] ?? null);

                    Notification::make()
                        ->success()
                        ->title(__($confirmed->order()->first()?->isPaid() ? 'admin.payment.confirmed_paid' : 'admin.payment.confirmed_partial'))
                        ->send();
                });
            });
    }

    /**
     * @param  Closure(array<string, mixed>): ?Payment  $resolve
     */
    public static function reject(Closure $resolve, string $name = 'rejectPayment'): Action
    {
        return Action::make($name)
            ->label(__('admin.payment.reject'))
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->modalHeading(__('admin.payment.reject_heading'))
            ->modalDescription(fn (array $arguments): ?string => self::dueDescription(self::open($resolve, $arguments)))
            ->modalSubmitActionLabel(__('admin.payment.reject'))
            ->schema(function (array $arguments) use ($resolve): array {
                $payment = self::open($resolve, $arguments);
                $due = $payment?->amount_satang ?? 0;

                return [
                    Select::make('reason')
                        ->label(__('admin.payment.reason'))
                        ->options(collect(RejectReason::cases())->mapWithKeys(fn (RejectReason $reason): array => [$reason->value => $reason->label()])->all())
                        ->default(RejectReason::AmountMismatch->value)
                        ->required()
                        ->native(false)
                        ->live(),
                    MoneyInput::make('slip_amount')
                        ->label(__('admin.payment.slip_amount'))
                        ->helperText(__('admin.payment.slip_amount_hint'))
                        ->visible(fn (Get $get): bool => $get('reason') === RejectReason::AmountMismatch->value)
                        ->required(fn (Get $get): bool => $get('reason') === RejectReason::AmountMismatch->value)
                        ->rules([
                            fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($due): void {
                                try {
                                    $slip = Money::fromBaht(trim((string) $value))->satang();
                                } catch (InvalidArgumentException) {
                                    return;
                                }

                                if ($slip <= 0 || $slip >= $due) {
                                    $fail(__('admin.payment.slip_amount_range', ['due' => Money::fromSatang($due)->format()]));
                                }
                            },
                        ])
                        ->live(onBlur: true),
                    TextInput::make('slip_ref')
                        ->label(__('admin.payment.slip_ref'))
                        ->helperText(__('admin.payment.slip_ref_hint'))
                        ->visible(fn (Get $get): bool => $get('reason') === RejectReason::AmountMismatch->value)
                        ->maxLength(100),
                    Text::make(function (Get $get) use ($due): string {
                        $slip = self::slipSatang($get('slip_amount'));

                        return $slip !== null && $slip > 0 && $slip < $due
                            ? __('admin.payment.reasons.amount_mismatch')."\n".RejectPayment::shortDetail($due, $slip)
                            : __('admin.payment.preview_waiting');
                    })
                        ->extraAttributes(['class' => 'whitespace-pre-line'])
                        ->visible(fn (Get $get): bool => $get('reason') === RejectReason::AmountMismatch->value),
                    Textarea::make('note')
                        ->label(__('admin.payment.reason_text'))
                        ->visible(fn (Get $get): bool => $get('reason') === RejectReason::Other->value)
                        ->required(fn (Get $get): bool => $get('reason') === RejectReason::Other->value)
                        ->maxLength(500)
                        ->rows(3),
                ];
            })
            ->action(function (array $data, array $arguments) use ($resolve): void {
                self::attempt(function () use ($resolve, $arguments, $data): void {
                    $payment = self::open($resolve, $arguments, authorize: true);

                    if ($payment === null) {
                        throw new PaymentRefused('changed');
                    }

                    app(RejectPayment::class)->handle(
                        $payment,
                        RejectReason::from($data['reason']),
                        $data['note'] ?? null,
                        self::slipSatang($data['slip_amount'] ?? null),
                        $data['slip_ref'] ?? null,
                    );

                    Notification::make()->success()->title(__('admin.payment.rejected_done'))->send();
                });
            });
    }

    /**
     * Runs a staff action and turns the refusals the actions throw into a notification instead of an error page.
     */
    public static function attempt(Closure $work): void
    {
        try {
            $work();
        } catch (PaymentRefused $refused) {
            self::refuse($refused->message());
        } catch (SlipRefAlreadyCredited $credited) {
            self::refuse($credited->message());
        } catch (IllegalOrderTransition) {
            self::refuse(__('admin.orders.illegal_transition'));
        } catch (InvalidArgumentException $invalid) {
            self::refuse($invalid->getMessage());
        }
    }

    private static function refuse(string $message): void
    {
        Notification::make()->danger()->title($message)->persistent()->send();
    }

    /**
     * The payment, re-read, only while it is still `submitted`.
     *
     * @param  Closure(array<string, mixed>): ?Payment  $resolve
     * @param  array<string, mixed>  $arguments
     */
    private static function open(Closure $resolve, array $arguments, bool $authorize = false): ?Payment
    {
        $payment = $resolve($arguments);

        if ($payment === null) {
            return null;
        }

        if ($authorize) {
            Gate::authorize('update', $payment);
        }

        $fresh = Payment::query()->with('order')->find($payment->getKey());

        return $fresh?->status === PaymentStatus::Submitted ? $fresh : null;
    }

    private static function dueDescription(?Payment $payment): ?string
    {
        return $payment === null
            ? null
            : __('admin.payment.due_line', ['number' => $payment->order->number, 'due' => Money::fromSatang($payment->amount_satang)->format()]);
    }

    /**
     * The satang of a baht amount typed or already dehydrated, null when blank or not a valid amount.
     */
    private static function slipSatang(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        try {
            return Money::fromBaht(trim((string) $value))->satang();
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
