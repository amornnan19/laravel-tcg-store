<?php

namespace App\Filament\Resources\SealedProducts\Pages;

use App\Filament\Resources\SealedProducts\SealedProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSealedProduct extends CreateRecord
{
    protected static string $resource = SealedProductResource::class;
}
