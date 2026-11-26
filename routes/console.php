<?php

use App\Jobs\CancelUnpaidOrders;
use App\Jobs\ReleaseExpiredReservations;
use App\Jobs\RequestPreorderBalances;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new ReleaseExpiredReservations)->everyMinute()->withoutOverlapping();
Schedule::job(new CancelUnpaidOrders)->everyMinute()->withoutOverlapping();
// Asks for the balance of deposit pre-orders a week before their release, at 09:00 shop time.
Schedule::job(new RequestPreorderBalances)->dailyAt('09:00')->timezone(config('shop.timezone'))->withoutOverlapping();
