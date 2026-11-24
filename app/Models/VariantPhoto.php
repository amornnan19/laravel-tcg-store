<?php

namespace App\Models;

use Database\Factories\VariantPhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Unguarded]
class VariantPhoto extends Model
{
    /** @use HasFactory<VariantPhotoFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleted(function (VariantPhoto $photo): void {
            // After commit, so a rolled-back delete never loses the file.
            DB::afterCommit(fn () => Storage::disk('public')->delete($photo->path));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort' => 'integer',
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
