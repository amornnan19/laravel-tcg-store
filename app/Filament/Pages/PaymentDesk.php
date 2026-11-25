<?php

namespace App\Filament\Pages;

use App\Enums\PaymentStatus;
use App\Filament\Actions\PaymentActions;
use App\Models\Payment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Every slip waiting for a decision, oldest first, each with the slip beside what is owed and the two buttons. Staff
 * clear the queue; there is no search.
 */
class PaymentDesk extends Page
{
    protected string $view = 'filament.pages.payment-desk';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.orders');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.desk.title');
    }

    public function getTitle(): string
    {
        return __('admin.desk.title');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('viewAny', Payment::class);
    }

    /**
     * @return Collection<int, Payment>
     */
    public function submittedPayments(): Collection
    {
        return Payment::query()
            ->with(['order', 'shortfallOf'])
            ->where('status', PaymentStatus::Submitted->value)
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();
    }

    public function confirmAction(): Action
    {
        return PaymentActions::confirm(fn (array $arguments): ?Payment => $this->paymentFrom($arguments), 'confirm');
    }

    public function rejectAction(): Action
    {
        return PaymentActions::reject(fn (array $arguments): ?Payment => $this->paymentFrom($arguments), 'reject');
    }

    /**
     * The id arrives from the browser: it is looked up, never trusted.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function paymentFrom(array $arguments): ?Payment
    {
        return Payment::query()->find($arguments['payment'] ?? null);
    }
}
