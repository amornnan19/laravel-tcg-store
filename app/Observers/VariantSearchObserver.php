<?php

namespace App\Observers;

use App\Models\Printing;
use App\Models\Variant;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * `in_stock`, `from_satang`, `conditions` and `available_qty` are derived from variants, so a printing is
 * re-indexed whenever one of its variants is saved or deleted.
 */
class VariantSearchObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * The printing a variant is leaving, by variant id. After-commit handlers run once the model's original
     * attributes are synced, so Variant::booted() captures it from an `updating` hook, which an after-commit
     * observer cannot be (it would be deferred too).
     *
     * @var array<int, int>
     */
    private static array $previousPrintingIds = [];

    public static function rememberPreviousPrinting(Variant $variant): void
    {
        if ($variant->isDirty('printing_id')) {
            self::$previousPrintingIds[$variant->id] = (int) $variant->getOriginal('printing_id');
        }
    }

    public function saved(Variant $variant): void
    {
        $this->reindex($variant->printing_id);

        if (isset(self::$previousPrintingIds[$variant->id])) {
            $this->reindex(self::$previousPrintingIds[$variant->id]);
            unset(self::$previousPrintingIds[$variant->id]);
        }
    }

    public function deleted(Variant $variant): void
    {
        $this->reindex($variant->printing_id);
    }

    private function reindex(int $printingId): void
    {
        Printing::syncSearchIndexFor(Printing::query()->whereKey($printingId));
    }
}
