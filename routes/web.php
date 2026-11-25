<?php

use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\NotBuiltYet;
use App\Http\Controllers\Storefront\ResultsController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/search', [ResultsController::class, 'search'])->name('search');
Route::get('/g/{game:slug}', [ResultsController::class, 'game'])->name('game.show');

// Registered now so links can use route(); M3 builds these pages and replaces the controller.
Route::get('/c/{card:slug}', NotBuiltYet::class)->name('card.show');
Route::get('/s/{set:code}', NotBuiltYet::class)->name('set.show');
