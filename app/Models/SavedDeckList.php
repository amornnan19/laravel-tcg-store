<?php

namespace App\Models;

use Database\Factories\SavedDeckListFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A deck list a signed-in customer kept to check again later. `raw_text` is exactly what was pasted, so a re-check
 * parses it against the stock of the day; `parsed` is the per-line match result (which card each line names, no stock).
 */
class SavedDeckList extends Model
{
    /** @use HasFactory<SavedDeckListFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'game_id', 'name', 'raw_text', 'parsed', 'requested_cards'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'parsed' => 'array',
        ];
    }

    /**
     * How many copies the list asks for, stored when it was saved.
     */
    public function requestedCards(): int
    {
        return (int) $this->requested_cards;
    }

    /**
     * The match of a list cut down to what a re-check does not need to re-derive: line number, kind, quantity and the
     * printings it named.
     *
     * @param  list<array<string, mixed>>  $matched  Records of a `DeckMatch`.
     * @return list<array{number: int, kind: string, qty: int, printingIds: list<int>}>
     */
    public static function slim(array $matched): array
    {
        return array_map(fn (array $line): array => [
            'number' => $line['number'],
            'kind' => $line['kind'],
            'qty' => $line['qty'],
            'printingIds' => $line['printingIds'],
        ], $matched);
    }

    /**
     * The copies a list asks for, card lines readable or not.
     *
     * @param  list<array<string, mixed>>  $matched
     */
    public static function requestedIn(array $matched): int
    {
        return (int) collect($matched)->whereIn('kind', ['matched', 'unmatched'])->sum('qty');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Game, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
