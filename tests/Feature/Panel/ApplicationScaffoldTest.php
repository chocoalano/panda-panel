<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use PandaPanel\Support\Installer\ApplicationScaffold;
use PandaPanel\Support\Installer\InertiaMiddlewareRegistrar;

/*
|--------------------------------------------------------------------------
| What `panel:install` writes for an application that has no frontend
|--------------------------------------------------------------------------
|
| `panel:install` on a Laravel Vue starter kit finished with nothing left to
| do. On a blank `laravel new` it finished with a list of nine, and every one
| of them was a file this package could have written: a root view, an
| entrypoint, a Vite config with the `@` alias every published component
| imports through, and the nineteen host modules the components import and do
| not ship.
|
| These are the rules for writing them. The one that matters most is the one
| that says no: nothing here overwrites, and an application that already has
| an entrypoint has made a decision.
|
| The command itself is not run end to end, for the reason
| `InstallCommandSmokeTest` gives — the suite uses this package as the
| application, so a publish would copy `resources/js` onto itself. So the
| filesystem half runs against a scratch base path, which is the only way to
| model an application that genuinely has nothing.
|
*/

/**
 * An empty application, somewhere this suite is allowed to write.
 */
function scratchApplication(): string
{
    $path = dirname(__DIR__, 3).'/build/testbench/scaffold-'.bin2hex(random_bytes(4));

    File::ensureDirectoryExists($path);

    app()->setBasePath($path);

    return $path;
}

afterEach(function (): void {
    if (isset($this->scratch)) {
        File::deleteDirectory($this->scratch);
    }
});

/*
 * The application files
 */

it('names a stub for every file it writes, and ships all of them', function (): void {
    foreach (ApplicationScaffold::files() as $relative => $stub) {
        expect(File::exists(dirname(__DIR__, 3).'/stubs/install/'.$stub.'.stub'))
            ->toBeTrue("stubs/install/{$stub}.stub is named by {$relative} and is not there");
    }
});

it('writes every file a blank application is missing', function (): void {
    $this->scratch = scratchApplication();

    expect(ApplicationScaffold::missing())->toBe([
        'resources/views/app.blade.php',
        'resources/js/app.ts',
        'resources/css/app.css',
        'vite.config.ts',
    ]);

    foreach (ApplicationScaffold::missing() as $relative) {
        expect(ApplicationScaffold::write($relative))->toBeTrue();
    }

    expect(ApplicationScaffold::missing())->toBe([])
        // The alias is the whole reason a scaffolded Vite config exists: every
        // published component imports through `@/…`, and a blank Laravel's own
        // config has no alias and no Vue plugin.
        ->and(File::get($this->scratch.'/vite.config.ts'))->toContain("'@': resolve(root, 'resources/js')")
        ->and(File::get($this->scratch.'/vite.config.ts'))->toContain('@vitejs/plugin-vue')
        ->and(File::get($this->scratch.'/resources/views/app.blade.php'))->toContain('@inertia')
        ->and(File::get($this->scratch.'/resources/js/app.ts'))->toContain('createInertiaApp');
});

it('never overwrites a file the application already has', function (): void {
    $this->scratch = scratchApplication();

    File::ensureDirectoryExists($this->scratch.'/resources/js');
    File::put($this->scratch.'/resources/js/app.ts', '// mine');

    expect(ApplicationScaffold::missing())->not->toContain('resources/js/app.ts')
        ->and(ApplicationScaffold::write('resources/js/app.ts'))->toBeFalse()
        ->and(File::get($this->scratch.'/resources/js/app.ts'))->toBe('// mine');
});

it('does not scaffold an entrypoint that overwrites a page’s own layout', function (): void {
    // The one thing about this seam that cannot be fixed from inside the
    // package — `page.default.layout = AppLayout` renders every panel screen
    // inside the application's shell, on an HTTP 200 with no error. The
    // installer checks an application for it, so the file it writes itself had
    // better not be the thing it warns about.
    $entry = File::get(dirname(__DIR__, 3).'/stubs/install/app.ts.stub');

    expect(preg_match('/^\s*page\.default\.layout\s*=/m', $entry))->toBe(0);
});

/*
 * The Vite config a blank Laravel already has
 */

