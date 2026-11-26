<?php

namespace App\Filament\Resources\BuylistSubmissions\Schemas;

use App\Enums\BuylistStatus;
use App\Enums\CardCondition;
use App\Models\BuylistSubmission;
use App\Models\BuylistSubmissionItem;
use App\Support\BuylistAssessment;
use App\Support\Money;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * One submission: what the customer sent, then a line per card with the condition they claimed and the one the shop
 * found. Everything but the assessment is read-only, and the assessment only while the submission is `received`; the
 * unit and the totals are worked out again, live, as the conditions change.
 */
class BuylistSubmissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make(__('admin.buylist.submissions.awaiting_customer'))
                    ->description(__('admin.buylist.submissions.awaiting_customer_hint'))
                    ->warning()
                    ->columnSpanFull()
                    ->visible(fn (?Model $record): bool => $record instanceof BuylistSubmission && $record->status === BuylistStatus::Revised),
                Section::make()
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('number')->label(__('admin.buylist.submissions.fields.number')),
                        TextEntry::make('user.name')->label(__('admin.buylist.submissions.fields.customer')),
                        TextEntry::make('status')
                            ->label(__('admin.buylist.submissions.fields.status'))
                            ->badge()
                            ->formatStateUsing(fn (BuylistStatus $state): string => $state->staffLabel()),
                        TextEntry::make('payout')
                            ->label(__('admin.buylist.submissions.fields.payout'))
                            ->formatStateUsing(fn (mixed $state): string => $state->label()),
                        TextEntry::make('quote_expires_at')
                            ->label(__('admin.buylist.submissions.fields.quote_expires_at'))
                            ->dateTime('j M Y', config('shop.timezone')),
                        TextEntry::make('late_note')
                            ->label(__('admin.buylist.submissions.fields.late_note'))
                            ->visible(fn (?Model $record): bool => filled($record?->late_note)),
                        TextEntry::make('payout_reference')
                            ->label(__('admin.buylist.submissions.fields.payout_reference'))
                            ->visible(fn (?Model $record): bool => filled($record?->payout_reference)),
                        TextEntry::make('return_tracking')
                            ->label(__('admin.buylist.submissions.fields.return_tracking'))
                            ->visible(fn (?Model $record): bool => filled($record?->return_tracking)),
                    ]),
                Repeater::make('assessments')
                    ->label(__('admin.buylist.submissions.fields.assessments'))
                    ->columnSpanFull()
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->disabled(fn (?Model $record): bool => $record instanceof BuylistSubmission && $record->status !== BuylistStatus::Received)
                    ->itemLabel(function (array $state, ?Model $record): ?string {
                        $item = self::item($record, $state['id'] ?? null);

                        return $item === null ? null : $item->card_name.' · '.$item->display_code.' · '.__('admin.buylist.submissions.fields.qty', ['n' => $item->qty]);
                    })
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Hidden::make('id'),
                        TextEntry::make('claimed')
                            ->label(__('admin.buylist.submissions.fields.claimed'))
                            ->badge()
                            ->state(fn (Get $get, ?Model $record): ?CardCondition => self::item($record, $get('id'))?->claimed_condition),
                        TextEntry::make('quoted_unit')
                            ->label(__('admin.buylist.submissions.fields.quoted_unit'))
                            ->state(fn (Get $get, ?Model $record): string => Money::fromSatang(self::item($record, $get('id'))?->quoted_unit_satang ?? 0)->format()),
                        Select::make('assessed_condition')
                            ->label(__('admin.buylist.submissions.fields.assessed_condition'))
                            ->options(fn (Get $get, ?Model $record): array => collect(BuylistAssessment::optionsFor(self::item($record, $get('id'))?->claimed_condition ?? CardCondition::Dmg))
                                ->mapWithKeys(fn (CardCondition $condition): array => [$condition->value => $condition->label()])
                                ->all())
                            ->selectablePlaceholder(false)
                            ->required()
                            ->live(),
                        TextEntry::make('assessed_unit')
                            ->label(__('admin.buylist.submissions.fields.assessed_unit'))
                            ->state(fn (Get $get, ?Model $record): string => ($unit = self::unit($record, $get('id'), $get('assessed_condition'))) === null ? '—' : Money::fromSatang($unit)->format())
                            ->helperText(fn (Get $get, ?Model $record): ?string => self::unit($record, $get('id'), $get('assessed_condition')) === 0 ? __('admin.buylist.not_bought') : null),
                        TextInput::make('assessed_note')
                            ->label(__('admin.buylist.submissions.fields.note'))
                            ->maxLength(200),
                        TextEntry::make('line_total')
                            ->label(__('admin.buylist.submissions.fields.line_total'))
                            ->state(fn (Get $get, ?Model $record): string => ($unit = self::unit($record, $get('id'), $get('assessed_condition'))) === null
                                ? '—'
                                : Money::fromSatang($unit * (self::item($record, $get('id'))?->qty ?? 0))->format()),
                        FileUpload::make('photo_path')
                            ->label(__('admin.buylist.submissions.fields.photo'))
                            ->disk('public')
                            ->directory('buylist-photos')
                            ->visibility('public')
                            ->image()
                            ->maxSize(5120)
                            // The drop zone's own text is FilePond's, with no Thai: the admin theme prints this instead.
                            ->extraAttributes(['style' => "--upload-idle-label: '".addcslashes(__('admin.buylist.submissions.upload_idle'), "'\\")."'"])
                            ->columnSpanFull()
                            ->visible(fn (Get $get, ?Model $record): bool => self::isLower($record, $get('id'), $get('assessed_condition')))
                            ->required(fn (Get $get, ?Model $record): bool => self::isLower($record, $get('id'), $get('assessed_condition'))),
                    ]),
                TextEntry::make('totals')
                    ->label(__('admin.buylist.submissions.fields.totals'))
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->size(TextSize::Large)
                    ->weight(FontWeight::Bold)
                    ->state(fn (Get $get, ?Model $record): string => __('admin.buylist.submissions.totals', [
                        'quoted' => Money::fromSatang($record?->quoted_total_satang ?? 0)->format(),
                        'final' => Money::fromSatang(self::newTotal($record, $get('assessments') ?? []))->format(),
                    ])),
            ]);
    }

    /**
     * The line of the submission with this id, from the relation the page loaded once.
     */
    private static function item(?Model $record, mixed $id): ?BuylistSubmissionItem
    {
        if (! $record instanceof BuylistSubmission || $id === null) {
            return null;
        }

        $item = $record->items->firstWhere('id', (int) $id);
        $item?->setRelation('submission', $record);

        return $item;
    }

    /**
     * The unit the line would be paid at under `$condition`; null when there is no such line or condition.
     */
    private static function unit(?Model $record, mixed $id, mixed $condition): ?int
    {
        $item = self::item($record, $id);
        $assessed = CardCondition::tryFrom((string) $condition);

        if ($item === null || $assessed === null) {
            return null;
        }

        try {
            return BuylistAssessment::unitFor($item, $assessed);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function isLower(?Model $record, mixed $id, mixed $condition): bool
    {
        $item = self::item($record, $id);
        $assessed = CardCondition::tryFrom((string) $condition);

        return $item !== null && $assessed !== null && BuylistAssessment::isLower($item->claimed_condition, $assessed);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $assessments  The repeater state.
     */
    public static function newTotal(?Model $record, array $assessments): int
    {
        return (int) collect($assessments)->sum(fn (array $row): int => (self::unit($record, $row['id'] ?? null, $row['assessed_condition'] ?? null) ?? 0)
            * (self::item($record, $row['id'] ?? null)?->qty ?? 0));
    }
}
