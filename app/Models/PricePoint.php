<?php

namespace App\Models;

use Database\Factories\PricePointFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class PricePoint extends Model
{
    /** @use HasFactory<PricePointFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'on_date' => 'date',
            'avg_satang' => 'integer',
            'low_satang' => 'integer',
            'high_satang' => 'integer',
            'sold_qty' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
