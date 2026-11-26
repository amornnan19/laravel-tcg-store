<?php

namespace App\Support;

use App\Enums\CardCondition;
use App\Enums\Language;
use App\Models\Printing;

/**
 * How one purchasable reads on the cart and checkout pages: its name, where it links, and the small facts under the
 * name. The pages render this and never the model behind it, so every kind of product shares one line layout.
 */
final readonly class LinePresentation
{
    /**
     * @param  string  $title  The name in the card's own language; empty when the card is gone.
     * @param  string|null  $url  Where the name links, or null when there is nothing to open.
     * @param  string|null  $code  The set and card number, e.g. `OP05-119`.
     * @param  list<string>  $badges  Plain labels after the code, e.g. the finish or `PSA 10`.
     * @param  Printing|null  $printing  For the placeholder card art; null draws the neutral one.
     * @param  string|null  $imageUrl  A real photo of the item (a graded card's front) that replaces the placeholder art.
     * @param  string|null  $gradeBadge  "PSA 10", shown through the grade badge component.
     * @param  string  $shape  The art's shape: `card` (63:88 placeholder art), `photo` (a real photo, 3:4) or `square` (a box).
     */
    public function __construct(
        public string $title,
        public ?string $url = null,
        public ?string $code = null,
        public ?Language $language = null,
        public ?CardCondition $condition = null,
        public array $badges = [],
        public ?Printing $printing = null,
        public bool $foil = false,
        public ?string $imageUrl = null,
        public ?string $gradeBadge = null,
        public string $shape = 'card',
    ) {}
}
