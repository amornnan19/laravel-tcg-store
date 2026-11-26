<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\SealedProduct;
use Illuminate\Contracts\View\View;

/**
 * `/p/{sealedProduct:slug}`: one booster box, pack, starter deck or accessory. A trashed or inactive product, or one of
 * a game the storefront hides, is a 404 (the route binding already drops a trashed one).
 */
class SealedProductController extends Controller
{
    public function __invoke(SealedProduct $sealedProduct): View
    {
        abort_unless(SealedProduct::query()->searchVisible()->whereKey($sealedProduct->getKey())->exists(), 404);

        $sealedProduct->load(['game', 'set']);

        return view('sealed', [
            'product' => $sealedProduct,
            'title' => $sealedProduct->displayName(),
        ]);
    }
}