it('spots a vite.config.js that cannot build the panel', function (): void {
    $this->scratch = scratchApplication();

    // Exactly what `laravel new` ships: Laravel's plugin and Tailwind, no Vue
    // plugin and no alias. And it is the file Vite reads *first*, so a
    // vite.config.ts written beside it would never be read.
    File::put($this->scratch.'/vite.config.js', <<<'JS'
        import laravel from 'laravel-vite-plugin';
        import tailwindcss from '@tailwindcss/vite';

        export default defineConfig({
            plugins: [laravel({ input: ['resources/css/app.css'] }), tailwindcss()],
        });
        JS);

    expect(ApplicationScaffold::staleViteConfig())->toBe('vite.config.js')
        // And not written beside it, which is what makes this a replacement
        // rather than one more file.
        ->and(ApplicationScaffold::missing())->not->toContain('vite.config.ts');
});

it('leaves a vite.config.js that already builds Vue alone', function (): void {
    $this->scratch = scratchApplication();

    File::put($this->scratch.'/vite.config.js', <<<'JS'
        import vue from '@vitejs/plugin-vue';

        export default defineConfig({
            plugins: [vue()],
            resolve: { alias: { '@': 'resources/js' } },
        });
        JS);

    expect(ApplicationScaffold::staleViteConfig())->toBeNull();
});

it('keeps the config it replaces, rather than deleting it', function (): void {
    $this->scratch = scratchApplication();

    File::put($this->scratch.'/vite.config.js', '// mine');

    expect(ApplicationScaffold::replaceViteConfig('vite.config.js'))->toBe('vite.config.js.bak')
        ->and(File::get($this->scratch.'/vite.config.js.bak'))->toBe('// mine')
        ->and(File::exists($this->scratch.'/vite.config.js'))->toBeFalse()
        ->and(File::get($this->scratch.'/vite.config.ts'))->toContain('@vitejs/plugin-vue');
});

/*
 * The stylesheet
 */

it('points an existing app.css at the panel stylesheet, after the imports already there', function (): void {
    $this->scratch = scratchApplication();

    File::ensureDirectoryExists($this->scratch.'/resources/css');
    File::put($this->scratch.'/resources/css/panda-panel.css', '/* published */');
    File::put($this->scratch.'/resources/css/app.css', "@import 'tailwindcss';\n\n.mine { color: red; }\n");

    expect(ApplicationScaffold::linkStylesheet())->toBeTrue();

    $written = File::get($this->scratch.'/resources/css/app.css');

    // CSS requires every `@import` before any rule, so appending would have
    // produced a file the build rejects.
    expect($written)->toContain("@import './panda-panel.css';")
        ->and(mb_strpos($written, 'panda-panel.css'))->toBeLessThan((int) mb_strpos($written, '.mine'))
        ->and($written)->toContain('.mine { color: red; }');

    // Never twice.
    expect(ApplicationScaffold::linkStylesheet())->toBeFalse();
});

it('adds nothing when the panel stylesheet has not been published', function (): void {
    $this->scratch = scratchApplication();

    File::ensureDirectoryExists($this->scratch.'/resources/css');
    File::put($this->scratch.'/resources/css/app.css', "@import 'tailwindcss';\n");

    expect(ApplicationScaffold::linkStylesheet())->toBeFalse();
});

/*
 * The host seam
 */

it('ships a stand-in for every host module it offers to write', function (): void {
    $modules = ApplicationScaffold::hostModules();

    expect($modules)->not->toBe([])
        ->and($modules)->toHaveKey('resources/js/components/UserMenuContent.vue')
        ->and($modules)->toHaveKey('resources/js/types/ui.ts')
        ->and($modules)->toHaveKey('resources/js/routes/login.ts');

    foreach ($modules as $relative => $source) {
        expect(File::exists($source))->toBeTrue("{$relative} has no source")
            ->and($relative)->not->toEndWith('.md');
    }
});

it('writes the stand-ins a blank application has none of', function (): void {
    $this->scratch = scratchApplication();

    $written = ApplicationScaffold::writeHostModules();

    expect($written)->toHaveCount(count(ApplicationScaffold::hostModules()))
        ->and(ApplicationScaffold::missingHostModules())->toBe([])
        ->and(File::get($this->scratch.'/resources/js/components/UserInfo.vue'))->toContain('defineProps');
});

