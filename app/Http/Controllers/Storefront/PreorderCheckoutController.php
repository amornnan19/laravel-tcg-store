<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\PreorderPlan;
use App\Http\Controllers\Controller;
use App\Models\Preorder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * `/preorder/{preorder}/checkout?qty=&plan=`: the pre-order's own checkout, for a signed-in customer. The query only
 * chooses what the page starts with; `PlacePreorder` decides again under the lock when the order is placed.
 */
class PreorderCheckoutController extends Controller
{
    public function __invoke(Request $request, Preorder $preorder): View
    {
        $preorder->load('sealedProduct');

        abort_if($preorder->sealedProduct === null, 404);

        return view('preorder-checkout', [
            'preorder' => $preorder,
            'qty' => max(1, (int) $request->query('qty', 1)),
            'plan' => (PreorderPlan::tryFrom((string) $request->query('plan')) ?? PreorderPlan::Deposit)->value,
        ]);
    }
}
