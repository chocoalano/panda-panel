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
    | Home redirect
    |--------------------------------------------------------------------------
    |
    | The other half of the guest redirect. A Laravel Vue starter kit ships a
    | `/dashboard` route with an empty placeholder page, and points Fortify's
    | post-login redirect at it. Installing a panel changes neither, so the
    | first screen after signing in is that placeholder and the panel is
    | somewhere you have to know the URL of.
    |
    | With this on, a signed-in user who lands on one of these paths is sent to
    | the first panel they can enter instead. Nothing is rewritten to do it:
    | the application keeps its route, its route name, and its page component,
    | and turning this off gives all three back.
    |
    | The paths are `Request::is()` patterns, so `'reports/*'` hands over a
    | whole section. A path a panel itself is mounted on is ignored — the panel
    | answers for its own URLs, and redirecting one to itself is a loop.
    |
    | Set enabled to false for an application whose `/dashboard` is a real
    | screen it means to keep.
    |
    */

    'home_redirect' => [
        'enabled' => true,

        'paths' => ['dashboard'],
    ],

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
    | Integrations
    |--------------------------------------------------------------------------
    |
    | A resource with `integrations()->isEnabled(true)` gets a screen where an
    | administrator configures outbound HTTP requests fired on its writes. The
    | server issues those requests, which makes the screen a server-side
    | request forgery surface by construction — the destination is typed into
    | a form rather than written in code.
    |
    | Two gates, and a URL has to pass both.
    |
    | `allowed_hosts` is an allowlist of `Str::is()` patterns, and it is empty,
    | so nothing is reachable until a destination is added here. Deny by
    | default: a panel installed and left alone can call nowhere, and adding a
    | destination is a deploy rather than a form submission.
    |
    | `block_private_networks` refuses any host that resolves into the private,
    | loopback or link-local ranges — `169.254.169.254` above all, which is the
    | unauthenticated cloud metadata endpoint that hands out IAM credentials.
    | Checked when an integration is saved and again immediately before each
    | request, because a name approved last week can resolve elsewhere today.
    |
    | Leave the second on. It is what makes relaxing the first survivable.
    |
    */

    'integrations' => [

        'allowed_hosts' => [
            // 'api.example.com',
            // '*.partner.io',
        ],

        'block_private_networks' => true,

        /*
        | Delivery history.
        |
        | One row per attempt, which on a busy resource is a table that
        | outgrows the records it describes. So it is bounded twice, and both
        | bounds are applied immediately after each delivery rather than by
        | anything you have to schedule:
        |
        |   keep_per_integration — a hard cap. Only this many rows survive per
        |   integration, so the table is bounded at cap × integrations however
        |   much traffic there is. This is the bound that holds in an
        |   application with no scheduler at all.
        |
        |   retention_days — a window, so integrations that fire twice a year
        |   do not keep rows from three years ago. Set it to 0 to keep the cap
        |   and nothing else.
        |
        | Bodies are stored truncated. Headers never are: they hold the API
        | keys these requests carry, and a log of them would be a credential
        | store nobody meant to create.
        */

        'history' => [
            'enabled' => true,

            'keep_per_integration' => 50,

            'retention_days' => 30,
        ],

    ],

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
