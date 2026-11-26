<?php

namespace App\Support;

use App\Contracts\Purchasable;
use App\Models\GradedItem;
use App\Models\SealedProduct;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Finds the purchasable behind a line's `purchasable_type` + `purchasable_id`, always through the morph map and never
 * by id against one table: a graded item and a variant may share an id.
 */
class PurchasableTypes
{
    /**
     * The order in which a transaction locks the rows of several types, so two orders can never wait on each other.
     * Within a type the ids are locked in ascending order.
     *
     * @return list<class-string<Model&Purchasable>>
     */
    public static function lockOrder(): array
    {
        return [Variant::class, GradedItem::class, SealedProduct::class];
    }

    /**
     * The model class a morph alias stands for, or null when it is not a purchasable.
     *
     * @return class-string<Model&Purchasable>|null
     */
    public static function classFor(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? (class_exists($type) ? $type : null);

        return $class !== null && in_array($class, self::lockOrder(), true) ? $class : null;
    }

    /**
     * @throws ModelNotFoundException when the type is not a purchasable or the row is gone.
     */
    public static function lock(?string $type, int $id): Model&Purchasable
    {
        $class = self::classFor($type);

        return ($class === null ? null : $class::lockForHold($id))
            ?? throw (new ModelNotFoundException)->setModel($class ?? (string) $type, [$id]);
    }

    /**
     * The purchasable rows a set of lines (cart items or order items) point at, per morph alias, ids ascending. Lines
     * with no row or an unknown type (a snapshot only) are left out.
     *
     * @param  Collection<int, Model>  $items
     * @return array<string, Collection<int, int>>
     */
    public static function referencedBy(Collection $items): array
    {
        $refs = [];

        foreach (self::lockOrder() as $class) {
            $type = (new $class)->getMorphClass();

            $ids = $items
                ->filter(fn (Model $item): bool => $item->purchasable_id !== null && $item->purchasable_type === $type)
                ->pluck('purchasable_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->sort()
                ->values();

            if ($ids->isNotEmpty()) {
                $refs[$type] = $ids;
            }
        }

        return $refs;
    }

    /**
     * Locks the referenced rows, one type after another in lockOrder(), each set by ascending id. Nothing else may be
     * read in the transaction before this. Variants come first and are queried even when none are referenced.
     *
     * @param  array<string, Collection<int, int>>  $refs  From referencedBy().
     * @return array<string, Collection<int, Model&Purchasable>> `morph alias => id => model`
     */
    public static function lockMany(array $refs): array
    {
        $locked = [];

        foreach (self::lockOrder() as $class) {
            $type = (new $class)->getMorphClass();

            // The variants are always read, even with no id: the first statement of every order transaction is the
            // variants lock, whatever else the order holds. The other types are read only when referenced.
            if (! isset($refs[$type]) && $class !== Variant::class) {
                continue;
            }

            // A soft-deleted product is locked too: an order placed before it was trashed can still be cancelled or
            // refunded, and its quantity goes back. Whether a trashed one may be bought is purchasableIdsAmong()'s question.
            $locked[$type] = $class::query()
                ->when(in_array(SoftDeletes::class, class_uses_recursive($class), true), fn ($query) => $query->withTrashed())
                ->whereIn('id', $refs[$type] ?? [])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
        }

        return $locked;
    }
}
