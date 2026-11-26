<?php

namespace App\Filament\Resources\BuylistSubmissions;

use App\Filament\Resources\BuylistSubmissions\Pages\EditBuylistSubmission;
use App\Filament\Resources\BuylistSubmissions\Pages\ListBuylistSubmissions;
use App\Filament\Resources\BuylistSubmissions\Schemas\BuylistSubmissionForm;
use App\Filament\Resources\BuylistSubmissions\Tables\BuylistSubmissionsTable;
use App\Models\BuylistSubmission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The buylist desk: the customer's lists as they arrive, are inspected, confirmed and paid. Lists are made by customers
 * at `/buylist`, never in the panel, and never deleted.
 */
class BuylistSubmissionResource extends Resource
{
    protected static ?string $model = BuylistSubmission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.buylist');
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.buylist_submission.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.buylist_submission.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return BuylistSubmissionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BuylistSubmissionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBuylistSubmissions::route('/'),
            'edit' => EditBuylistSubmission::route('/{record}/edit'),
        ];
    }
}
