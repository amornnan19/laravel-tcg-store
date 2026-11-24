<?php

namespace App\Filament\Forms\Components;

use App\Support\Money;
use Closure;
use Filament\Forms\Components\TextInput;
use InvalidArgumentException;

/**
 * A baht text input over a satang integer column: shows `4290.50`, validates through
 * Money::fromBaht(), and dehydrates to satang. Blank dehydrates to null.
 */
class MoneyInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix('฿');
        $this->inputMode('decimal');

        $this->formatStateUsing(function (mixed $state): ?string {
            if ($state === null || $state === '') {
                return null;
            }

            return Money::fromSatang((int) $state)->toBahtString();
        });

        $this->rules([
            fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    Money::fromBaht(trim((string) $value));
                } catch (InvalidArgumentException) {
                    $fail(__('admin.validation.money'));
                }
            },
        ]);

        $this->dehydrateStateUsing(function (mixed $state): mixed {
            if (blank($state)) {
                return null;
            }

            try {
                return Money::fromBaht(trim((string) $state))->satang();
            } catch (InvalidArgumentException) {
                // Dehydration also runs for state that failed validation; the rule above rejects it before it is saved.
                return $state;
            }
        });
    }
}
