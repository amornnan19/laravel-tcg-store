<?php

namespace App\Filament\Resources\BuylistPrices\Pages;

use App\Filament\Resources\BuylistPrices\BuylistPriceResource;
use App\Models\BuylistPrice;
use Filament\Resources\Pages\CreateRecord;

class CreateBuylistPrice extends CreateRecord
{
    protected static string $resource = BuylistPriceResource::class;

    /**
     * A credit price left blank is the cash price plus the configured bonus, in whole baht.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (blank($data['credit_satang'] ?? null)) {
            $data['credit_satang'] = BuylistPrice::creditFor((int) $data['cash_satang']);
        }

        return $data;
    }
}
