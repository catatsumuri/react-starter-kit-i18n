<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use LaravelLang\Locales\Facades\Locales;

Route::inertia('/', 'welcome')->name('home');

Route::post('/locale', function (Request $request) {
    $locale = $request->string('locale')->toString();

    if (Locales::isInstalled($locale)) {
        session()->put('Accept-Language', $locale);
    }

    return back();
})->name('locale.switch');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
