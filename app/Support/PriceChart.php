<?php

namespace App\Support;

use App\Models\PricePoint;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The geometry of the card page's price chart: a polyline of the daily average price, computed on the server
 * so the page needs no charting library and no client fetch.
 */
final class PriceChart
{
    public const WIDTH = 326;

    public const HEIGHT = 96;

    private const PAD_X = 2;

    private const PAD_TOP = 8;

    private const PAD_BOTTOM = 12;

    /**
     * @param  Collection<int, PricePoint>  $points  Any order; fewer than two make no chart.
     * @return array{first_date: Carbon, last_date: Carbon, covered_days: int, path: string, end_x: float, end_y: float, first_satang: int, last_satang: int, low_satang: int, high_satang: int, days: int, change_percent: ?float, grid: list<int>}|null
     */
    public static function make(Collection $points): ?array
    {
        $points = $points->sortBy(fn (PricePoint $point): string => $point->on_date->toDateString())->values();

        if ($points->count() < 2) {
            return null;
        }

        $first = $points->first();
        $last = $points->last();
        $days = max(1, (int) $first->on_date->diffInDays($last->on_date));
        $low = (int) $points->min('avg_satang');
        $high = (int) $points->max('avg_satang');
        $spread = $high - $low;
        $plotWidth = self::WIDTH - 2 * self::PAD_X;
        $plotHeight = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;

        $coordinates = $points->map(function (PricePoint $point) use ($first, $days, $low, $spread, $plotWidth, $plotHeight): array {
            $x = self::PAD_X + $plotWidth * (int) $first->on_date->diffInDays($point->on_date) / $days;
            $y = $spread === 0
                ? self::PAD_TOP + $plotHeight / 2
                : self::PAD_TOP + $plotHeight * (1 - ($point->avg_satang - $low) / $spread);

            return [round($x, 1), round($y, 1)];
        });

        $path = $coordinates
            ->map(fn (array $xy, int $index): string => ($index === 0 ? 'M' : 'L').$xy[0].' '.$xy[1])
            ->implode(' ');

        $monthAgo = $last->on_date->copy()->subDays(30);
        $reference = $points->last(fn (PricePoint $point): bool => $point->on_date->lte($monthAgo));

        return [
            'first_date' => $first->on_date->copy(),
            'last_date' => $last->on_date->copy(),
            // The days the chart covers, counted from its first point to today and both ends included.
            'covered_days' => (int) $first->on_date->diffInDays(today()) + 1,
            'path' => $path,
            'end_x' => $coordinates->last()[0],
            'end_y' => $coordinates->last()[1],
            'first_satang' => $first->avg_satang,
            'last_satang' => $last->avg_satang,
            'low_satang' => $low,
            'high_satang' => $high,
            'days' => $days,
            'change_percent' => $reference !== null && $reference->avg_satang > 0
                ? round(($last->avg_satang - $reference->avg_satang) / $reference->avg_satang * 100, 1)
                : null,
            'grid' => [self::PAD_TOP, self::PAD_TOP + intdiv(self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM, 2), self::HEIGHT - self::PAD_BOTTOM],
        ];
    }
}
