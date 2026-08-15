<?php

declare(strict_types=1);

namespace Tests;

use App\Http\Middleware\HandleInertiaRequests;
use App\Panels\Admin\AdminPanelProvider;
use App\Panels\App\AppPanelProvider;
use App\Providers\FortifyServiceProvider as ExampleFortifyServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Vite;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Laravel\Fortify\FortifyServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PandaPanel\PandaPanelServiceProvider;
use Tests\Fixtures\Panel\FakeVite;

/**
 * The application every test runs against.
 *
 * Testbench boots a minimal Laravel with the package's provider registered.
 * The application half — the user model, the two example panels, the classes
 * they discover — comes from `examples/`, which is autoloaded under `App\`
 * for development only. Using the examples as the test application means they
 * are exercised by the suite rather than left to rot beside it.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            InertiaServiceProvider::class,
            FortifyServiceProvider::class,
            PandaPanelServiceProvider::class,
            ExampleFortifyServiceProvider::class,
        ];
    }

    /**
     * The package root, so `resource_path()` and `base_path()` answer the way
     * they do in an application that has published the frontend.
     *
     * The panel reads three things through those helpers — the icon registry,
     * the generator stubs, and the TypeScript the serialized schemas are
     * checked against. Pointing at Testbench's empty skeleton would mean
     * testing them against files that are not there.
     */
    public static function applicationBasePath(): string
    {
        return (string) realpath(__DIR__.'/..');
    }

    /**
     * The example application, in place of Testbench's empty skeleton.
     *
     * The panel providers call `app_path('Panels/Admin/Resources')` and
     * friends, so discovery only finds anything when `app_path()` points at
     * the examples.
     */
    protected function resolveApplicationConfiguration($app): void
    {
        $app->useAppPath((string) realpath(__DIR__.'/../examples/app'));
        $app->useDatabasePath((string) realpath(__DIR__.'/../examples/database'));

        // `route:cache` rebuilds the application from `bootstrap/app.php`, and
        // a package repository has no such file of its own. Testbench's is the
        // one that knows how to build this application, and its `bootstrap`
        // directory is where every cache this run writes belongs.
        $app->useBootstrapPath((string) realpath(__DIR__.'/../vendor/orchestra/testbench-core/laravel/bootstrap'));

        // Compiled views, the private disk exports land on, logs. Under
        // `build/`, which is ignored, so a checkout needs no directories that
        // git cannot track and a clean is `rm -rf build`.
        $app->useStoragePath(dirname(__DIR__).'/build/testbench/storage');

        parent::resolveApplicationConfiguration($app);
    }

    /**
     * The directories the harness writes to, created on first use.
     *
     * Git cannot commit an empty directory, and a `.gitkeep` in each would be
     * five files whose only job is to exist. Making them here means a fresh
     * clone runs the suite with no setup step, and `rm -rf build bootstrap`
     * is a complete clean.
     *
     * Two of them have to be under the base path rather than under `build/`,
     * because the base path during a test run is this package:
     *
     *   `bootstrap/cache` — Laravel builds its package manifest while the
     *   application is still being constructed, before anything can move it.
     *
     *   `resources/views` — the view finder globs it, and `route:cache`
     *   rebuilds the application in a process that never sees this class.
     *
     * Neither is shipped: the package has no Blade views of its own, and both
     * are ignored by git.
     */
    public static function prepareWritableDirectories(): void
    {
        $root = dirname(__DIR__);

        $directories = [
            'bootstrap/cache',
            'resources/views',
            'build/testbench/storage/app/private',
            'build/testbench/storage/framework/views',
            'build/testbench/storage/framework/cache/data',
            'build/testbench/storage/framework/sessions',
            'build/testbench/storage/logs',
        ];

        foreach ($directories as $directory) {
            if (! is_dir($path = $root.'/'.$directory)) {
                mkdir($path, recursive: true);
            }
        }
    }

    protected function defineEnvironment($app): void
    {
        // The Inertia root view lives with the examples, because it is the
        // application's file rather than the package's: it names the
        // application's own Vite entrypoints and appends the panel's.
        $app->make('view')->addLocation((string) realpath(__DIR__.'/../examples/resources/views'));

        // No PHP test should depend on `npm run build` having been run.
        $app->instance(Vite::class, new FakeVite);

        $app->afterResolving(Kernel::class, static function (mixed $kernel): void {
            // The application's own shared props — who is signed in, what the
            // application is called. The panel's own come from the package.
            if (method_exists($kernel, 'appendMiddlewareToGroup')) {
                $kernel->appendMiddlewareToGroup('web', HandleInertiaRequests::class);
            }
        });

        // Nothing here registers the guest redirect. It used to, standing in
        // for the `bootstrap/app.php` an application would have written it
        // in; the package now registers it itself, and leaving this bare is
        // what makes every auth test in this suite a real test of that.

        tap($app->make(Repository::class), function (Repository $config): void {
            $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

            $config->set('database.default', 'testing');
            $config->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);

            $config->set('session.driver', 'array');
            $config->set('cache.default', 'array');
            $config->set('queue.default', 'sync');
            $config->set('mail.default', 'array');

            // Exports and imports write to a disk that is never public, and
            // the suite asserts on what lands there.
            $config->set('filesystems.disks.local', [
                'driver' => 'local',
                'root' => storage_path('app/private'),
                'serve' => true,
                'throw' => false,
            ]);

            $config->set('panda-panel.panels', [
                AdminPanelProvider::class,
                AppPanelProvider::class,
            ]);

            // Inertia checks that a page component exists before asserting on
            // it. The components ship with the package; an application gets
            // them under `resources/js` by publishing.
            $config->set('inertia.pages.paths', [
                (string) realpath(__DIR__.'/../resources/js/pages'),
                (string) realpath(__DIR__.'/../examples/resources/js/pages'),
            ]);
            $config->set('inertia.pages.extensions', ['vue']);
        });
    }

    /**
     * The example application's own routes.
     *
     * A panel arrives into an application that already has a dashboard and a
     * set of settings URLs, and several behaviours only exist at that seam —
     * the flash toast that survives a redirect out of a panel, the settings
     * addresses that redirect into one. Registering them here means the suite
     * tests the seam rather than the panel in isolation.
     */
    protected function defineRoutes($router): void
    {
        // The `web` group explicitly: an application gets it from
        // `withRouting(web: ...)`, and without it these routes would have no
        // session — which is most of what they are here to exercise.
        $router->middleware('web')->group(__DIR__.'/../examples/routes/web.php');

        require __DIR__.'/../examples/routes/channels.php';
    }

    /**
     * Laravel's own tables, the example application's, and the package's.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../examples/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/fortify/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../vendor/laravel/passkeys/database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
