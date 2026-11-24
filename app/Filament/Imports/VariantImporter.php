<?php

namespace App\Filament\Imports;

use App\Actions\BulkEditVariants;
use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Models\Variant;
use App\Support\Money;
use Closure;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use InvalidArgumentException;

/**
 * Updates the price and quantity of variants that already exist. A row that matches nothing, or
 * more than one variant, fails with a reason; this importer never creates a row.
 */
class VariantImporter extends Importer
{
    protected static ?string $model = Variant::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('sku')
                ->label(__('admin.fields.sku'))
                ->example('OP05-119-JA-NM-F')
                ->rules(['nullable', 'string', 'max:255']),
            ImportColumn::make('set_code')
                ->label(__('admin.fields.code'))
                ->example('OP05')
                ->rules(['nullable', 'string', 'max:255']),
            ImportColumn::make('number')
                ->label(__('admin.fields.number'))
                ->example('119')
                ->rules(['nullable', 'string', 'max:255']),
            ImportColumn::make('language')
                ->label(__('admin.fields.language'))
                ->example('ja')
                ->castStateUsing(fn (?string $state): ?string => self::lowerCase($state))
                ->rules(['nullable', 'in:'.implode(',', array_column(Language::cases(), 'value'))]),
            ImportColumn::make('condition')
                ->label(__('admin.fields.condition'))
                ->example('nm')
                ->castStateUsing(fn (?string $state): ?string => self::lowerCase($state))
                ->rules(['nullable', 'in:'.implode(',', array_column(CardCondition::cases(), 'value'))]),
            ImportColumn::make('finish')
                ->label(__('admin.fields.finish'))
                ->example('foil')
                ->castStateUsing(fn (?string $state): ?string => self::lowerCase($state))
                ->rules(['nullable', 'in:'.implode(',', array_column(Finish::cases(), 'value'))]),
            ImportColumn::make('price')
                ->label(__('admin.fields.price'))
                ->example('4290.50')
                ->helperText(__('admin.hints.import_price'))
                ->rules([
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (blank($value)) {
                            return;
                        }

                        try {
                            Money::fromBaht(trim((string) $value));
                        } catch (InvalidArgumentException) {
                            $fail(__('admin.validation.money'));
                        }
                    },
                ]),
            ImportColumn::make('qty')
                ->label(__('admin.fields.qty'))
                ->example('3')
                ->rules([
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (blank($value)) {
                            return;
                        }

                        if (preg_match('/^\d{1,10}\z/', trim((string) $value)) !== 1 || (int) $value > BulkEditVariants::MAX_QTY) {
                            $fail(__('admin.validation.qty'));
                        }
                    },
                ]),
        ];
    }

    public function resolveRecord(): Variant
    {
        $sku = $this->mappedValue('sku');

        if ($sku !== '') {
            $variants = Variant::query()->where('sku', $sku)->limit(2)->get();
        } else {
            $key = [];

            foreach (['set_code', 'number', 'language', 'condition', 'finish'] as $column) {
                $key[$column] = $this->mappedValue($column);
            }

            if (in_array('', $key, true)) {
                throw new RowImportFailedException(__('admin.import.missing_identity'));
            }

            // Set codes are only unique within a game, so a code shared by two games matches two rows and fails as ambiguous.
            // A trashed printing still matches here, so both paths refuse it with the same reason.
            $variants = Variant::query()
                ->where('condition', $key['condition'])
                ->where('finish', $key['finish'])
                ->whereHas('printingWithTrashed', fn ($printing) => $printing
                    ->where('number', $key['number'])
                    ->where('language', $key['language'])
                    ->whereHas('set', fn ($set) => $set->where('code', $key['set_code'])))
                ->limit(2)
                ->get();
        }

        if ($variants->isEmpty()) {
            throw new RowImportFailedException(__('admin.import.not_found'));
        }

        if ($variants->count() > 1) {
            throw new RowImportFailedException(__('admin.import.ambiguous'));
        }

        $variant = $variants->first();
        $printing = $variant->printingWithTrashed()->with('cardWithTrashed')->first();

        if ($printing?->trashed()) {
            throw new RowImportFailedException(__('admin.import.printing_trashed'));
        }

        if ($printing?->cardWithTrashed?->trashed()) {
            throw new RowImportFailedException(__('admin.import.card_trashed'));
        }

        return $variant;
    }

    public function fillRecord(): void
    {
        $price = $this->mappedValue('price');
        $qty = $this->mappedValue('qty');

        // A blank cell leaves the current value alone.
        if ($price !== '') {
            $this->record->price_satang = Money::fromBaht($price)->satang();
        }

        if ($qty !== '') {
            $this->record->qty = (int) $qty;
        }
    }

    /**
     * `$this->data` still holds the raw CSV row keyed by header, so a column the user did not map
     * is present but was never validated. Read a column only when it is mapped.
     */
    private function mappedValue(string $column): string
    {
        if (blank($this->columnMap[$column] ?? null)) {
            return '';
        }

        return trim((string) ($this->data[$column] ?? ''));
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = __('admin.import.completed', ['count' => Number::format($import->successful_rows)]);

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.__('admin.import.failed', ['count' => Number::format($failedRowsCount)]);
        }

        return $body;
    }

    private static function lowerCase(?string $state): ?string
    {
        return blank($state) ? null : strtolower(trim($state));
    }
}
