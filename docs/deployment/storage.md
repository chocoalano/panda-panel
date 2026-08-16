# Storage Setup

A deployed panel writes files in four places: the disks its file fields upload to, the disk its exports and imports live on, the system temp directory both spreadsheet writers assemble into, and `bootstrap/cache` for the panel manifest. None of it is configured in `config/panda-panel.php` — disks and directories are declared on the field, the exporter, and the importer, where a queued job can read them without a container. Reach for this page when preparing a production environment, when uploads preview as broken images, or when moving from one server to several.

## A minimal working deploy

The defaults need two commands and no configuration of their own:

```bash
php artisan storage:link    # the public disk, which file uploads default to
php artisan panel:cache     # writes bootstrap/cache/panels.php
```

With a stock `config/filesystems.php`, that gives you:

```text
storage/app/public/uploads/…                      form uploads, served at /storage/uploads/…
storage/app/private/panel-exports/{userId}/…      exports, served only through the panel
storage/app/private/panel-imports/…               import uploads and failure reports
bootstrap/cache/panels.php                        the panel manifest
```

Nothing is written into `public/` by this package. `vendor:publish --tag=panda-panel-assets` writes Vue and CSS sources into `resources/`; the only thing under `public/` is your own Vite build.

## What a panel writes, and where

| What | Disk and path | Default | Written by |
| --- | --- | --- | --- |
| Form upload | `{FileUpload::getDisk()}` at `{FileUpload::getDirectory()}/{hash}.{ext}` | `public` / `uploads` | `PandaPanel\Http\Controllers\PanelUploadController` |
| Import upload | `{Importer::disk()}` at `{Importer::directory()}/{hash}.{ext}` | `local` / `panel-imports` | the same upload endpoint — `ImportAction` declares a `FileUpload` bound to the importer's disk |
| Export file | `{Exporter::disk()}` at `{Exporter::directory()}/{userKey}/{fileName}.{ext}` | `local` / `panel-exports` | `PandaPanel\Actions\Exports\ExportRun::write()` |
| Failure report | `{Importer::disk()}` at `{Importer::directory()}/{userKey}/failed-rows-{Y-m-d-His}.csv` | `local` / `panel-imports` | `PandaPanel\Actions\Imports\ImportRun::run()` |
| Temporary spreadsheet | `sys_get_temp_dir()`, named `panel-export-*` or `panel-import-failures-*` | | `ExportRun` and `ImportRun`, deleted once streamed onto the disk |
| Panel manifest | `bootstrap/cache/panels.php`, through `PanelManifest::path()` | | `php artisan panel:cache` |

The user key segment on exports and reports is not decoration. The download endpoints build that segment from **whoever is asking** rather than from the request, so one user cannot name another's file however they spell the path.

## The public disk, and why `storage:link` matters

`FileUpload` defaults to the `public` disk because the field's preview URL is resolved on the server, once, from the disk itself:

```php
// PandaPanel\Forms\Components\FileUpload::previewBase(), in outline
return rtrim(Storage::disk($this->disk)->url('/'), '/');
```

That string is serialized to the browser as `previewBase`, and the Vue field joins it to the stored path. The browser never turns a disk name into a link. On a stock application the `public` disk is `storage/app/public` with `'url' => APP_URL.'/storage'`, so `storage:link` is what makes `/storage/uploads/9f3c.png` resolve to a file at all.

```php
use PandaPanel\Forms\Components\FileUpload;

FileUpload::make('avatar')
    ->disk('public')
    ->directory('avatars')
    ->image()
    ->maxSize(1024);
```

On a release-directory deploy (Envoyer, Deployer, a Forge deploy script), `public/storage` is inside the release and `storage/` is shared, so the link has to be created for every release:

```bash
php artisan storage:link
```

An existing link is left alone — the command reports `The [public/storage] link already exists.` and still exits 0 — so it is safe on every deploy. `--force` recreates one that points somewhere else.

## Private disks, for exports and imports

`Exporter::disk()` returns `local`, not `public`, and it should stay that way. An export is a copy of records somebody was allowed to see; a public disk would put that at a URL anybody can guess. The download goes through the panel instead, which asks the question again:

| Route name | Path | Controller |
| --- | --- | --- |
| `panel.{id}.export-file` | `{panel}/exports/{file}` | `PandaPanel\Http\Controllers\PanelExportController` |
| `panel.{id}.import-file` | `{panel}/imports/{file}` | `PandaPanel\Http\Controllers\PanelImportController` |

