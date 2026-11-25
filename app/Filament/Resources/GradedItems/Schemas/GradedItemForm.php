<?php

namespace App\Filament\Resources\GradedItems\Schemas;

use App\Enums\GradedStatus;
use App\Enums\Grader;
use App\Filament\Forms\Components\MoneyInput;
use App\Models\GradedItem;
use App\Models\Printing;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class GradedItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('printing_id')
                    ->label(__('admin.models.printing.singular'))
                    ->searchable()
                    ->required()
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
                Select::make('grader')
                    ->label(__('admin.fields.grader'))
                    ->options(Grader::class)
                    ->required(),
                TextInput::make('grade')
                    ->label(__('admin.fields.grade'))
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(10)
                    ->step(0.5),
                TextInput::make('grade_label')
                    ->label(__('admin.fields.grade_label'))
                    ->maxLength(255),
                TextInput::make('cert_number')
                    ->label(__('admin.fields.cert_number'))
                    ->helperText(__('admin.hints.cert_number'))
                    ->required(fn (Get $get, ?GradedItem $record): bool => self::mustSatisfyListingRule($get, $record))
                    ->maxLength(255),
                Fieldset::make(__('admin.fields.subgrades'))
                    ->columns(4)
                    ->schema(collect(['centering', 'corners', 'edges', 'surface'])->map(
                        fn (string $name) => TextInput::make('subgrades.'.$name)
                            ->label(__('admin.fields.subgrade_'.$name))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10)
                            ->step(0.5),
                    )->all()),
                MoneyInput::make('price_satang')
                    ->label(__('admin.fields.price'))
                    ->required(),
                FileUpload::make('front_path')
                    ->required(fn (Get $get, ?GradedItem $record): bool => self::mustSatisfyListingRule($get, $record))
                    ->label(__('admin.fields.front_photo'))
                    ->disk('public')
                    ->directory('graded-photos')
                    ->visibility('public')
                    ->image(),
                FileUpload::make('back_path')
                    ->required(fn (Get $get, ?GradedItem $record): bool => self::mustSatisfyListingRule($get, $record))
                    ->label(__('admin.fields.back_photo'))
                    ->disk('public')
                    ->directory('graded-photos')
                    ->visibility('public')
                    ->image(),
                FileUpload::make('label_path')
                    ->required(fn (Get $get, ?GradedItem $record): bool => self::mustSatisfyListingRule($get, $record))
                    ->label(__('admin.fields.label_photo'))
                    ->disk('public')
                    ->directory('graded-photos')
                    ->visibility('public')
                    ->image(),
                // `reserved` and `sold` are written only by the hold and order actions. Staff choose between listed and
                // withdrawn; on a record in a system state the select is locked and just shows it.
                Select::make('status')
                    ->label(__('admin.fields.status'))
                    ->options(fn (?GradedItem $record): array => self::isSystemState($record)
                        ? [$record->status->value => $record->status->label()]
                        : [GradedStatus::Listed->value => GradedStatus::Listed->label(), GradedStatus::Withdrawn->value => GradedStatus::Withdrawn->label()])
                    ->disabled(fn (?GradedItem $record): bool => self::isSystemState($record))
                    ->default(GradedStatus::Withdrawn->value)
                    ->required()
                    ->helperText(__('admin.hints.graded_status'))
                    ->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            // The same rule GradedItem enforces on save, shown here as a message under the field.
                            $complete = collect([...GradedItem::PHOTO_COLUMNS, 'cert_number'])->every(fn (string $column): bool => filled($get($column)));

                            if ($value === GradedStatus::Listed->value && ! $complete) {
                                $fail(__('admin.validation.graded_photos'));
                            }
                        },
                    ]),
                Textarea::make('note_th')
                    ->label(__('admin.fields.note_th'))
                    ->maxLength(255)
                    ->rows(2),
            ]);
    }

    /**
     * Whether the listing rule applies to what is being saved: the chosen status is `listed`, or the record is
     * `reserved` (its status select is locked, so its fields still have to hold the rule). Each field the saving hook
     * reads is `required` under this, so the error lands on the field that was cleared, never as a model exception.
     */
    private static function mustSatisfyListingRule(Get $get, ?GradedItem $record): bool
    {
        return $record?->status === GradedStatus::Reserved || $get('status') === GradedStatus::Listed->value;
    }

    private static function isSystemState(?GradedItem $record): bool
    {
        return $record !== null && in_array($record->status, [GradedStatus::Reserved, GradedStatus::Sold], true);
    }

    /**
     * "OP05 119 Luffy · EN", from the printing as loaded (card or cardWithTrashed).
     */
    private static function printingLabel(Printing $printing): string
    {
        $card = $printing->relationLoaded('cardWithTrashed') ? $printing->cardWithTrashed : $printing->card;

        return $printing->set->code.' '.$printing->number.' '.$card?->name_en.' · '.$printing->language->shortCode();
    }
}
