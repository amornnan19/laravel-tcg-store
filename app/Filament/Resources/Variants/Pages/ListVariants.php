<?php

namespace App\Filament\Resources\Variants\Pages;

use App\Filament\Imports\VariantImporter;
use App\Filament\Resources\Variants\VariantResource;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListVariants extends ListRecords
{
    protected static string $resource = VariantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()
                ->importer(VariantImporter::class)
                ->label(__('admin.actions.import_variants')),
        ];
    }
}