Both refuse a `file` containing `/`, `\` or `..`, build the directory from `$request->user()->getAuthIdentifier()`, and 404 on anything that is not there. The caller names a file, never a path, so a traversal has nowhere to go. Point neither of them at the `public` disk.

## Declaring disks

Everything the panel stores comes from one of these declarations. All of them are read at the moment of the write, so changing one is a code deploy rather than a config change.

| Declaration | Signature | Default |
| --- | --- | --- |
| Upload disk | `PandaPanel\Forms\Components\FileUpload::disk(string $disk): self` | `'public'` |
| Upload directory | `FileUpload::directory(string $directory): self` | `'uploads'` |
| Upload size limit | `FileUpload::maxSize(int $kilobytes): self` | `5120` |
| Upload count limit | `FileUpload::maxFiles(int $max): self` | `null` |
| Accepted types | `FileUpload::acceptedTypes(array $types): self` | `[]` |
| Read back | `FileUpload::getDisk(): string`, `getDirectory(): string`, `getMaxSize(): int`, `getAcceptedTypes(): array`, `accepts(string $path): bool` | |
| Export disk | `PandaPanel\Actions\Exports\Exporter::disk(): string` | `'local'` |
| Export directory | `Exporter::directory(): string` | `'panel-exports'` |
| Export file name | `Exporter::fileName(): string` | kebab class name plus `Y-m-d-His` |
| Import disk | `PandaPanel\Actions\Imports\Importer::disk(): string` | `'local'` |
| Import directory | `Importer::directory(): string` | `'panel-imports'` |
| Image URL disk | `PandaPanel\Infolists\Components\ImageEntry::disk(string $disk): self` | none — the value is used as-is |

A dedicated disk keeps panel files out of whatever else `local` holds:

```php
// config/filesystems.php
'disks' => [
    'panel-files' => [
        'driver' => 'local',
        'root' => storage_path('app/panel-files'),
        'throw' => false,
    ],
],
```

```php
use PandaPanel\Actions\Exports\Exporter;

final class OrderExporter extends Exporter
{
    public static function disk(): string
    {
        return 'panel-files';
    }

    public static function directory(): string
    {
        return 'exports/orders';
    }

    // …columns()
}
```

An importer overrides the same two methods, and both are read by the upload endpoint (the file field `ImportAction` builds is bound to them) and by the failure report writer:

```php
use PandaPanel\Actions\Imports\Importer;

final class OrderImporter extends Importer
{
    public static function disk(): string
    {
        return 'panel-files';
    }

    public static function directory(): string
    {
        return 'imports/orders';
    }

    // …model(), columns()
}
```

An infolist renders a stored path through the disk it names:

```php
use PandaPanel\Infolists\Components\ImageEntry;

ImageEntry::make('avatar')->disk('public')->circular()->size(64);
```

`ImageEntry::toValue()` returns an absolute `http://`/`https://` value untouched, resolves anything else through `Storage::disk($disk)->url()`, and answers `null` when that call throws `RuntimeException` — the renderer then shows the placeholder, which is the honest outcome for a disk that serves nothing.

## Remote disks

| Feature | Remote disk | Why |
| --- | --- | --- |
| `FileUpload` | Yes | The endpoint calls `$file->store($directory, $disk)`, and previews come from `Storage::disk($disk)->url()`. |
| `ImageEntry::disk()` | Yes | `url()` again. |
| `Exporter::disk()` | Yes | `ExportRun` writes a local temporary file and streams it on with `put()`; the download uses `$disk->download()`. |
| `Importer::disk()` | **No** | `ImportAction` reads `Storage::disk($importer::disk())->path($stored)` and hands it to `fopen()` or `ZipArchive`. A driver whose `path()` is not a readable filesystem path cannot be read. |

```php
use PandaPanel\Forms\Components\FileUpload;

FileUpload::make('gallery')
    ->disk('s3')
    ->directory('posts/gallery')
    ->multiple()
    ->maxFiles(8)
    ->acceptedTypes(['image/png', 'image/jpeg']);
```

Two things to check on a remote disk:

- **Visibility.** The endpoint stores with `$file->store()` and never sets a visibility, so objects get the disk's default. A bucket that is private and a `previewBase` built from `AWS_URL` produce previews that 403.
- **Cost per submit.** `FileUpload::accepts()` calls `Storage::disk($disk)->exists($path)`, and it runs on submit as well as on upload — one round trip per submitted path, per save.

## Directories that must be writable

| Path | Written by | Note |
| --- | --- | --- |
| the disk roots above | uploads, exports, imports | |
| `bootstrap/cache` | `panel:cache` | The manifest is written to `panels.php.{pid}.tmp` and then moved, so the **directory** must be writable, not only the file already in it. |
| `sys_get_temp_dir()` | `ExportRun`, `ImportRun` | `tempnam()` failing throws — `RuntimeException('Cannot create a temporary file for the export.')` from `ExportRun`, and `PandaPanel\Support\Spreadsheet\SpreadsheetException('Cannot write the failure report.')`, itself a `RuntimeException`, from `ImportRun`. A read-only or full `/tmp` is the usual cause. |
| `storage/framework`, `storage/logs` | Laravel | Sessions, views, and the log the stale-manifest warning is written to. |

## Upload limits at the edge

Files go up one request at a time — the Vue field awaits each upload before starting the next, and there is no chunking. The largest single file therefore has to fit through PHP and the web server:

