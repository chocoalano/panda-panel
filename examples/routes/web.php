<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SettingsRedirectController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Example application routes
|--------------------------------------------------------------------------
|
| The application half of the examples: the routes a Laravel starter kit
| already has, kept alongside the panels rather than replaced by them.
|
| The panel registers its own routes; nothing here does that. What is here is
| the surface an application keeps once the panel arrives — a dashboard the
| panel does not own, and the settings addresses that now redirect into it.
|
*/

Route::get('/', fn () => Inertia::render('Welcome'))->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('dashboard', fn () => Inertia::render('Dashboard'))->name('dashboard');
});

/*
 * Settings live in the panel now. These routes keep their names and their
 * addresses: the GET routes redirect into the panel's own settings pages, so
 * a bookmark or a Wayfinder-generated link still resolves.
 */
Route::middleware('auth')->group(function (): void {
    Route::get('settings', [SettingsRedirectController::class, 'profile']);
    Route::get('settings/profile', [SettingsRedirectController::class, 'profile'])->name('profile.edit');

    // The screen moved into the panel; the write did not. One place updates a
    // profile, whichever form posted to it.
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('settings/security', [SettingsRedirectController::class, 'security'])->name('security.edit');
    Route::get('settings/appearance', [SettingsRedirectController::class, 'appearance'])->name('appearance.edit');
});
