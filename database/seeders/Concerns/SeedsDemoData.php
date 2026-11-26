<?php

namespace Database\Seeders\Concerns;

trait SeedsDemoData
{
    /**
     * Demo content (orders, price history, graded cards, sealed products, pre-orders, buy prices) is seeded on a local
     * machine, and on a deployed demo that sets `SHOP_DEMO_DATA`. Nothing here may create a user with a known
     * password: that stays behind `app()->isLocal()` alone.
     */
    protected function shouldSeedDemoData(): bool
    {
        return app()->isLocal() || (bool) config('shop.demo_data');
    }
}
