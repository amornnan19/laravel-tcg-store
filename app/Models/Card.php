<?php

namespace App\Models;

use Database\Factories\CardFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Unguarded]
class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Card $card): void {
            if (blank($card->slug)) {
                $card->slug = static::uniqueSlug($card->name_en);
            }
        });
    }

    /**
     * Slugify the name (`card` when nothing survives, e.g. a Thai name), then append -2, -3... until no row, trashed or not, owns it.
     */
    protected static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'card';
        $slug = $base;
        $suffix = 2;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
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
            'traits' => 'array',
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
        return $this->hasMany(Printing::class);
    }
}
