<?php

namespace App\Filament\Resources\Printings\Schemas;

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Filament\Forms\Components\MoneyInput;
use App\Models\Card;
use App\Models\CardSet;
use App\Models\Printing;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class PrintingForm
{
    /**
     * The rarity codes the seeder maps every game onto.
     *
     * @var list<string>
     */
    public const RARITY_CODES = ['c', 'u', 'r', 'rr', 'sar', 'sec'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('card_id')
                    ->label(__('admin.fields.card'))
                    ->relationship('card', 'name_en')
                    ->getOptionLabelUsing(fn (mixed $value): ?string => Card::withTrashed()->find($value)?->name_en)
                    ->searchable()
                    ->required()
                    ->rules([
                        fn (?Model $record): Closure => self::liveCardRule($record),
                    ]),
                Select::make('set_id')
                    ->label(__('admin.fields.set'))
                    ->relationship('set', 'name_en')
                    ->getOptionLabelFromRecordUsing(fn (CardSet $record): string => $record->code.' · '.$record->name_en)
                    ->searchable(['code', 'name_en'])
                    ->preload()
                    ->required(),
                TextInput::make('number')
                    ->label(__('admin.fields.number'))
                    ->required()
                    ->maxLength(255)
                    ->rules([
                        fn (Get $get, ?Model $record): Closure => self::uniquePrintingRule($get, $record),
                    ]),
                Select::make('language')
                    ->label(__('admin.fields.language'))
                    ->options(Language::class)
                    ->required(),
                Select::make('rarity_code')
                    ->label(__('admin.fields.rarity_code'))
                    ->options(collect(self::RARITY_CODES)->mapWithKeys(
                        fn (string $code): array => [$code => strtoupper($code)],
                    )->all())
                    ->required(),
                DatePicker::make('released_on')
                    ->label(__('admin.fields.released_on')),
                Toggle::make('has_foil')
                    ->label(__('admin.fields.has_foil')),
                Repeater::make('variants')
                    ->label(__('admin.fields.variants'))
                    ->helperText(__('admin.hints.variants'))
                    ->hiddenOn('create')
                    ->columnSpanFull()
                    ->relationship('variants')
                    ->defaultItems(0)
                    ->addActionLabel(__('admin.actions.add_variant'))
                    ->itemLabel(fn (array $state): ?string => self::variantLabel($state))
                    ->deleteAction(fn (Action $action): Action => $action
                        ->requiresConfirmation()
                        ->modalHeading(fn (array $arguments, Repeater $component): string => __('admin.actions.delete_variant_heading', [
                            'name' => self::variantLabel($component->getRawItemState($arguments['item'])) ?? '',
                        ]))
                        ->modalDescription(__('admin.actions.delete_variant_description')))
                    ->rules([
                        fn (): Closure => self::distinctVariantsRule(),
                    ])
                    ->table([
                        TableColumn::make(__('admin.fields.condition')),
                        TableColumn::make(__('admin.fields.finish')),
                        TableColumn::make(__('admin.fields.price')),
                        TableColumn::make(__('admin.fields.qty')),
                        TableColumn::make(__('admin.fields.has_real_photo')),
                        TableColumn::make(__('admin.fields.compare_at')),
                        TableColumn::make(__('admin.fields.note_th')),
                    ])
                    ->schema([
                        // Condition and finish identify the variant (SKU, unique index), so an existing row keeps them.
                        Select::make('condition')
                            ->label(__('admin.fields.condition'))
                            ->options(CardCondition::class)
                            ->required()
                            ->disabled(fn (?Model $record): bool => $record?->exists === true)
                            ->dehydrated()
                            ->dehydrateStateUsing(fn (mixed $state, ?Model $record): mixed => $record?->exists === true ? $record->condition : $state),
                        Select::make('finish')
                            ->label(__('admin.fields.finish'))
                            ->options(Finish::class)
                            ->required()
                            ->disabled(fn (?Model $record): bool => $record?->exists === true)
                            ->dehydrated()
                            ->dehydrateStateUsing(fn (mixed $state, ?Model $record): mixed => $record?->exists === true ? $record->finish : $state),
                        MoneyInput::make('price_satang')
                            ->label(__('admin.fields.price')),
                        TextInput::make('qty')
                            ->label(__('admin.fields.qty'))
                            ->required()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(2147483647)
                            ->default(0),
                        Toggle::make('has_real_photo')
                            ->label(__('admin.fields.has_real_photo')),
                        MoneyInput::make('compare_at_satang')
                            ->label(__('admin.fields.compare_at')),
                        TextInput::make('note_th')
                            ->label(__('admin.fields.note_th'))
                            ->maxLength(255),
                    ]),
            ]);
    }

    /**
     * A trashed card cannot be picked, but a printing already attached to one can be saved unchanged.
     */
    private static function liveCardRule(?Model $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if ($record !== null && (string) $record->getAttribute('card_id') === (string) $value) {
                return;
            }

            if (Card::onlyTrashed()->whereKey($value)->exists()) {
                $fail(__('admin.validation.card_trashed'));
            }
        };
    }

    /**
     * The (set, number, language) index also covers soft-deleted rows, so a trashed printing
     * holding the combination gets its own message: restore it instead of inserting.
     */
    private static function uniquePrintingRule(Get $get, ?Model $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
            $setId = $get('set_id');
            $language = self::enumValue($get('language'));

            if (blank($setId) || blank($language)) {
                return;
            }

            $holder = Printing::withTrashed()
                ->where('set_id', $setId)
                ->where('number', $value)
                ->where('language', $language)
                ->when($record?->getKey(), fn ($query, $key) => $query->whereKeyNot($key))
                ->first();

            if ($holder === null) {
                return;
            }

            $fail($holder->trashed()
                ? __('admin.validation.printing_trashed')
                : __('admin.validation.printing_taken'));
        };
    }

    /**
     * Two repeater rows with the same condition and finish would hit the unique index.
     */
    private static function distinctVariantsRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $seen = [];

            foreach ((array) $value as $item) {
                $key = self::enumValue($item['condition'] ?? null).'|'.self::enumValue($item['finish'] ?? null);

                if (isset($seen[$key])) {
                    $fail(__('admin.validation.variant_duplicate'));

                    return;
                }

                $seen[$key] = true;
            }
        };
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function variantLabel(array $state): ?string
    {
        $condition = CardCondition::tryFrom(self::enumValue($state['condition'] ?? null) ?? '');
        $finish = Finish::tryFrom(self::enumValue($state['finish'] ?? null) ?? '');

        if ($condition === null || $finish === null) {
            return null;
        }

        return $condition->label().' · '.$finish->label();
    }

    private static function enumValue(mixed $value): ?string
    {
        return $value instanceof BackedEnum ? (string) $value->value : ($value === null ? null : (string) $value);
    }
}
