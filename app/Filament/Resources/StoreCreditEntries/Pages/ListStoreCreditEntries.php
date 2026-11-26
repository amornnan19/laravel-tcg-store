<?php

namespace App\Filament\Resources\StoreCreditEntries\Pages;

use App\Actions\RecordStoreCredit;
use App\Enums\StoreCreditReason;
use App\Exceptions\InsufficientStoreCredit;
use App\Filament\Forms\Components\MoneyInput;
use App\Filament\Resources\StoreCreditEntries\StoreCreditEntryResource;
use App\Models\StoreCreditEntry;
use App\Models\User;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class ListStoreCreditEntries extends ListRecords
{
    /** One adjustment moves at most ฿1,000,000: a typo cannot put an absurd balance on an account. */
    private const int MAX_ADJUSTMENT_SATANG = 100_000_000;

    protected static string $resource = StoreCreditEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->adjustAction()];
    }

    private function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label(__('admin.credit.adjust'))
            ->icon(Heroicon::OutlinedPlusCircle)
            ->visible(fn (): bool => Gate::allows('adjust', StoreCreditEntry::class))
            ->modalHeading(__('admin.credit.adjust_heading'))
            ->schema([
                Select::make('user_id')
                    ->label(__('admin.credit.fields.user'))
                    ->required()
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => User::query()
                        ->where('is_staff', false)
                        ->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                        ->orderBy('name')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (User $user): array => [$user->getKey() => self::userLabel($user)])
                        ->all())
                    ->getOptionLabelUsing(fn ($value): ?string => ($user = User::query()->where('is_staff', false)->find($value)) === null ? null : self::userLabel($user)),
                Radio::make('direction')
                    ->label(__('admin.credit.fields.direction'))
                    ->options(['add' => __('admin.credit.direction.add'), 'subtract' => __('admin.credit.direction.subtract')])
                    ->default('add')
                    ->inline()
                    ->required(),
                MoneyInput::make('amount_satang')
                    ->label(__('admin.credit.fields.amount'))
                    ->required()
                    ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        try {
                            $satang = Money::fromBaht(trim((string) $value))->satang();
                        } catch (InvalidArgumentException) {
                            return;
                        }

                        if ($satang <= 0) {
                            $fail(__('admin.credit.amount_positive'));
                        } elseif ($satang > self::MAX_ADJUSTMENT_SATANG) {
                            $fail(__('admin.credit.amount_max', ['max' => Money::fromSatang(self::MAX_ADJUSTMENT_SATANG)->format()]));
                        }
                    }]),
                Textarea::make('note')
                    ->label(__('admin.credit.fields.note'))
                    ->required()
                    ->maxLength(255)
                    ->rows(3),
            ])
            ->action(function (array $data): void {
                Gate::authorize('adjust', StoreCreditEntry::class);

                // Customers only: the select offers no staff account, and a posted id is checked here as well.
                $customer = User::query()->where('is_staff', false)->findOrFail($data['user_id']);
                $amount = (int) $data['amount_satang'];

                try {
                    $entry = app(RecordStoreCredit::class)->handle(
                        $customer,
                        $data['direction'] === 'subtract' ? -$amount : $amount,
                        StoreCreditReason::Adjustment,
                        note: (string) $data['note'],
                        staff: auth()->user(),
                    );
                } catch (InsufficientStoreCredit $exception) {
                    Notification::make()
                        ->danger()
                        ->title(__('admin.credit.insufficient', ['balance' => Money::fromSatang($exception->balanceSatang)->format()]))
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('admin.credit.adjusted', ['balance' => Money::fromSatang($entry->balance_after_satang)->format()]))
                    ->send();
            });
    }

    private static function userLabel(User $user): string
    {
        return trim($user->name.' · '.($user->email ?? ''), ' ·');
    }
}
