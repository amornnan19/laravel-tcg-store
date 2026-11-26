<?php

namespace App\Filament\Resources\SealedProducts\Schemas;

use App\Enums\SealedKind;
use App\Filament\Forms\Components\MoneyInput;
use App\Models\CardSet;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class SealedProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('kind')
                    ->label(__('admin.fields.kind'))
                    ->options(SealedKind::class)
                    ->required()
                    ->live(),
                // A generic accessory (sleeves, a deck box) belongs to no game; everything else does.
                Select::make('game_id')
                    ->label(__('admin.fields.game'))
                    ->relationship('game', 'name_th')
                    ->searchable()
                    ->preload()
                    ->required(fn (Get $get): bool => self::kindOf($get('kind')) !== SealedKind::Accessory)
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('set_id', null)),
                // Only the sets of the chosen game, and the rule below holds it server-side too: a client can send any id.
                Select::make('set_id')
                    ->label(__('admin.fields.set'))
                    ->options(fn (Get $get): array => blank($get('game_id')) ? [] : CardSet::query()
                        ->where('game_id', $get('game_id'))
                        ->orderBy('sort')
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (CardSet $set): array => [$set->getKey() => $set->code.' · '.$set->name_en])
                        ->all())
                    ->searchable()
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (filled($value) && ! CardSet::query()->whereKey($value)->where('game_id', $get('game_id'))->exists()) {
                                $fail(__('admin.validation.set_of_game'));
                            }
                        },
                    ]),
                TextInput::make('name_en')
                    ->label(__('admin.fields.name_en'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('name_th')
                    ->label(__('admin.fields.name_th'))
                    ->maxLength(255),
                // Unique over every row, trashed ones included: the index counts them.
                TextInput::make('sku')
                    ->label(__('admin.fields.sku'))
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('slug')
                    ->label(__('admin.fields.slug'))
                    ->helperText(__('admin.hints.slug'))
                    ->visibleOn('edit')
                    ->disabled()
                    ->dehydrated(false),
                MoneyInput::make('price_satang')
                    ->label(__('admin.fields.price'))
                    ->helperText(__('admin.hints.sealed_price')),
                TextInput::make('qty')
                    ->label(__('admin.fields.qty'))
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(2147483647)
                    ->default(0),
                FileUpload::make('image_path')
                    ->label(__('admin.fields.product_image'))
                    ->disk('public')
                    ->directory('sealed-photos')
                    ->visibility('public')
                    ->image(),
                Textarea::make('description_th')
                    ->label(__('admin.fields.description_th'))
                    ->rows(4)
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->default(true),
                Toggle::make('is_preorder')
                    ->label(__('admin.fields.is_preorder'))
                    ->helperText(__('admin.hints.is_preorder')),
            ]);
    }

    /**
     * The kind select hands its state over as the enum once the form is filled, and as a string before.
     */
    private static function kindOf(mixed $state): ?SealedKind
    {
        return $state instanceof SealedKind ? $state : SealedKind::tryFrom((string) $state);
    }
}
