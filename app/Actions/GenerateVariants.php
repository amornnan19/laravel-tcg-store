<?php

namespace App\Actions;

use App\Enums\CardCondition;
use App\Enums\Finish;
use App\Models\Printing;

class GenerateVariants
{
    /**
     * Create the missing condition x finish rows for a printing: five conditions, times two
     * finishes when the printing has foil. New rows are unpriced with qty 0.
     *
     * @return int How many variants were created.
     */
    public function handle(Printing $printing): int
    {
        $finishes = $printing->has_foil ? Finish::cases() : [Finish::Normal];

        $existing = $printing->variants()
            ->get(['condition', 'finish'])
            ->map(fn ($variant): string => $variant->condition->value.'|'.$variant->finish->value)
            ->all();

        $created = 0;

        foreach ($finishes as $finish) {
            foreach (CardCondition::cases() as $condition) {
                if (in_array($condition->value.'|'.$finish->value, $existing, true)) {
                    continue;
                }

                $printing->variants()->create([
                    'condition' => $condition,
                    'finish' => $finish,
                    'price_satang' => null,
                    'qty' => 0,
                ]);

                $created++;
            }
        }

        return $created;
    }
}
