<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\BuylistSubmission;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * `/buylist/{number}`: one submission, for the signed-in customer who sent it. Anyone else gets a 404, never a 403, so
 * the page does not confirm that a number exists.
 */
class BuylistSubmissionController extends Controller
{
    public function __invoke(Request $request, BuylistSubmission $submission): View
    {
        abort_unless($submission->user_id === $request->user()->getKey(), 404);

        return view('buylist-show', [
            'submission' => $submission->load('items'),
        ]);
    }
}
