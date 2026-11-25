<?php

use App\Http\Controllers\Storefront\CardController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\OrderController;
use App\Http\Controllers\Storefront\PaymentQrController;
use App\Http\Controllers\Storefront\ResultsController;
use App\Http\Controllers\Storefront\SetController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/search', [ResultsController::class, 'search'])->name('search');
Route::get('/g/{game:slug}', [ResultsController::class, 'game'])->name('game.show');

Route::get('/c/{card:slug}', CardController::class)->name('card.show');

Route::get('/s/{set:code}', SetController::class)->name('set.show');

Route::get('/cart', CartController::class)->name('cart');

Route::get('/checkout', CheckoutController::class)->name('checkout');

Route::get('/orders/{order:number}', OrderController::class)->name('order.show');

Route::get('/orders/{order:number}/qr.png', PaymentQrController::class)->name('order.qr');
