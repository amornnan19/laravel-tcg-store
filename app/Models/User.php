<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_staff' => 'boolean',
            'line_notify_opt_in' => 'boolean',
        ];
    }

    /**
     * Only staff accounts may sign in to a Filament panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_staff === true;
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<WishlistItem, $this>
     */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /**
     * @return HasMany<PriceAlert, $this>
     */
    public function priceAlerts(): HasMany
    {
        return $this->hasMany(PriceAlert::class);
    }

    /**
     * @return HasMany<RestockAlert, $this>
     */
    public function restockAlerts(): HasMany
    {
        return $this->hasMany(RestockAlert::class);
    }

    /**
     * Email addresses are stored and looked up trimmed and lower-case, so `Mixed@Example.com` and `mixed@example.com`
     * are one account.
     */
    public static function normalizeEmail(?string $email): ?string
    {
        return $email === null ? null : Str::lower(trim($email));
    }

    public function hasLine(): bool
    {
        return $this->line_user_id !== null;
    }

    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    /**
     * Messages go to LINE only when the customer linked it, opted in, and the shop has a Messaging API token to push with.
     */
    public function prefersLine(): bool
    {
        return $this->hasLine()
            && $this->line_notify_opt_in
            && filled(config('services.line.messaging_token'));
    }

    public function routeNotificationForLine(): ?string
    {
        return $this->line_user_id;
    }

    /**
     * An account made through LINE may have no email; there is nothing to send a verification link to.
     */
    public function sendEmailVerificationNotification(): void
    {
        if ($this->email === null) {
            return;
        }

        parent::sendEmailVerificationNotification();
    }
}