| Limit | Must be at least |
| --- | --- |
| `upload_max_filesize`, `post_max_size` | the largest `FileUpload::maxSize()` you declare, in KB |
| nginx `client_max_body_size` | the same |

The default for a plain field is `5120` (5 MB). `ImportAction` declares its file field with `maxSize(20480)`, so **any panel with an import needs 20 MB of headroom** even if nothing else uploads. The browser refuses an oversized file before sending it (`file.size > maxSize * 1024`), and the endpoint refuses it again with `max:` and `mimetypes:` against the real file. A file that is under the field's limit but over `post_max_size` never reaches either check: Laravel's `ValidatePostSize` middleware throws `PostTooLargeException` and the field reports only that the upload failed, which is a confusing way to learn about a PHP setting.

## More than one server

Storage is where a single-node deployment and a multi-node one stop behaving the same.

- **Queued exports write on the worker; the download is served by the web node.** `RunPanelExport` calls `ExportRun::write()` wherever it runs, and `PanelExportController` reads it wherever the request lands. A `local` disk on two machines is two different disks, and the second one 404s. Use a disk both reach.
- **Queued imports read the file the web request stored**, so the same applies — with the added constraint above that an importer's disk must be local. In practice that means running import workers on the host that received the upload, or a shared filesystem.
- **The session store is storage too.** Table state persisted with `persistSearchInSession()` and friends, and the emailed-code challenge (`panel.mfa.email.confirmed_at`), both live in the session. A `file` session driver across two nodes makes both intermittent.
- **The manifest is per-node.** `bootstrap/cache/panels.php` is a file in the release, so every node runs `panel:cache` as part of its own deploy.

## Retention

Nothing prunes what the panel writes. Exports, failure reports, and uploads whose dialog was abandoned stay until something deletes them, and there is no `panel:prune` command. What *is* deleted for you is the import upload — on success, on failure, and when a required column is missing — because it was a means, not a record.

An admin exporting a daily list writes 365 files a year per person who asks for one. [Storage and cleanup](../import-export/storage-cleanup.md) has the pruning command to copy, and the retention argument for scheduling it.

## Gotchas

- **A private local disk does not answer `null` for a preview URL.** A local disk with no `url` key falls back to `/storage/{path}` rather than throwing, so a `FileUpload` on the `local` disk serializes `previewBase: "/storage"` — which in a stock application is the *public* disk's URI, pointing at a file that is not there. The preview is a 404, not a placeholder. Put previewable uploads on `public` or on a disk with a real `url`.
- **`previewBase` is `null` only when `url()` throws `RuntimeException`**, which local, FTP and SFTP drivers never do.
- **A queued import opens the path it was handed.** `ImportAction` dispatches `RunPanelImport` with the path as it sits on the disk, and `ImportRun` opens that with `fopen()` — an inline run resolves it through `Storage::disk(...)->path()` first, and the queued one does not. The worker then looks for the file relative to its own working directory and reports `fopen(panel-imports/9f3c.csv): Failed to open stream`. `RunPanelImport::failed()` deletes the upload and sends the user an `Import failed` notification carrying that message, so it fails loudly — but keep `Importer::queueAfter()` above the row counts you actually import until you have watched a queued one succeed on a real worker.
- **`ImageColumn` has no `disk()`.** A table image column serializes whatever the attribute holds as the URL. A column showing a stored path needs an accessor that returns `Storage::disk(...)->url($path)`.
- **`route:cache` disables Laravel's served-file routes.** `FilesystemServiceProvider` skips registering them when routes are cached, so a disk relying on `'serve' => true` for its URLs works in development and 404s in production. The `public` disk is unaffected: `storage:link` makes it a real path the web server answers.
- **`optimize:clear` removes the panel manifest** along with the config and route caches. A rollback that restores code but leaves the previous release's `bootstrap/cache/panels.php` is the failure the discovery fingerprint warns about — and it warns in development only.
- **Removing a file from a form does not delete it.** The form has not been submitted and the record may still be using it. Deleting stored files is your application's decision.
- **Changing `directory()` orphans existing paths.** A stored value no longer starts with the new prefix, so `accepts()` rejects it and the next save drops it.
- **`Storage::fake()` is the whole test story.** Every path above is an ordinary disk write, so a test that fakes the disk touches nothing in `storage/`.

## See also

- [Production checklist](production-checklist.md)
- [Panel cache](panel-cache.md) and [Config cache](config-cache.md)
- [Queue workers](queues.md) and [Octane](octane.md)
- [File uploads](../forms/file-uploads.md), [File upload field](../forms/fields/file-upload.md)
- [Storage and cleanup](../import-export/storage-cleanup.md)
- [Exporters](../import-export/exporters.md), [Importers](../import-export/importers.md)
- [Persisted table state](../tables/persisted-state.md)
- [Email code challenge](../authentication/email-code-challenge.md)
- [Caching](../concepts/caching.md)
