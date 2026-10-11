<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityActivityController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SessionController;
use App\Http\Middleware\AuditAuthenticationFailures;
use App\Http\Middleware\RequireRecentAuthenticationForEmailChange;
/* @chisel-password-confirmation */
use Illuminate\Auth\Middleware\RequirePassword;
/* @end-chisel-password-confirmation */
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])
        ->middleware(RequireRecentAuthenticationForEmailChange::class)
        ->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        /* @chisel-password-confirmation */
        ->middleware(RequirePassword::class)
        /* @end-chisel-password-confirmation */
        ->name('security.edit');

    Route::get('settings/sessions', [SessionController::class, 'index'])
        ->name('sessions.index');

    Route::get('settings/security-activity', SecurityActivityController::class)
        ->name('security-activity.index');

    Route::middleware([
        AuditAuthenticationFailures::class,
        RequirePassword::using(passwordTimeoutSeconds: 900),
        'throttle:session-revocation',
    ])->group(function () {
        Route::delete('settings/sessions/all', [SessionController::class, 'destroyAll'])
            ->name('sessions.destroy-all');
        Route::delete('settings/sessions', [SessionController::class, 'destroyOthers'])
            ->name('sessions.destroy-others');
        Route::delete('settings/sessions/{fingerprint}', [SessionController::class, 'destroy'])
            ->name('sessions.destroy');
    });

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});

/* @chisel-passkeys */
Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
/* @end-chisel-passkeys */
