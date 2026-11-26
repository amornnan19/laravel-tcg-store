<?php

namespace App\Enums;

/**
 * `stock` is an order of things the shop holds; a `preorder` reserves places in a release and takes no stock.
 */
enum OrderKind: string
{
    case Stock = 'stock';
    case Preorder = 'preorder';

    public function label(): string
    {
        return __('enums.order_kind.'.$this->value);
    }
}
