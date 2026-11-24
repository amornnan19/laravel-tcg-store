<?php

namespace App\Filament\Resources\Variants\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Real photos of one variant. Attached to the Variants resource in Step C: add it to that
 * resource's getRelations().
 */
class PhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'photos';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.models.variant_photo.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.models.variant_photo.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.models.variant_photo.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('path')
                    ->label(__('admin.fields.photo'))
                    ->disk('public')
                    ->directory('variant-photos')
                    ->visibility('public')
                    ->image()
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('path')
            ->columns([
                ImageColumn::make('path')
                    ->label(__('admin.fields.photo'))
                    ->disk('public'),
            ])
            ->reorderable('sort')
            ->defaultSort('sort')
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
