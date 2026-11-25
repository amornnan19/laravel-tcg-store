<?php

namespace App\Providers;

use App\Models\Game;
use App\Models\Variant;
use App\Search\QueryParser;
use App\Support\CurrentCart;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\Scout;
use Meilisearch\Client as MeilisearchClient;
use SocialiteProviders\Line\LineExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(QueryParser::class);
        $this->app->scoped(CurrentCart::class);

        // Scout's own binding has no timeout, so a stopped Meilisearch would hang the page instead of
        // failing fast into CatalogueSearch's database fallback.
        $this->app->singleton(MeilisearchClient::class, function (Application $app): MeilisearchClient {
            $config = $app['config']->get('scout.meilisearch');

            return new MeilisearchClient(
                $config['host'],
                $config['key'],
                new GuzzleClient([
                    'timeout' => $config['timeout'],
                    'connect_timeout' => $config['connect_timeout'],
                ]),
                clientAgents: [sprintf('Meilisearch Laravel Scout (v%s)', Scout::VERSION)],
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // An alias, not enforceMorphMap(): Filament's notifications and imports store other morph types unaliased.
        Relation::morphMap(['variant' => Variant::class]);

        // `php artisan dev` runs no scheduler by default, and ReleaseExpiredReservations runs from the schedule.
        DevCommands::artisan('schedule:work');

        Event::listen(SocialiteWasCalled::class, [LineExtendSocialite::class, 'handle']);

        View::composer('components.layouts.storefront', function (ViewContract $view): void {
            $view->with('games', Game::query()->where('is_active', true)->orderBy('sort')->get());
        });
    }
}
