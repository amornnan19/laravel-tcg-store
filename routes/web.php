<?php

use App\Http\Controllers\Admin\SlipController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LineController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Storefront\AccountAlertController;
use App\Http\Controllers\Storefront\AccountController;
use App\Http\Controllers\Storefront\AccountDeckController;
use App\Http\Controllers\Storefront\CardController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\DeckController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\OrderController;
use App\Http\Controllers\Storefront\PaymentQrController;
use App\Http\Controllers\Storefront\ResultsController;
use App\Http\Controllers\Storefront\SetController;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/search', [ResultsController::class, 'search'])->name('search');
Route::get('/g/{game:slug}', [ResultsController::class, 'game'])->name('game.show');

Route::get('/c/{card:slug}', CardController::class)->name('card.show');

Route::get('/s/{set:code}', SetController::class)->name('set.show');

Route::get('/cart', CartController::class)->name('cart');

Route::get('/checkout', CheckoutController::class)->name('checkout');

Route::get('/deck', DeckController::class)->name('deck.import');

Route::get('/orders/{order:number}', OrderController::class)->name('order.show');

Route::get('/orders/{order:number}/qr.png', PaymentQrController::class)->name('order.qr');

Route::get('/admin/slips/{payment}', SlipController::class)
    ->middleware(['auth', AuthenticateSession::class, 'can:view,payment'])
    ->name('admin.slip');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed:relative', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::post('/account/line/unlink', [LineController::class, 'unlink'])->name('line.unlink');
    Route::get('/account', AccountController::class)->name('account');
    Route::post('/account/notifications', [AccountController::class, 'notifications'])->name('account.notifications');
    Route::delete('/account/wishlist/{wishlistItem}', [AccountAlertController::class, 'destroyWishlistItem'])->name('account.wishlist.destroy');
    Route::delete('/account/alerts/price/{priceAlert}', [AccountAlertController::class, 'destroyPriceAlert'])->name('account.alerts.price.destroy');
    Route::delete('/account/alerts/restock/{restockAlert}', [AccountAlertController::class, 'destroyRestockAlert'])->name('account.alerts.restock.destroy');
    Route::delete('/account/decks/{savedDeckList}', [AccountDeckController::class, 'destroy'])->name('account.decks.destroy');
});

Route::get('/auth/line', [LineController::class, 'redirect'])->name('line.redirect');
Route::get('/auth/line/callback', [LineController::class, 'callback'])->name('line.callback');
