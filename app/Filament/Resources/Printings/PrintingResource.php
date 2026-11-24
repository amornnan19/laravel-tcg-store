<?php

namespace App\Filament\Resources\Printings;

use App\Filament\Resources\Printings\Pages\CreatePrinting;
use App\Filament\Resources\Printings\Pages\EditPrinting;
use App\Filament\Resources\Printings\Pages\ListPrintings;
use App\Filament\Resources\Printings\Schemas\PrintingForm;
use App\Filament\Resources\Printings\Tables\PrintingsTable;
use App\Models\Printing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PrintingResource extends Resource
{
    protected static ?string $model = Printing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (! $record instanceof Printing) {
            return null;
        }

        return $record->set->code.' '.$record->number.' '.$record->cardWithTrashed->name_en;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.catalogue');
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.printing.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.printing.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return PrintingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PrintingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrintings::route('/'),
            'create' => CreatePrinting::route('/create'),
            'edit' => EditPrinting::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
