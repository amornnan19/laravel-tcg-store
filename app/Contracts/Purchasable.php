<?php

namespace App\Contracts;

use App\Support\LinePresentation;
use Illuminate\Support\Collection;

/**
 * Something a cart can hold and an order can sell: a variant, a graded item. Every hold and order path resolves a
 * line's purchasable by `purchasable_type` + `purchasable_id` through the morph map and talks to it only through
 * this contract, so a new kind of product joins the cart, the holds and the orders by implementing it.
 *
 * Implemented by Eloquent models: `getKey()` and `getMorphClass()` come from the model.
 */
interface Purchasable
{
    /**
     * The row, read with `lockForUpdate()` on its own table, or null. The first read of any transaction that
     * creates or increases a hold on it.
     */
    public static function lockForHold(int $id): ?static;

    /**
     * Which of `$ids` pass the purchasability rule, as keys, from one query. The batch twin of isPurchasable().
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, int>
     */
    public static function purchasableIdsAmong(Collection $ids): Collection;

    /**
     * Whether it can be put in a basket at all, as the database has it now. Stock is a separate question
     * (holdableQty()): a graded item that its own cart holds is still purchasable.
     */
    public function isPurchasable(): bool;

    /**
     * What can still be held now, after the live reservations of every cart.
     */
    public function holdableQty(): int;

    /**
     * What is on the shelf, ignoring holds: the figure an order is checked against when it is placed.
     */
    public function onHandQty(): int;

    public function unitPriceSatang(): int;

    /**
     * The most one cart line may hold: 1 for a one-of-a-kind item.
     */
    public function maxPerLine(): int;

    /**
     * A hold was written on it. A one-of-a-kind item flips to `reserved`; the others do nothing.
     */
    public function onHeld(): void;

    /**
     * A hold ended without a sale. A one-of-a-kind item that is still `reserved` and has no other live hold goes
     * back to `listed`; the others do nothing.
     */
    public function onReleased(): void;

    /**
     * An order took `$qty` out of stock.
     */
    public function onSold(int $qty): void;

    /**
     * A cancelled or refunded order gave `$qty` back.
     */
    public function onReturned(int $qty): void;

    /**
     * The `order_items` columns that describe it: card_name, set_code, number, language, condition, finish, kind, detail.
     *
     * @return array<string, string|null>
     */
    public function snapshot(): array;

    public function cartPresentation(): LinePresentation;

    /**
     * Whether it travels in a hard case with parcel insurance, which checkout adds as its own line.
     */
    public function isInsured(): bool;
}
