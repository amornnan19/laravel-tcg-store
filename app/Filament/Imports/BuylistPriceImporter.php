<?php

namespace App\Filament\Imports;

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Enums\Language;
use App\Filament\Resources\BuylistPrices\Schemas\BuylistPriceForm;
use App\Models\BuylistPrice;
use App\Models\Printing;
use App\Support\Money;
use Closure;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;
use InvalidArgumentException;

/**
 * Upserts buy prices on their unique key (printing, condition, finish). The printing is found by set code, number and
 * language; a row that matches no printing, or two, or a trashed one, fails with a reason. A blank cell leaves the
 * current value of an existing row alone; on a new row a blank credit is the cash price plus the configured bonus.
 */
class BuylistPriceImporter extends Importer
{
    protected static ?string $model = BuylistPrice::class;

    public static function getColumns(): array
    {
        return [
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
                ->example('normal')
                ->castStateUsing(fn (?string $state): ?string => self::lowerCase($state))
                ->rules(['nullable', 'in:'.implode(',', array_column(Finish::cases(), 'value'))]),
            ImportColumn::make('cash_baht')
                ->label(__('admin.buylist.fields.cash_baht'))
                ->example('120.50')
                ->helperText(__('admin.buylist.hints.import_cash'))
                ->rules([self::moneyRule()]),
            ImportColumn::make('credit_baht')
                ->label(__('admin.buylist.fields.credit_baht'))
                ->example('140')
                ->helperText(fn (): string => __('admin.buylist.hints.import_credit', ['percent' => BuylistPriceForm::bonusPercent()]))
                ->rules([self::moneyRule()]),
            ImportColumn::make('weekly_quota')
                ->label(__('admin.buylist.fields.weekly_quota'))
                ->example('10')
                ->rules([
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (! blank($value) && (preg_match('/^\d{1,10}\z/', trim((string) $value)) !== 1 || (int) $value > 2147483647)) {
                            $fail(__('admin.buylist.validation.quota'));
                        }
                    },
                ]),
            ImportColumn::make('is_active')
                ->label(__('admin.buylist.fields.is_active'))
                ->example('1')
                ->rules([
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (! blank($value) && self::parseActive(trim((string) $value)) === null) {
                            $fail(__('admin.buylist.validation.active'));
                        }
                    },
                ]),
        ];
    }

    public function resolveRecord(): BuylistPrice
    {
        $key = [];

        foreach (['set_code', 'number', 'language', 'condition'] as $column) {
            $key[$column] = $this->mappedValue($column);
        }

        if (in_array('', $key, true)) {
            throw new RowImportFailedException(__('admin.buylist.import.missing_identity'));
        }

        $finish = $this->mappedValue('finish') ?: Finish::Normal->value;

        // Set codes are only unique within a game, so a code shared by two games matches two rows and fails as ambiguous.
        // A trashed printing still matches here, so it is refused with a reason rather than reported as not found.
        $printings = Printing::withTrashed()
            ->with('cardWithTrashed')
            ->where('number', $key['number'])
            ->where('language', $key['language'])
            ->whereHas('set', fn ($set) => $set->where('code', $key['set_code']))
            ->limit(2)
            ->get();

        if ($printings->isEmpty()) {
            throw new RowImportFailedException(__('admin.buylist.import.not_found'));
        }

        if ($printings->count() > 1) {
            throw new RowImportFailedException(__('admin.buylist.import.ambiguous'));
        }

        $printing = $printings->first();

        if ($printing->trashed() || $printing->cardWithTrashed?->trashed()) {
            throw new RowImportFailedException(__('admin.buylist.import.printing_trashed'));
        }

        if ($finish === Finish::Foil->value && ! $printing->has_foil) {
            throw new RowImportFailedException(__('admin.buylist.validation.foil'));
        }

        return BuylistPrice::query()->firstOrNew([
            'printing_id' => $printing->getKey(),
            'condition' => $key['condition'],
            'finish' => $finish,
        ]);
    }

    public function fillRecord(): void
    {
        $cash = $this->mappedValue('cash_baht');
        $credit = $this->mappedValue('credit_baht');
        $quota = $this->mappedValue('weekly_quota');
        $active = $this->mappedValue('is_active');

        if ($cash !== '') {
            $this->record->cash_satang = Money::fromBaht($cash)->satang();
        } elseif (! $this->record->exists) {
            throw new RowImportFailedException(__('admin.buylist.import.cash_required'));
        }

        if ($this->record->cash_satang < 1) {
            throw new RowImportFailedException(__('admin.buylist.validation.cash_positive'));
        }

        if ($credit !== '') {
            $this->record->credit_satang = Money::fromBaht($credit)->satang();
        } elseif (! $this->record->exists) {
            $this->record->credit_satang = BuylistPrice::creditFor($this->record->cash_satang);
        }

        // Credit is what the shop pays more in: a row that ends below its cash price is refused whole.
        if ($this->record->credit_satang < $this->record->cash_satang) {
            throw new RowImportFailedException(__('admin.buylist.validation.credit_below_cash'));
        }

        // A blank cell leaves the current value alone.
        if ($quota !== '') {
            $this->record->weekly_quota = (int) $quota;
        }

        if ($active !== '') {
            $this->record->is_active = self::parseActive($active);
        } elseif (! $this->record->exists) {
            $this->record->is_active = true;
        }
    }

    /**
     * `$this->data` still holds the raw CSV row keyed by header, so a column the user did not map is present but was
     * never validated. Read a column only when it is mapped.
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
        $body = __('admin.buylist.import.completed', ['count' => Number::format($import->successful_rows)]);

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.__('admin.buylist.import.failed', ['count' => Number::format($failedRowsCount)]);
        }

        return $body;
    }

    private static function moneyRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (blank($value)) {
                return;
            }

            try {
                Money::fromBaht(trim((string) $value));
            } catch (InvalidArgumentException) {
                $fail(__('admin.validation.money'));
            }
        };
    }

    private static function parseActive(string $value): ?bool
    {
        return match (strtolower($value)) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => null,
        };
    }

    private static function lowerCase(?string $state): ?string
    {
        return blank($state) ? null : strtolower(trim($state));
    }
}
