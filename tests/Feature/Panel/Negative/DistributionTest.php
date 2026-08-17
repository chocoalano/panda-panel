<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use PandaPanel\Support\Installer\FrontendRequirements;

/*
 * What the Composer archive has to contain.
 *
 * This suite runs with the repository itself as the application, so every file
 * is on disk and nothing here is exercised the way a real install exercises it.
 * That is exactly why these assertions exist: the one packaging fault that
 * matters was invisible to every other test for precisely that reason.
 */

it('ships its own package.json in the Composer archive', function (): void {
    // `FrontendRequirements::npmPackages()` reads this file at runtime from
    // inside `vendor/` to tell an application which npm packages the published
    // components import. Export-ignoring it did not make `panel:install`
    // complain — it made the check return an empty list, so the installer
    // reported no missing dependencies because it could not look.
    $attribute = trim((string) shell_exec('git check-attr export-ignore -- package.json 2>/dev/null'));

    expect($attribute)->toContain('export-ignore: unspecified');
});

it('keeps the lock file out of the archive', function (): void {
    // The counterpart: `package-lock.json` is a development artefact and an
    // application resolves its own. Only the manifest is read at runtime.
    $attribute = trim((string) shell_exec('git check-attr export-ignore -- package-lock.json 2>/dev/null'));

    expect($attribute)->toContain('export-ignore: set');
});

it('can tell a missing dependency list from an empty one', function (): void {
    // Two different facts that were the same empty array. `panel:install`
    // reports the second as "nothing is missing", which is true only if it
    // could look.
    expect(FrontendRequirements::hasNpmManifest())->toBeTrue()
        ->and(FrontendRequirements::npmManifestPath())->toEndWith('/package.json')
        ->and(File::exists(FrontendRequirements::npmManifestPath()))->toBeTrue()
        ->and(FrontendRequirements::npmPackages())->not->toBeEmpty();
});

it('names every command the documentation tells somebody to run', function (): void {
    // Five commands were documented that have never existed —
    // `composer run types:check`, `composer run lint:check`,
    // `npm run types:check`, `npm run lint:check` — in the master document and
    // in this repository's own agent skill. A verification loop somebody
    // cannot run is a verification loop nobody runs.
    //
    // This once scanned one master document. That file was removed when the
    // sectioned documentation replaced it, and a `continue` over a missing
    // path meant the guarantee would have quietly narrowed to the skill file
    // alone — a green test covering almost nothing. It scans every page under
    // `docs/` instead, which is both what the old file became and more than it
    // ever was.
    $composer = json_decode(File::get(base_path('composer.json')), true);
    $npm = json_decode(File::get(base_path('package.json')), true);

    $scripts = [
        ...array_keys($composer['scripts'] ?? []),
        ...array_keys($npm['scripts'] ?? []),
        // An *application's* script, not this repository's. The guides tell a
        // reader to rebuild after adding a component, and `npm run dev` is
        // what a Laravel Vue starter kit gives them. This package is a
        // library: it has `build` for its own toolchain and no dev server to
        // run, so the name is right on the page and absent from the manifest.
        'dev',
    ];

    $paths = [
        ...array_map(
            static fn (SplFileInfo $file): string => $file->getPathname(),
            File::allFiles(base_path('docs')),
        ),
        base_path('.claude/skills/panel-development/SKILL.md'),
    ];

    $paths = array_values(array_filter(
        $paths,
        static fn (string $path): bool => str_ends_with($path, '.md'),
    ));

    expect($paths)->not->toBeEmpty();

    $documented = [];

    foreach ($paths as $path) {
        if (! File::exists($path)) {
            continue;
        }

        // Only inside ```bash fences. Prose that *names* a command in order
        // to say it does not exist is not somebody being told to run it, and
        // scanning the whole file makes this test fail on its own explanation.
        preg_match_all('/```bash\n(.*?)```/s', File::get($path), $blocks);

        foreach ($blocks[1] as $block) {
            preg_match_all('/(?:composer run|npm run) ([a-z:-]+)/', $block, $matches);

            foreach ($matches[1] as $name) {
                $documented[$name] = $path;
            }
        }
    }

    $unknown = array_diff(array_keys($documented), $scripts);

    expect($unknown)->toBe([]);
});
