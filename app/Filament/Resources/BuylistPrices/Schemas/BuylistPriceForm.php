<?php

namespace App\Filament\Resources\BuylistPrices\Schemas;

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Filament\Forms\Components\MoneyInput;
use App\Models\Printing;
use App\Support\Money;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class BuylistPriceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('printing_id')
                    ->label(__('admin.models.printing.singular'))
                    ->searchable()
                    ->required()
                    ->live()
                    // What a row is (printing, condition, finish) is set once: the submissions and the quote signature name it.
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->getSearchResultsUsing(fn (string $search): array => Printing::query()
                        ->with(['set', 'card'])
                        ->matchingText($search)
                        ->orderBy('id')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Printing $printing): array => [$printing->getKey() => self::printingLabel($printing)])
                        ->all())
                    // A printing or its card may be in the trash later: the label still has to render.
                    ->getOptionLabelUsing(fn (mixed $value): ?string => ($printing = Printing::withTrashed()->with(['set', 'cardWithTrashed'])->find($value)) === null
                        ? null
                        : self::printingLabel($printing)),
                Select::make('condition')
                    ->label(__('admin.fields.condition'))
                    ->options(CardCondition::class)
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->default(CardCondition::Nm->value),
                Select::make('finish')
                    ->label(__('admin.fields.finish'))
                    ->options(fn (Get $get): array => self::finishOptions($get('printing_id')))
                    ->helperText(__('admin.buylist.hints.finish'))
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->default(Finish::Normal->value)
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $printingId = $get('printing_id');

                            if ($value === Finish::Foil->value && $printingId !== null && ! Printing::withTrashed()->whereKey($printingId)->value('has_foil')) {
                                $fail(__('admin.buylist.validation.foil'));
                            }
                        },
                    ])
                    ->unique(
                        table: 'buylist_prices',
                        column: 'finish',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                            ->where('printing_id', $get('printing_id'))
                            ->where('condition', $get('condition')),
                    )
                    ->validationMessages(['unique' => __('admin.buylist.validation.taken')]),
                MoneyInput::make('cash_satang')
                    ->label(__('admin.buylist.fields.cash'))
                    ->required()
                    ->rules([self::positiveRule()]),
                MoneyInput::make('credit_satang')
                    ->label(__('admin.buylist.fields.credit'))
                    ->helperText(fn (): string => __('admin.buylist.hints.credit', ['percent' => self::bonusPercent()]))
                    ->required(fn (string $operation): bool => $operation === 'edit')
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $cash = $get('cash_satang');

                            if (! blank($value) && ! blank($cash) && is_numeric($value) && is_numeric($cash) && self::satang((string) $value) < self::satang((string) $cash)) {
                                $fail(__('admin.buylist.validation.credit_below_cash'));
                            }
                        },
                    ]),
                TextInput::make('weekly_quota')
                    ->label(__('admin.buylist.fields.weekly_quota'))
                    ->helperText(__('admin.buylist.hints.weekly_quota'))
                    ->integer()
                    ->minValue(0)
                    ->maxValue(2147483647),
                Toggle::make('is_active')
                    ->label(__('admin.buylist.fields.is_active'))
                    ->default(true),
            ]);
    }

    /**
     * Foil is offered only for a printing that exists in foil.
     *
     * @return array<string, string>
     */
    public static function finishOptions(mixed $printingId): array
    {
        $hasFoil = $printingId !== null && Printing::withTrashed()->whereKey($printingId)->value('has_foil');

        return collect(Finish::cases())
            ->filter(fn (Finish $finish): bool => $finish === Finish::Normal || $hasFoil)
            ->mapWithKeys(fn (Finish $finish): array => [$finish->value => $finish->label()])
            ->all();
    }

    public static function printingLabel(?Printing $printing): ?string
    {
        if ($printing === null) {
            return null;
        }

        $card = $printing->relationLoaded('cardWithTrashed') ? $printing->cardWithTrashed : $printing->card;

        return $printing->set->code.' '.$printing->number.' '.$card?->name_en.' · '.$printing->language->shortCode();
    }

    /**
     * The credit bonus as shown to staff: `15`, or `7.5`.
     */
    public static function bonusPercent(): string
    {
        return rtrim(rtrim(number_format(((int) config('buylist.credit_bonus_bp')) / 100, 2, '.', ''), '0'), '.');
    }

    /**
     * A baht string the form holds, in satang, for comparing two of them.
     */
    private static function satang(string $baht): int
    {
        try {
            return Money::fromBaht(trim($baht))->satang();
        } catch (\InvalidArgumentException) {
            return 0;
        }
    }

    /**
     * Wrapped in a closure that takes nothing: Filament evaluates a bare rule closure as it does a callback and cannot
     * resolve its `$attribute`.
     *
     * @return Closure(): Closure
     */
    private static function positiveRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (is_numeric($value) && (float) $value <= 0) {
                $fail(__('admin.buylist.validation.cash_positive'));
            }
        };
    }
}
