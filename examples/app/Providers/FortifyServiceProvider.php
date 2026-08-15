<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Fortify;

/**
 * The application's half of Fortify.
 *
 * The panel renders the login, register, and two-factor screens with its own
 * branding, and posts them to Fortify's own routes — so Fortify still owns
 * throttling, session fixation, two-factor, and passkeys. What it does not own
 * is what an account is made of, which is this file.
 */
final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);

        // The application keeps its own auth screens at the addresses it
        // always had. A panel with `->auth()` renders *its* versions of these,
        // branded as that panel, at its own URLs — both post to the same
        // Fortify routes, so there is one implementation of signing in.
        Fortify::loginView(static fn (): Response => Inertia::render('auth/Login'));
        Fortify::registerView(static fn (): Response => Inertia::render('auth/Register'));
        Fortify::requestPasswordResetLinkView(static fn (): Response => Inertia::render('auth/ForgotPassword'));
        Fortify::resetPasswordView(static fn (Request $request): Response => Inertia::render('auth/ResetPassword', [
            'token' => $request->route('token'),
            'email' => $request->query('email'),
        ]));
        Fortify::verifyEmailView(static fn (): Response => Inertia::render('auth/VerifyEmail'));
        Fortify::confirmPasswordView(static fn (): Response => Inertia::render('auth/ConfirmPassword'));
        Fortify::twoFactorChallengeView(static fn (): Response => Inertia::render('auth/TwoFactorChallenge'));
    }
}
