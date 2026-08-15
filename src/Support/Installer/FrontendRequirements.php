<?php

declare(strict_types=1);

namespace PandaPanel\Support\Installer;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * What the published frontend needs from the application around it.
 *
 * Every panel screen is a Vue component that reaches an application by
 * `vendor:publish` and is built by that application's Vite. Three things have
 * to be true for that to work, and none of them is something this package can
 * do on its behalf:
 *
 * 1. **npm dependencies.** The components import sixteen packages. The list
 *    lives in this repository's own `package.json` and is read from there
 *    rather than restated here — a second copy is a copy that goes stale the
 *    first time a component imports something new.
 *
 * 2. **The host seam.** Eighteen modules the components import and do not
 *    ship: `@/routes/*` and `@/actions/*`, which Wayfinder generates from the
 *    application's own routes, and a handful of components a Laravel Vue
 *    starter kit already has. See `frontend/host/README.md` in this
 *    repository for why each one is the application's rather than ours.
 *
 * 3. **Vite.** No entrypoint, no build.
 *
 * Getting any of them wrong fails at `npm run build`, in a message about a
 * module specifier — which is a true error about the wrong thing. Checking
 * here turns each into a sentence naming what to install.
 */
final class FrontendRequirements
{
    /**
     * Where each host-seam module is expected, relative to `resources/js`.
     *
     * Extensionless: a Wayfinder module is `.ts`, a component is `.vue`, and
     * a starter kit may write either as a directory with an `index`. The
     * check tries all of them.
     *
     * @var list<string>
     */
    private const HOST_MODULES = [
        'routes',
        'routes/login',
        'routes/register',
        'routes/password',
        'routes/two-factor',
        'routes/verification',
        'actions/App/Http/Controllers/Settings/ProfileController',
        'actions/App/Http/Controllers/Settings/SecurityController',
        'actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController',
        'components/Heading',
        'components/UserInfo',
        'components/UserMenuContent',
        'components/PasskeyItem',
        'components/PasskeyRegister',
        'components/TwoFactorRecoveryCodes',
        'components/TwoFactorSetupModal',
        'composables/useTwoFactorAuth',
        'types',
    ];

    /** @var list<string> */
    private const EXTENSIONS = ['.ts', '.vue', '/index.ts', '/index.vue', ''];

    /**
     * The npm packages an application needs, as `name@range` pairs ready to
     * be handed to `npm install`.
     *
     * @return list<string>
     */
    public static function npmPackages(): array
    {
        $manifest = dirname(__DIR__, 3).'/package.json';

        if (! File::exists($manifest)) {
            return [];
        }

        $decoded = json_decode(File::get($manifest), associative: true);

        if (! is_array($decoded) || ! is_array($decoded['dependencies'] ?? null)) {
            return [];
        }

        $packages = [];

        foreach ($decoded['dependencies'] as $name => $range) {
            if (is_string($name) && is_string($range)) {
                $packages[] = $name.'@'.$range;
            }
        }

        sort($packages);

        return $packages;
    }

    /**
     * The npm packages the application does not already have.
     *
     * Read from its `package.json` rather than from `node_modules`, because
     * what matters is whether the project has *declared* the dependency — a
     * transitive copy on disk today is one somebody else's upgrade removes
     * tomorrow.
     *
     * @return list<string> the same `name@range` pairs, filtered
     */
    public static function missingNpmPackages(): array
    {
        $path = base_path('package.json');

        if (! File::exists($path)) {
            return self::npmPackages();
        }

        $decoded = json_decode(File::get($path), associative: true);
        $declared = [];

        if (is_array($decoded)) {
            foreach (['dependencies', 'devDependencies'] as $section) {
                if (is_array($decoded[$section] ?? null)) {
                    $declared += $decoded[$section];
                }
            }
        }

        return array_values(array_filter(
            self::npmPackages(),
            // `beforeLast` rather than `before`: a scoped package's name
            // starts with the same character the range is joined on.
            static fn (string $package): bool => ! array_key_exists(
                Str::beforeLast($package, '@'),
                $declared,
            ),
        ));
    }

    /**
     * The host-seam modules the application is missing, as `@/…` specifiers.
     *
     * @return list<string>
     */
    public static function missingHostModules(): array
    {
        $missing = [];

        foreach (self::HOST_MODULES as $module) {
            if (! self::exists($module)) {
                $missing[] = '@/'.$module;
            }
        }

        return $missing;
    }

    /**
     * Whether the application has a Vite config at all.
     */
    public static function hasVite(): bool
    {
        return File::exists(base_path('vite.config.ts'))
            || File::exists(base_path('vite.config.js'));
    }

    /**
     * Whether the application is an Inertia application.
     *
     * @return list<string> what is missing, in words, empty when nothing is
     */
    public static function missingInertia(): array
    {
        $missing = [];

        if (! File::exists(resource_path('views/app.blade.php'))) {
            $missing[] = 'an Inertia root view at resources/views/app.blade.php';
        }

        if (! File::exists(app_path('Http/Middleware/HandleInertiaRequests.php'))) {
            $missing[] = 'Inertia\'s middleware (php artisan inertia:middleware)';
        }

        return $missing;
    }

    private static function exists(string $module): bool
    {
        foreach (self::EXTENSIONS as $extension) {
            if (File::exists(resource_path('js/'.$module.$extension))) {
                return true;
            }
        }

        return false;
    }
}
