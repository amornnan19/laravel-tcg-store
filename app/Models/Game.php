<?php

namespace App\Models;

use App\Observers\GameSearchObserver;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[ObservedBy(GameSearchObserver::class)]
#[Unguarded]
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Game $game): void {
            if (blank($game->slug)) {
                $game->slug = static::uniqueSlug($game->name_en);
            }
        });
    }

    /**
     * Slugify the name (`game` when nothing survives, e.g. a Thai name), then append -2, -3... until no row, trashed or not, owns it.
     */
    protected static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'game';
        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CardSet, $this>
     */
    public function sets(): HasMany
    {
        return $this->hasMany(CardSet::class);
    }

    /**
     * @return HasMany<Card, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }
}
