<?php

namespace App\Notifications;

use App\Models\Variant;
use App\Support\Money;

/**
 * What a restock and a price alert have in common: they name one variant of a card and link to its page.
 *
 * Everything the message says is copied into scalars when it is built, at the moment the alert fires. The queued send
 * never re-reads the variant or its printing, so a card trashed or a price raised while the notification waits (or
 * backs off after a failed LINE push) cannot change or lose it. The customer's email travels with it too, so a LINE
 * push that cannot be delivered still reaches them (`failed()` has no notifiable).
 */
abstract class VariantAlertFired extends ShopNotification
{
    public string $cardName;

    /** "OP05-119 · EN · NM · ฟอยล์": set code and number, language, condition and finish. */
    public string $meta;

    public int $priceSatang;

    public string $cardUrl;

    public function __construct(Variant $variant, public ?string $email = null)
    {
        $variant->loadMissing(['printing.card', 'printing.set']);
        $printing = $variant->printing;

        $this->cardName = $printing->display_name;
        $this->meta = $printing->display_code.' · '.$printing->language->shortCode().' · '.$variant->conditionFinishLabel();
        $this->priceSatang = (int) $variant->price_satang;
        $this->cardUrl = route('card.show', ['card' => $printing->card, 'p' => $printing->id]);
    }

    public function fallbackMailRoute(?object $notifiable): ?string
    {
        return filled($this->email) ? $this->email : null;
    }

    protected function price(): string
    {
        return Money::fromSatang($this->priceSatang)->format();
    }
}
