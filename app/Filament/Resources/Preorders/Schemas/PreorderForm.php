<?php

namespace App\Filament\Resources\Preorders\Schemas;

use App\Filament\Forms\Components\MoneyInput;
use App\Models\Preorder;
use App\Models\SealedProduct;
use App\Support\Money;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use InvalidArgumentException;

/**
 * Staff set up a pre-order of a sealed product marked `is_preorder`: the dates, the deposit and full price, the discount
 * for paying in full, the quota and the limit per account. `reserved_count` is never on the form: only the orders move it.
 * Every rule here is also enforced where the order is placed; a form check is a suggestion.
 */
class PreorderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // One pre-order per product, and only for a product marked as a pre-order.
                Select::make('sealed_product_id')
                    ->label(__('admin.fields.sealed_product'))
                    ->options(fn (?Preorder $record): array => SealedProduct::query()
                        ->where('is_preorder', true)
                        ->where(fn ($query) => $query->whereDoesntHave('preorder')->when($record, fn ($q) => $q->orWhereKey($record->sealed_product_id)))
                        ->orderBy('name_en')
                        ->pluck('name_en', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->getOptionLabelUsing(fn (mixed $value): ?string => SealedProduct::withTrashed()->find($value)?->name_en)
                    ->unique(ignoreRecord: true)
                    ->disabledOn('edit')
                    ->helperText(__('admin.hints.preorder_product')),
                DatePicker::make('release_on')
                    ->label(__('admin.fields.release_on'))
                    ->required()
                    ->native(false)
                    ->live(),
                DatePicker::make('cancel_until')
                    ->label(__('admin.fields.cancel_until'))
                    ->helperText(__('admin.hints.cancel_until'))
                    ->required()
                    ->native(false)
                    ->beforeOrEqual('release_on'),
                MoneyInput::make('deposit_satang')
                    ->label(__('admin.fields.deposit'))
                    ->required()
                    ->rules([fn (): Closure => self::positiveMoney()]),
                MoneyInput::make('full_satang')
                    ->label(__('admin.fields.full_price'))
                    ->required()
                    ->rules([
                        fn (): Closure => self::positiveMoney(),
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (self::satang($value) < self::satang($get('deposit_satang'))) {
                                $fail(__('admin.validation.deposit_above_full'));
                            }
                        },
                    ]),
                TextInput::make('full_discount_bp')
                    ->label(__('admin.fields.full_discount_bp'))
                    ->helperText(__('admin.hints.full_discount_bp'))
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(10000)
                    ->default(0),
                TextInput::make('quota')
                    ->label(__('admin.fields.quota'))
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(2147483647)
                    ->rules([
                        fn (?Preorder $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                            $taken = $record === null ? 0 : (int) Preorder::query()->whereKey($record->getKey())->value('reserved_count');

                            if ((int) $value < $taken) {
                                $fail(__('admin.validation.quota_below_reserved', ['n' => $taken]));
                            }
                        },
                    ]),
                TextInput::make('per_account_limit')
                    ->label(__('admin.fields.per_account_limit'))
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(1000)
                    ->default(1),
                Toggle::make('is_open')
                    ->label(__('admin.fields.preorder_open'))
                    ->helperText(__('admin.hints.preorder_open')),
                DateTimePicker::make('opens_at')
                    ->label(__('admin.fields.opens_at'))
                    ->helperText(__('admin.hints.opens_at'))
                    ->timezone(config('shop.timezone'))
                    ->native(false),
            ]);
    }

    private static function positiveMoney(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (self::satang($value) < 1) {
                $fail(__('admin.validation.money_positive'));
            }
        };
    }

    /**
     * The satang a money field holds, 0 when it does not parse (the field's own rule reports that).
     */
    private static function satang(mixed $value): int
    {
        try {
            return Money::fromBaht(trim((string) $value))->satang();
        } catch (InvalidArgumentException) {
            return 0;
        }
    }
}
