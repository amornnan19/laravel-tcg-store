<?php

use App\Http\Controllers\Storefront\NotBuiltYet;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

// Registered now so links can use route(); M3 builds these pages and replaces the controller.
Route::get('/search', NotBuiltYet::class)->name('search');
Route::get('/c/{card:slug}', NotBuiltYet::class)->name('card.show');
Route::get('/s/{set:code}', NotBuiltYet::class)->name('set.show');
