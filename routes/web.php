<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');
Route::view('/about', 'about')->name('about');
Route::view('/contact', 'contact')->name('contact');
Route::view('/faqs', 'faqs')->name('faqs');
Route::view('/privacy-policy', 'privacy-policy')->name('privacy');
Route::view('/terms-of-services', 'terms-of-services')->name('terms');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'role:admin'])
    ->group(function () {

    Route::livewire('/users', 'admin::user-manager')->name('users');
    Route::livewire('/dashboard', 'admin::dashboard')->name('dashboard');
});

Route::prefix('client')
    ->name('client.')
    ->middleware(['auth', 'role:client'])
    ->group(function () {

    Route::livewire('/dashboard', 'client::dashboard')->name('dashboard');
    Route::livewire('/wallet-topup', 'client::wallet-topup')->name('wallet-topup');
});

Route::prefix('staff')
    ->name('staff.')
    ->middleware(['auth', 'role:staff'])
    ->group(function () {

    Route::livewire('/dashboard', 'staff::dashboard')->name('dashboard');
});


require __DIR__.'/settings.php';
