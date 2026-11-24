<?php

namespace App\Models;

use Database\Factories\CardSetFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A card set. Named CardSet because `Set` is awkward in PHP.
 */
#[Table('sets')]
#[Unguarded]
class CardSet extends Model
{
    /** @use HasFactory<CardSetFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'released_on' => 'date',
            'card_count' => 'integer',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * @return HasMany<Printing, $this>
     */
    public function printings(): HasMany
    {
        return $this->hasMany(Printing::class, 'set_id');
    }
}
