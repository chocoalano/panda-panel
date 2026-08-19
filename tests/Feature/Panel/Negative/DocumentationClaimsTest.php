<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use PandaPanel\Support\Installer\FrontendRequirements;

/*
 * Numbers the documentation states about itself.
 *
 * Every one of these was already wrong once. `README.md` claimed a file count
 * that was eight short, the index claimed a page count that was two short, and
 * both were written by hand in the same session that corrected an earlier
 * version of the same claim — because a number in prose has nothing holding it
 * to the thing it describes.
 *
 * The counts are stated two different ways on purpose, and the difference is
 * the point:
 *
 * - **An exact count** is right for something whose whole job is to be
 *   complete. `docs/pages.md` is the index — `docs/index.md` is the site's home
 *   page and lists nothing — and a page missing from the index is a page nobody
 *   can find, so the number and the tree have to agree exactly.
 * - **A floor** is right for something that only grows. "over 350 files" and a
 *   "1,200-test suite" describe scale, and a claim that gets *safer* as the
 *   codebase grows is a claim nobody has to remember to update. It only fails
 *   if the real figure ever drops below what was promised, which is the one
 *   case worth being told about.
 */

it('states the number of pages the documentation actually has', function (): void {
    $index = File::get(base_path('docs/pages.md'));

    preg_match('/^(\d+) pages\./m', $index, $claim);

    expect($claim)->not->toBeEmpty('docs/pages.md no longer states a page count.');

    $actual = collect(File::allFiles(base_path('docs')))
        ->filter(static fn (SplFileInfo $file): bool => $file->getExtension() === 'md')
        ->count();

    // Exact, not a floor: the index is the one document whose contract is
    // completeness, and the next test holds the tree to it.
    expect((int) $claim[1])->toBe($actual);

    // The same number is spelled out again on every page that sends a reader
    // to the index. A count repeated in prose drifts one copy at a time, so
    // all of them are checked against the tree rather than against each other.
    $stale = [];

    foreach ([...File::allFiles(base_path('docs')), new SplFileInfo(base_path('README.md'))] as $file) {
        if (! str_ends_with($file->getPathname(), '.md')) {
            continue;
        }

        preg_match_all('/All ([\d,]+) pages/', File::get($file->getPathname()), $matches);

        foreach ($matches[1] as $stated) {
            if ((int) str_replace(',', '', $stated) !== $actual) {
                $stale[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    expect(array_values($stale))->toBe([]);
});

it('links every documentation page from its index', function (): void {
    $index = File::get(base_path('docs/pages.md'));

    $pages = collect(File::allFiles(base_path('docs')))
        ->filter(static fn (SplFileInfo $file): bool => $file->getExtension() === 'md')
        ->map(static fn (SplFileInfo $file): string => str_replace(
            base_path('docs').'/',
            '',
            $file->getPathname(),
        ))
        // The index does not link itself, and the other two are navigation
        // rather than pages to read: `index.md` is the site's home page and
        // `sidebar.md` is this same tree collapsed to one entry per section.
        ->reject(static fn (string $path): bool => in_array(
            $path,
            ['index.md', 'sidebar.md', 'pages.md'],
            true,
        ))
        ->values();

    $missing = $pages
        ->reject(static fn (string $path): bool => str_contains($index, "]({$path})"))
        ->all();

    // Collected and asserted as a list rather than inside the loop, so a
    // failure names every page at once instead of the first one.
    expect(array_values($missing))->toBe([]);
});

it('never claims more frontend files than it has', function (): void {
    preg_match(
        '/over (\d+) Vue and TypeScript files/',
        File::get(base_path('README.md')),
        $claim,
    );

    expect($claim)->not->toBeEmpty('README.md no longer states a frontend file count.');

    $actual = collect(File::allFiles(base_path('resources/js')))
        ->filter(static fn (SplFileInfo $file): bool => in_array(
            $file->getExtension(),
            ['vue', 'ts'],
            true,
        ))
        ->count();

    expect($actual)->toBeGreaterThanOrEqual((int) $claim[1]);
});

it('counts the host modules the same way everywhere it says a number', function (): void {
    // This one drifted twice. `HOST_MODULES` gained `types/ui` and three
    // documents went on saying eighteen; one of them was then corrected in the
    // same pass that left a fourth behind. A number repeated in prose has
    // nothing holding it to the list it describes, so this is that.
    $actual = count((new ReflectionClass(FrontendRequirements::class))
        ->getConstant('HOST_MODULES'));

    $spelled = [
        18 => 'eighteen', 19 => 'nineteen', 20 => 'twenty',
    ][$actual] ?? null;

    expect($spelled)->not->toBeNull("No spelling known for {$actual} host modules.");

    $wrong = [];

    foreach ([...File::allFiles(base_path('docs')), new SplFileInfo(base_path('README.md'))] as $file) {
        if (! str_ends_with($file->getPathname(), '.md')) {
            continue;
        }

        $contents = File::get($file->getPathname());

        // Only where the sentence is about the modules, so the theme's own
        // eighteen properties and the ADR's eighteen decisions are left alone.
        if (preg_match('/\b(eighteen|nineteen|twenty)\b(?=[^.]{0,80}modules)/i', $contents, $match) !== 1) {
            continue;
        }

        if (strtolower($match[1]) !== $spelled) {
            $wrong[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect(array_values($wrong))->toBe([]);
});

it('never claims a larger test suite than it has', function (): void {
    preg_match('/([\d,]+)-test suite/', File::get(base_path('README.md')), $claim);

    expect($claim)->not->toBeEmpty('README.md no longer states a suite size.');

    $tests = collect(File::allFiles(base_path('tests')))
        ->filter(static fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), 'Test.php'))
        ->sum(static fn (SplFileInfo $file): int => preg_match_all(
            '/^\s*(it|test)\(/m',
            File::get($file->getPathname()),
        ));

    expect($tests)->toBeGreaterThanOrEqual(
        (int) str_replace(',', '', $claim[1]),
    );
});
