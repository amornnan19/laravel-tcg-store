<?php

namespace App\Filament\Resources\StoreCreditEntries;

use App\Filament\Resources\StoreCreditEntries\Pages\ListStoreCreditEntries;
use App\Filament\Resources\StoreCreditEntries\Tables\StoreCreditEntriesTable;
use App\Models\StoreCreditEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The store-credit ledger, read-only: lines are only ever added through `RecordStoreCredit`, here by the
 * "ปรับยอดเครดิต" header action of the list page.
 */
class StoreCreditEntryResource extends Resource
{
    protected static ?string $model = StoreCreditEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.customers');
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.store_credit_entry.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.store_credit_entry.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return StoreCreditEntriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStoreCreditEntries::route('/'),
        ];
    }
}