it('leaves a starter kit’s own components alone, however they are spelled', function (): void {
    $this->scratch = scratchApplication();

    // A starter kit may write a component as a directory with an index, and
    // its shared types as `types/index.d.ts`. Both are real answers to the
    // specifier, and a stand-in written over either would be replacing a
    // design with a placeholder.
    File::ensureDirectoryExists($this->scratch.'/resources/js/components/UserInfo');
    File::put($this->scratch.'/resources/js/components/UserInfo/index.vue', '<template />');

    File::ensureDirectoryExists($this->scratch.'/resources/js/types');
    File::put($this->scratch.'/resources/js/types/index.d.ts', 'export type Appearance = string;');

    $missing = ApplicationScaffold::missingHostModules();

    expect($missing)->not->toHaveKey('resources/js/components/UserInfo.vue')
        ->and($missing)->not->toHaveKey('resources/js/types/index.ts')
        ->and($missing)->toHaveKey('resources/js/components/Heading.vue');

    ApplicationScaffold::writeHostModules();

    expect(File::get($this->scratch.'/resources/js/components/UserInfo/index.vue'))->toBe('<template />')
        ->and(File::exists($this->scratch.'/resources/js/components/UserInfo.vue'))->toBeFalse();
});

it('writes nothing for the seam before the frontend is published', function (): void {
    // A stand-in is only ever needed by a component that imports it.
    $this->scratch = scratchApplication();

    expect(ApplicationScaffold::frontendIsPublished())->toBeFalse();

    File::ensureDirectoryExists($this->scratch.'/resources/js/panel');

    expect(ApplicationScaffold::frontendIsPublished())->toBeTrue();
});

/*
 * Inertia's middleware
 */

it('adds Inertia’s middleware to the web group', function (): void {
    $path = bootstrapFixture(<<<'PHP'
        <?php

        return Application::configure(basePath: dirname(__DIR__))
            ->withMiddleware(function (Middleware $middleware): void {
                //
            })
            ->withExceptions(function (Exceptions $exceptions): void {
                //
            })->create();
        PHP);

    expect(InertiaMiddlewareRegistrar::register($path))->toBe(InertiaMiddlewareRegistrar::REGISTERED);

    $written = File::get($path);

    expect($written)->toContain('$middleware->web(append: [')
        ->toContain('HandleInertiaRequests::class,')
        // Laravel's `//` placeholder said "your middleware goes here". Left
        // above a real call it reads as a comment about that call.
        ->and(substr_count($written, '//'))->toBe(1)
        // The rest of the file is untouched.
        ->and($written)->toContain('->withExceptions(function (Exceptions $exceptions): void {');

    File::delete($path);
});

it('adds it once', function (): void {
    $path = bootstrapFixture(<<<'PHP'
        <?php

        return Application::configure(basePath: dirname(__DIR__))
            ->withMiddleware(function (Middleware $middleware): void {
                $middleware->web(append: [
                    \App\Http\Middleware\HandleInertiaRequests::class,
                ]);
            })->create();
        PHP);

    $before = File::get($path);

    expect(InertiaMiddlewareRegistrar::register($path))
        ->toBe(InertiaMiddlewareRegistrar::ALREADY_PRESENT)
        ->and(File::get($path))->toBe($before);

    File::delete($path);
});

it('keeps middleware the application had already written', function (): void {
    $path = bootstrapFixture(<<<'PHP'
        <?php

        return Application::configure(basePath: dirname(__DIR__))
            ->withMiddleware(function (Middleware $middleware): void {
                $middleware->trustProxies(at: '*');
            })->create();
        PHP);

    expect(InertiaMiddlewareRegistrar::register($path))->toBe(InertiaMiddlewareRegistrar::REGISTERED)
        ->and(File::get($path))->toContain("trustProxies(at: '*')")
        ->and(File::get($path))->toContain('HandleInertiaRequests::class,');

    File::delete($path);
});

it('leaves a bootstrap file it does not recognise alone, and says so', function (): void {
    // `bootstrap/app.php` is the most rewritten file in a Laravel application
    // and the one that decides whether it boots. A pattern match that missed
    // is a report, never a guess.
    $path = bootstrapFixture("<?php\n\nreturn (new MyOwnBootstrapper)->create();\n");

    $before = File::get($path);

    expect(InertiaMiddlewareRegistrar::register($path))
        ->toBe(InertiaMiddlewareRegistrar::UNRECOGNISED)
        ->and(File::get($path))->toBe($before);

    File::delete($path);
});

it('says so rather than writing when there is no bootstrap file', function (): void {
    expect(InertiaMiddlewareRegistrar::register(sys_get_temp_dir().'/nothing-here-'.uniqid().'.php'))
        ->toBe(InertiaMiddlewareRegistrar::NO_BOOTSTRAP);
});

function bootstrapFixture(string $contents): string
{
    $path = sys_get_temp_dir().'/panda-panel-bootstrap-'.bin2hex(random_bytes(6)).'.php';

    File::put($path, $contents);

    return $path;
}
