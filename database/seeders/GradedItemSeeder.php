<?php

namespace Database\Seeders;

use App\Enums\GradedStatus;
use App\Enums\Grader;
use App\Models\GradedItem;
use App\Models\Printing;
use Database\Seeders\Concerns\SeedsDemoData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class GradedItemSeeder extends Seeder
{
    use SeedsDemoData;

    /**
     * Six graded cards for sale on seeded printings (demo data): nothing here is a real slab or a real photo. The
     * three photos of each are plain labelled rectangles drawn with GD ("FRONT · DEMO"), never card art. Without GD
     * the items are created withdrawn, since the listing rule needs all three photos.
     */
    public function run(): void
    {
        if (! $this->shouldSeedDemoData() || GradedItem::query()->exists()) {
            return;
        }

        $printings = Printing::query()->searchVisible()->orderBy('id')->limit(6)->get();

        // [grader, grade, label]; every one gets a certificate number, which listing requires.
        $specs = [
            [Grader::Psa, 10, 'Gem Mint'],
            [Grader::Psa, 9, 'Mint'],
            [Grader::Bgs, 9.5, 'Gem Mint'],
            [Grader::Cgc, 10, 'Pristine'],
            [Grader::Psa, 8, 'Near Mint-Mint'],
            [Grader::Bgs, 10, 'Pristine'],
        ];

        foreach ($printings as $index => $printing) {
            [$grader, $grade, $label] = $specs[$index];

            $photos = [
                'front_path' => $this->placeholderPhoto("graded-photos/demo/{$printing->id}-front.png", 'FRONT'),
                'back_path' => $this->placeholderPhoto("graded-photos/demo/{$printing->id}-back.png", 'BACK'),
                'label_path' => $this->placeholderPhoto("graded-photos/demo/{$printing->id}-label.png", 'LABEL'),
            ];

            GradedItem::query()->create([
                'printing_id' => $printing->id,
                'grader' => $grader,
                'grade' => $grade,
                'grade_label' => $label,
                'cert_number' => (string) (10000000 + $printing->id * 4821),
                'subgrades' => $grader === Grader::Bgs ? ['centering' => 9.5, 'corners' => 9.5, 'edges' => 9, 'surface' => 9.5] : null,
                // A plausible demo price, never shown as a real quote.
                'price_satang' => (3500 + $index * 1750) * 100,
                'status' => in_array(null, $photos, true) ? GradedStatus::Withdrawn : GradedStatus::Listed,
                ...$photos,
            ]);
        }
    }

    /**
     * Draws a grey rectangle with the word and "DEMO" on the public disk, or returns null without GD.
     */
    private function placeholderPhoto(string $path, string $word): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $image = imagecreatetruecolor(504, 704);
        imagefill($image, 0, 0, imagecolorallocate($image, 58, 64, 78));
        imagerectangle($image, 12, 12, 491, 691, imagecolorallocate($image, 231, 182, 78));

        $ink = imagecolorallocate($image, 243, 244, 247);
        imagestring($image, 5, 190, 340, "{$word} - DEMO", $ink);

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        Storage::disk('public')->put($path, $png);

        return $path;
    }
}
