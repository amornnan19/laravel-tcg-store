<?php

namespace App\Support;

/**
 * The largest slip the server will really take: the shop's own limit, capped by what PHP accepts for an upload and for
 * a whole request, so the hint and the validation never promise more than the server can receive.
 */
final class SlipLimit
{
    public static function kb(): int
    {
        $limits = [(int) config('shop.slip_max_kb')];

        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = self::bytes((string) ini_get($setting));

            // 0 and -1 mean no limit.
            if ($bytes > 0) {
                $limits[] = intdiv($bytes, 1024);
            }
        }

        return max(1, min($limits));
    }

    /**
     * `2M`, `8m`, `512K`, `1G` or plain bytes.
     */
    public static function bytes(string $value): int
    {
        $value = trim($value);

        if (preg_match('/^(-?\d+)\s*([kmg]?)b?$/i', $value, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1] * match (strtolower($matches[2])) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            default => 1,
        };
    }

    public static function label(int $kb): string
    {
        return $kb >= 1024 ? round($kb / 1024, 1).' MB' : $kb.' KB';
    }
}
