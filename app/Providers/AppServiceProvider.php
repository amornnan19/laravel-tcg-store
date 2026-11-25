<?php

namespace App\Providers;

use App\Models\Game;
use App\Models\User;
use App\Models\Variant;
use App\Search\QueryParser;
use App\Support\CurrentCart;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
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

        $this->configureAccountMail();

        View::composer('components.layouts.storefront', function (ViewContract $view): void {
            $view->with('games', Game::query()->where('is_active', true)->orderBy('sort')->get());
        });
    }

    /**
     * Mail sent from inside a request builds its links from APP_URL, never the request host: `trustProxies` trusts
     * forwarded headers from any caller, so a forged Host would otherwise put the attacker's domain in a reset link.
     */
    private function configureAccountMail(): void
    {
        $appUrl = fn (string $path): string => rtrim((string) config('app.url'), '/').$path;

        $resetUrl = fn (User $user, string $token): string => $appUrl(
            route('password.reset', ['token' => $token, 'email' => $user->email], false),
        );

        $verifyUrl = fn (User $user): string => $appUrl(URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1((string) $user->email)],
            absolute: false,
        ));

        ResetPassword::createUrlUsing($resetUrl);
        ResetPassword::toMailUsing(fn (User $user, string $token): MailMessage => (new MailMessage)
            ->subject(__('notifications.reset_password.subject'))
            ->greeting(__('notifications.greeting', ['name' => $user->name]))
            ->line(__('notifications.reset_password.line'))
            ->action(__('notifications.reset_password.action'), $resetUrl($user, $token))
            ->line(__('notifications.reset_password.expires', ['minutes' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire')]))
            ->line(__('notifications.reset_password.ignore'))
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')])));

        VerifyEmail::createUrlUsing($verifyUrl);
        VerifyEmail::toMailUsing(fn (User $user, string $url): MailMessage => (new MailMessage)
            ->subject(__('notifications.verify_email.subject'))
            ->greeting(__('notifications.greeting', ['name' => $user->name]))
            ->line(__('notifications.verify_email.line'))
            ->action(__('notifications.verify_email.action'), $url)
            ->line(__('notifications.verify_email.ignore'))
            ->salutation(__('notifications.salutation', ['shop' => config('app.name')])));
    }
}
