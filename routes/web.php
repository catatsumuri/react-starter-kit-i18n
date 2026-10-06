<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::inertia('/', 'admin')->name('dashboard');
    });
});

require __DIR__.'/settings.php';
