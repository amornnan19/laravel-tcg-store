<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\GradedStatus;
use App\Http\Controllers\Controller;
use App\Models\GradedItem;
use App\Models\Printing;
use Illuminate\Contracts\View\View;

/**
 * `/graded/{gradedItem}`: one graded card. Listed and reserved items show; a sold one stays up as "sold" so a shared
 * link still lands somewhere. A withdrawn item, or one whose card the storefront hides, is a 404.
 */
class GradedItemController extends Controller
{
    public function __invoke(GradedItem $gradedItem): View
    {
        abort_if($gradedItem->status === GradedStatus::Withdrawn, 404);

        $printing = Printing::query()->searchVisible()->with(['card.game', 'set'])->find($gradedItem->printing_id);

        abort_if($printing === null, 404);

        return view('graded', [
            'item' => $gradedItem,
            'printing' => $printing,
            'card' => $printing->card,
            'game' => $printing->card->game,
            'title' => __('storefront.graded.title', ['name' => $printing->display_name, 'badge' => $gradedItem->gradeBadge()]),
        ]);
    }
}
