<?php

declare(strict_types=1);

namespace PandaPanel\Support\Installer;

use Illuminate\Support\Facades\File;

/**
 * What the application got, when it published, so an upgrade can tell an edit
 * from a stale copy.
 *
 * ## The problem this solves
 *
 * The panel's frontend is published into the application rather than imported
 * from the package, and that is the right trade — a component you cannot see
 * the source of is a component you cannot debug, and the build-time
 * `import.meta.glob` registries require it. But it costs something real, and
 * the cost is the whole of this class: **once a file is the application's, a
 * package update cannot improve it.**
 *
 * With no record of what was published, an upgrade has two options and both
 * are bad. Re-publishing without `--force` skips every file, so nothing
 * updates. Re-publishing *with* `--force` overwrites everything, including the
 * files the project deliberately changed. Neither can tell the difference,
 * because "the file on disk differs from the package's" is true in both cases.
 *
 * ## Three hashes, not two
 *
 * The missing piece is the same one `git merge-base` supplies: a record of the
 * common ancestor. With the hash the file had *when it was published*, three
 * values answer the question exactly:
 *
 * | on disk | in package | means | what an update does |
 * | --- | --- | --- | --- |
 * | = manifest | = manifest | unchanged both sides | nothing |
 * | = manifest | ≠ manifest | stale, never edited | **overwrite, safely** |
 * | ≠ manifest | = manifest | you edited it, nothing upstream | leave alone |
 * | ≠ manifest | ≠ manifest | **conflict** — both changed | report, never touch |
 * | absent | present | you deleted it | leave alone |
 * | not in manifest | present | new since you published | **write it** |
 * | in manifest | absent | no longer shipped | report as removable |
 *
 * Only two rows are written automatically, and both are ones where the
 * application demonstrably has no opinion about the file. A conflict is
 * reported with its path and never resolved by guessing — that is a diff for
 * a person to read.
 *
 * ## Where it lives
 *
 * `.panel-assets.json` at the application's root, and it belongs in the
 * application's repository: it is a record of a decision the project made, in
 * the same way `composer.lock` is. Under `bootstrap/cache` it would be
 * regenerated and useless; under `storage` it would be gitignored and lost on
 * the first deploy.
 */
final class AssetManifest
{
    /** A file the application never published. */
    public const NEW = 'new';

    /** Published, untouched, and unchanged upstream. */
    public const CURRENT = 'current';

    /** Published, untouched, and changed upstream. Safe to overwrite. */
    public const STALE = 'stale';

    /** Published and then edited here. Nothing new upstream. */
    public const MODIFIED = 'modified';

    /** Edited here *and* changed upstream. A person has to look. */
    public const CONFLICT = 'conflict';

    /** Published and then deleted here. */
    public const DELETED = 'deleted';

    /** Published once, and the package no longer ships it. */
    public const REMOVED_UPSTREAM = 'removed-upstream';

    public static function path(): string
    {
        return base_path('.panel-assets.json');
    }

    public static function exists(): bool
    {
        return File::exists(self::path());
    }

    /**
     * The recorded hashes, keyed by application-relative destination.
     *
     * A manifest that cannot be parsed is treated as absent rather than
     * fatal: the worst outcome is that every file reads as `new`, which is
     * exactly the state an application that never published is in.
     *
     * @return array<string, string>
     */
    public static function read(): array
    {
        if (! self::exists()) {
            return [];
        }

        $decoded = json_decode((string) File::get(self::path()), associative: true);

        if (! is_array($decoded) || ! is_array($decoded['files'] ?? null)) {
            return [];
        }

        $files = [];

        foreach ($decoded['files'] as $path => $hash) {
            if (is_string($path) && is_string($hash)) {
                $files[$path] = $hash;
            }
        }

        return $files;
    }

    /**
     * Records the hashes of everything currently on disk that we ship.
     *
     * Hashes the **application's** copy rather than the package's, and the
     * distinction matters: this is a record of what the application has, so a
     * file that was published and then immediately edited is recorded as
     * edited. Recording the package's hash would claim the application had a
     * pristine copy it never had.
     *
     * @param  array<string, string>  $existing  hashes to keep for files not being written now
     */
    public static function write(array $existing = []): void
    {
        $files = $existing;

        foreach (PublishedAssets::files() as $destination => $source) {
            $relative = PublishedAssets::relative($destination);

            if (! File::exists($destination)) {
                unset($files[$relative]);

                continue;
            }

            $files[$relative] = self::hash($destination);
        }

        ksort($files);

        File::put(self::path(), json_encode([
            '_' => 'Written by php artisan panel:install / panel:assets. Commit this file: '
                .'it is the record of which version of the panel frontend this application '
                .'published, and without it an upgrade cannot tell your edits from a stale copy.',
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * Every shipped file, with what should happen to it.
     *
     * `$files` exists so the four states can be tested against a scratch
     * fixture. This repository is its own test application — its published
     * copies *are* the package's files — so with the real map, "on disk" and
     * "in the package" can never differ, and the two cases that matter most
     * would be untestable.
     *
     * @param  array<string, string>|null  $files  destination => source, defaulting to the real publish map
     * @return array<string, array{status: self::*, destination: string, source: string|null}>
     */
    public static function compare(?array $files = null): array
    {
        $manifest = self::read();
        $shipped = $files ?? PublishedAssets::files();
        $report = [];

        foreach ($shipped as $destination => $source) {
            $relative = PublishedAssets::relative($destination);
            $recorded = $manifest[$relative] ?? null;

            $report[$relative] = [
                'status' => self::statusFor($destination, $source, $recorded),
                'destination' => $destination,
                'source' => $source,
            ];
        }

        // Anything recorded that we no longer ship. Reported rather than
        // deleted: a file this package stopped shipping may well have been
        // adopted by the application in the meantime.
        foreach ($manifest as $relative => $hash) {
            if (! isset($report[$relative])) {
                $report[$relative] = [
                    'status' => self::REMOVED_UPSTREAM,
                    'destination' => base_path($relative),
                    'source' => null,
                ];
            }
        }

        ksort($report);

        return $report;
    }

    /**
     * @return self::*
     */
    private static function statusFor(string $destination, string $source, ?string $recorded): string
    {
        $onDisk = File::exists($destination) ? self::hash($destination) : null;
        $inPackage = self::hash($source);

        if ($recorded === null) {
            // Never published, or published before this manifest existed. A
            // file already on disk and identical to ours is the second case,
            // and is current rather than new.
            return $onDisk === $inPackage ? self::CURRENT : self::NEW;
        }

        if ($onDisk === null) {
            return self::DELETED;
        }

        $edited = $onDisk !== $recorded;
        $updated = $inPackage !== $recorded;

        return match (true) {
            $edited && $updated => self::CONFLICT,
            $edited => self::MODIFIED,
            $updated => self::STALE,
            default => self::CURRENT,
        };
    }

    /**
     * Content hash, with line endings normalised.
     *
     * A checkout on Windows, or an editor configured to write CRLF, would
     * otherwise report every file as edited — and a report where everything
     * is a conflict is a report nobody reads.
     */
    private static function hash(string $path): string
    {
        return hash('xxh128', str_replace("\r\n", "\n", (string) File::get($path)));
    }
}
