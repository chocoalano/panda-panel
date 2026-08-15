<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Panels
    |--------------------------------------------------------------------------
    |
    | The panel providers to register, in order. Panels are listed explicitly
    | rather than discovered: registration order decides which panel a user is
    | sent to when the request does not name one, and adding a panel should be
    | a deliberate edit rather than a filesystem side effect.
    |
    | The classes *inside* a panel are discovered — see each provider's
    | discoverResources() / discoverPages() / discoverWidgets() calls.
    |
    | @var list<class-string<\PandaPanel\Core\PanelProvider>>
    |
    */

    'panels' => [
        // App\Panels\Admin\AdminPanelProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Panel routes are registered during boot, one group per panel, with the
    | path, domain and middleware each panel declares. Turn this off when the
    | application registers them itself — a test harness that boots panels
    | without HTTP, for example.
    |
    */

    'register_routes' => true,

    /*
    |--------------------------------------------------------------------------
    | Web middleware
    |--------------------------------------------------------------------------
    |
    | Two pieces of middleware belong to the whole `web` group rather than to
    | the panel route groups:
    |
    |   ResetPanelContext — clears the resolved panel at the start of every
    |   request, so nothing leaks between requests under Octane or between
    |   requests inside one test.
    |
    |   ShareFlashToast — maps Laravel's conventional flash keys onto the
    |   single toast channel the frontend listens on.
    |
    | Set this to false when you would rather register them yourself in
    | `bootstrap/app.php`, which is the right call if you need them at a
    | specific position in the stack.
    |
    */

    'register_web_middleware' => true,

    /*
    |--------------------------------------------------------------------------
    | Guest redirect
    |--------------------------------------------------------------------------
    |
    | Sends a guest who opens a panel URL to *that panel's* own login, when the
    | panel has one, and to the application's `login` route otherwise — which
    | is exactly what Laravel does by default, so turning this on adds a case
    | rather than replacing one.
    |
    | Set this to false if your application calls `redirectGuestsTo()` in
    | `bootstrap/app.php` itself. Yours would otherwise be overwritten. To keep
    | panel logins working alongside your own rule, call into it:
    |
    |   use PandaPanel\Support\PanelLoginRedirect;
    |
    |   ->withMiddleware(function (Middleware $middleware): void {
    |       $middleware->redirectGuestsTo(
    |           fn ($request) => PanelLoginRedirect::for($request) ?? route('welcome'),
    |       );
    |   })
    |
    */

    'register_guest_redirect' => true,

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    |
    | The package ships two migrations: Laravel's own `notifications` table
    | (which the notification centre reads on every panel request) and the
    | `two_factor_email_confirmed_at` column on `users`.
    |
    | They run from the package by default, because a panel cannot render
    | without the first — an install that had to remember a publish step would
    | 500 on its very first page. Both check before they touch anything, so an
    | application that already has the table or the column is untouched.
    |
    | Turn this off to own them yourself:
    |
    |   php artisan vendor:publish --tag=panda-panel-migrations
    |
    */

    'load_migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | Frontend
    |--------------------------------------------------------------------------
    |
    | Where `vendor:publish --tag=panda-panel-assets` puts the panel's Vue
    | components, and where the generators write the components they scaffold.
    | Both are relative to the application's `resources/` directory, because
    | every component registry in the frontend is an `import.meta.glob` over
    | these paths — a build-time allowlist by design.
    |
    */

    'frontend' => [
        'panel_path' => 'js/panel',
        'pages_path' => 'js/pages/Panels',
    ],

];
