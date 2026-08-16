# Upload Failures

A file field stores its file in its own request, before the form is submitted, and the browser only ever learns whether that request succeeded. When it fails, the field says `X could not be uploaded.` and nothing else — the status code, and the reason behind it, are in the network tab. This page maps every answer `PandaPanel\Http\Controllers\PanelUploadController` can give back to the declaration or the permission that produced it. Reach for it when an upload is refused, when a file uploads and then vanishes on save, or when a preview renders as a broken image.

## Reproducing it outside the browser

The fastest diagnosis is a test that posts to the same endpoint the field posts to, because it shows the status and the message the field swallows:

```php
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('stores a file for the avatar field', function (): void {
    Storage::fake('public');

    $response = $this->actingAs(User::factory()->admin()->create())->postJson(
        route('panel.admin.uploads', ['resource' => 'users', 'page' => 'create'], absolute: false),
        [
            'field' => 'avatar',
            'file' => UploadedFile::fake()->image('me.png'),
        ],
    );

    expect($response->status())->toBe(200)
        ->and(Storage::disk('public')->exists((string) $response->json('path')))->toBeTrue();
});
```

Use `postJson`, not `post`. A validation failure on a plain POST is a `302` back to the previous page; on a JSON request it is a `422` carrying the message, which is what the field's `fetch` actually receives.

## The request the field makes

One `POST` to `{panel-path}/uploads`, route name `panel.{panel_id}.uploads`, registered in `PandaPanel\Routing\PanelRouteRegistrar` inside the panel's own middleware.

| Where | Key | Meaning |
| --- | --- | --- |
| Body | `field` | The field name. Required, string. |
| Body | `file` | The file. Required, a real upload. |
| Query | `resource` | The resource slug **in this panel**. Required. |
| Query | `page` | `create` or `edit`, for a resource form. |
| Query | `record` | The record key — required for `page=edit` and for a relation form, optional for an action. |
| Query | `relation`, `operation`, `related` | A relation manager's form. |
| Query | `action`, `scope` | An action's form. `scope` is one of `record`, `table`, `bulk`, `infolist`. |
| Query | `parent` | The parent record key, for a nested resource. |

Everything that says *what this form is* lives in the query string and is read from there only. A field named `resource` in the form body cannot point the upload somewhere else — the test `reads the resource from the URL and not from the form body` in `tests/Feature/Panel/FormEndpointTest.php` asserts exactly that, and the answer is `422` rather than a redirected upload.

A success is:

```json
{ "path": "avatars/9f3c.png", "name": "portrait.png" }
```

`path` is what the form submits. `name` is the original client name, kept for display only.

## Every answer the endpoint can give

Checks run in this order: the current panel, then `field` and `file`, then the resource, then the parent binding, then authorization and the schema, then the field, then the file's own limits, then the write. A missing `field` is therefore a `422` even when the `resource` is also wrong.

| Status | Message | What produced it |
| --- | --- | --- |
| `422` | validation errors | `field` or `file` missing, or `file` is not an upload |
| `422` | `Invalid resource.` | no `resource` in the query string |
| `404` | `Unknown resource.` | that slug is not registered in this panel |
| `422` | `Invalid parent key.` | a nested resource with no `parent` |
| `404` | — | the parent record does not resolve |
| `422` | `Invalid page.` | `page` is neither `create` nor `edit` |
| `403` | — | `Resource::canCreate()` said no |
| `422` | `Invalid record key.` | `page=edit` with no `record` |
| `404` | — | the record is not in `Resource::query()` |
| `403` | — | `Resource::canEdit($record)` said no |
| `404` | `Unknown relation.` | no relation manager under that key |
| `404` | `Unknown relation operation.` | `operation` is not a `RelationOperation` |
| `403` | — | `canView($owner)`, the manager's `canViewAny($owner)`, or the operation's own ability said no |
| `422` | `Invalid scope.` | `scope` is not one of the four |
| `404` | `Unknown action.` | that name is not in the set `scope` names |
| `403` | — | `Action::isAuthorizedFor($record)` said no |
| `400` | `This action has no form.` | the action declares no schema |
| `404` | `Unknown field.` | the schema has no field by that name |
| `400` | `That field does not accept files.` | the field is not a `FileUpload` |
| `422` | validation errors on `file` | the file failed `max:` or `mimetypes:` |
| `422` | `No file was uploaded.` | the request carried no `UploadedFile` |
| `500` | `The file could not be stored.` | `$file->store()` returned false |
| `413` | — | Laravel's `ValidatePostSize`, before any of this |
| `419` | — | the session expired, so the CSRF token no longer matches |

## The three messages the field itself shows

`resources/js/panel/forms/fields/FileUploadField.vue` produces all of them, and only one of them involves the server.

| Message | Cause |
| --- | --- |
| `This form cannot store files.` | The form was rendered without an upload URL, so there is nowhere to post. |
| `{name} is larger than {n} KB.` | The browser check, `file.size > field.maxSize * 1024`, before anything is sent. |
| `{name} could not be uploaded.` | The POST answered anything other than `200`. |

`postForm()` in `resources/js/panel/forms/http.ts` answers `null` for every non-OK response and for every thrown request, deliberately: a failed side request must leave a half-filled form as it was rather than break the page. The cost is that the third message covers all twenty-odd rows of the table above, so read the status in the network tab before reading anything else.

### `This form cannot store files.`

`FormRenderer` provides the URL and a field injects it:

```ts
provideUploadUrl(() => props.uploadUrl ?? null);
```

Four places pass one, and they are the only four:

| Form | Prop set by |
| --- | --- |
| Create page | `PandaPanel\Resources\Pages\CreateRecord::render()` |
| Edit page | `PandaPanel\Resources\Pages\EditRecord::render()` |
| Relation dialog | `PandaPanel\Http\Controllers\PanelRelationController` |
| Action modal | `PandaPanel\Http\Controllers\PanelActionFormController` |

A custom Vue page that renders `FormRenderer` without `:upload-url` gets `null`, and a `FileUpload` on it reports that message on the first drop rather than failing after the file has been sent. **There is no page-level upload endpoint.** `PandaPanel\Support\FormEndpoints` builds every upload URL from a resource class, so a form that does not belong to a resource, a relation, or an action has nowhere to put a file:

```php
use PandaPanel\Support\FormEndpoints;

FormEndpoints::upload(PostResource::class, 'create');                    // string
FormEndpoints::upload(PostResource::class, 'edit', $post);
FormEndpoints::uploadForRelation(PostResource::class, CommentsRelationManager::class, $post, 'create');
FormEndpoints::uploadForRelation(PostResource::class, CommentsRelationManager::class, $post, 'edit', $comment);
FormEndpoints::uploadForAction(PostResource::class, 'import', 'table');
FormEndpoints::uploadForAction(PostResource::class, 'attach-file', 'record', $post);
```

| Method | Signature |
| --- | --- |
| `upload` | `static upload(string $resource, string $page, ?Model $record = null): string` |
| `uploadForRelation` | `static uploadForRelation(string $resource, string $manager, Model $owner, string $operation, Model\|int\|string\|null $related = null): string` |
| `uploadForAction` | `static uploadForAction(string $resource, string $action, string $scope, Model\|int\|string\|null $record = null): string` |

All three return a relative URL and throw `PandaPanel\Exceptions\PanelRegistrationException::noCurrentPanel()` outside a panel request. Pass the result to `FormRenderer` as `uploadUrl` if you are hosting a resource form on a page of your own.

## A 403 on an upload

An upload is a write. The endpoint asks the ability that would be needed to *submit the form the field belongs to*, and nothing weaker — reading the resource is never one of the answers.

| Context in the URL | Schema built | Ability asked |
| --- | --- | --- |
| `page=create` | the resource's create form | `Resource::canCreate()` |
| `page=edit` + `record` | the resource's edit form | `Resource::canEdit($record)` |
| `relation` + `operation` | the relation form | `canView($owner)`, `Manager::canViewAny($owner)`, and `RelationOperation::isAuthorized()` |
| `action` + `scope` | the action's form | `Action::isAuthorizedFor($record)` |

Two consequences worth knowing before blaming the endpoint:

- **A user who can list a resource but not create in it gets a 403 on the create form's upload.** That is a fix rather than a regression: the endpoint used to accept `canCreate() || canViewAny()`, so a read-only role could write files to a disk. See the [changelog](../upgrading/changelog.md).
- **An edit form's upload asks about one record.** It never borrows `create`, so a policy that allows creation and refuses updates refuses the upload on the edit page.

If the policy method you expect is not being reached at all, the usual cause is that there is no policy. `Gate::allows()` answers false when none is registered, which is correct and indistinguishable from a policy that considered the question. Turn the ambiguity into an exception while you look:

```php
use PandaPanel\Core\Panel;

Panel::make('admin')->strictAuthorization();
```

`PandaPanel\Support\PolicyGate` then throws `PandaPanel\Exceptions\PanelAuthorizationException` for a model with no policy, and for a policy missing the method — including the relation abilities that have no `can*` method on a resource. A policy with a `before()` method is exempt, because `before` may legitimately answer for everything.

For a nested resource, add the parent. `bindParentRecord()` binds it before anything queries, so the upload sees the scope the page saw; without `parent` in the query string the answer is `422`, and with an unresolvable one it is `404`.

## A 422 that names the file

Two rules are built from the field's own declaration and applied to the file that actually arrived:

```php
$rules = ['file' => ['file', 'max:'.$field->getMaxSize()]];

if ($field->getAcceptedTypes() !== []) {
    $rules['file'][] = 'mimetypes:'.implode(',', $field->getAcceptedTypes());
}
```

`mimetypes:` reads the bytes, not the name. A PHP script renamed `payload.png` is refused — `tests/Feature/Panel/Negative/MalformedInputTest.php` asserts that with a real file rather than a fake one, because `UploadedFile::fake()` reports its type from the file name and would have made the test vacuous.

The `accept` attribute on the input comes from the same list, so the picker and the endpoint agree, but the attribute is a convenience and the endpoint is the control. Every knob:

| Method | Signature | Default |
| --- | --- | --- |
| `disk` | `disk(string $disk): self` | `'public'` |
| `directory` | `directory(string $directory): self` | `'uploads'` — `..` stripped, slashes trimmed |
| `multiple` | `multiple(bool $multiple = true): self` | `false` |
| `maxSize` | `maxSize(int $kilobytes): self` | `5120`, floored at 1 |
| `maxFiles` | `maxFiles(int $max): self` | `null`, floored at 1 |
| `acceptedTypes` | `acceptedTypes(array $types): self` | `[]` |
| `image` | `image(bool $image = true): self` | `false`; fills `acceptedTypes` with `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `image/avif` when none were declared |
| `getDisk` | `getDisk(): string` | |
| `getDirectory` | `getDirectory(): string` | |
| `getMaxSize` | `getMaxSize(): int` | |
| `isMultiple` | `isMultiple(): bool` | |
| `getAcceptedTypes` | `getAcceptedTypes(): list<string>` | |
| `accepts` | `accepts(string $path): bool` | |
| `mutate` | `mutate(mixed $value, ?Model $record): mixed` | |
| `elementRules` | `elementRules(): list<mixed>` | `['string']` when multiple, `[]` otherwise |

```php
use PandaPanel\Forms\Components\FileUpload;

FileUpload::make('attachment')
    ->disk('public')
    ->directory('attachments')
    ->acceptedTypes(['image/png', 'image/jpeg'])
    ->maxSize(2048)
    ->required();
```

Read the declaration back when a refusal does not make sense — the field is the authority, not the picker. `FormSchema::field(string $name): ?Field` answers `null` for a name the schema does not declare, which is the same `404` the endpoint gives:

```php
use PandaPanel\Forms\Components\FileUpload;
use PandaPanel\Forms\FormSchema;

$field = PostResource::form(FormSchema::make())->field('attachment');

if ($field instanceof FileUpload) {
    $field->getDisk();            // 'public'
    $field->getDirectory();       // 'attachments'
    $field->getMaxSize();         // 2048  (kilobytes)
    $field->getAcceptedTypes();   // ['image/png', 'image/jpeg']
    $field->isMultiple();         // false
}
```

## The upload succeeded and the file is gone after saving

That is `FileUpload::accepts()` refusing the path on submit, and `mutate()` dropping it silently. Both are deliberate: the upload and the submit are two requests, and only the second attaches a path to a record.

```php
use Illuminate\Support\Facades\Storage;
use PandaPanel\Forms\Components\FileUpload;

Storage::fake('local');
Storage::disk('local')->put('avatars/one.png', 'x');

$field = FileUpload::make('avatar')->disk('local')->directory('avatars');

$field->accepts('avatars/one.png');                 // true
$field->accepts('elsewhere/two.png');               // false — outside the directory
$field->accepts('avatars/missing.png');             // false — not on the disk
$field->accepts('avatars/../elsewhere/two.png');    // false — climbs out

$field->mutate('avatars/never-uploaded.png', null); // null
```

Four things make a path fail that check:

- **`directory()` changed since the file was stored.** Old values no longer start with the new prefix, so the next save of an untouched record drops them.
- **`disk()` changed.** `accepts()` asks the declared disk whether the path exists, and the file is on the other one.
- **The file was deleted out from under the record.** Removing a file from the *form* never deletes it — the form has not been submitted and the record may still be using it — so a deletion here came from your application.
- **The value did not come from this field.** A path from another field, another form, or a request somebody wrote by hand is exactly what the check exists to drop.

For `multiple()`, the value is a list of paths and the model needs somewhere to put a list:

```php
protected function casts(): array
{
    return ['gallery' => 'array'];
}
```

Without the cast the attribute is a string, and every path after the first is lost before `accepts()` is ever asked.

## The file uploaded but the preview is broken

`previewBase` is resolved on the server, once, so the browser never builds a URL from a disk name:

```php
rtrim(Storage::disk($this->disk)->url('/'), '/');
```

| Symptom | Cause |
| --- | --- |
| The file name renders as plain text, no link | `url()` threw `RuntimeException`, so `previewBase` is `null` — the honest answer for a driver that serves nothing |
| A link or `<img>` that 404s | `previewBase` resolved, but nothing serves that path |

The second is the common one, and it has two usual causes. On the `public` disk, `php artisan storage:link` has not run in this release — on a release-directory deploy the link lives inside the release and has to be recreated every time. On a `local` disk, there is no exception to catch: a local disk with no `url` key falls back to `/storage/{path}`, which in a stock application is the *public* disk's URI, so a `local` upload serializes a `previewBase` pointing at a file that is not there. Put previewable uploads on `public`, or on a disk with a real `url`.

On a remote disk, check visibility. The endpoint stores with `$file->store($directory, $disk)` and never sets one, so objects take the disk's default — a private bucket with a public `previewBase` gives previews that 403.

## A file that never reaches the endpoint

Uploads go one file at a time and there is no chunking, so the largest single file has to fit through PHP and the web server first:

| Limit | Must be at least |
| --- | --- |
| `upload_max_filesize`, `post_max_size` | the largest `maxSize()` you declare, in KB |
| nginx `client_max_body_size` | the same |

`PandaPanel\Actions\ImportAction` declares its file field with `maxSize(20480)`, so **any panel with an import needs 20 MB of headroom** even when nothing else uploads. A file under the field's limit but over `post_max_size` is rejected by Laravel's `ValidatePostSize` before the controller runs: the response is a `413`, and the field reports only that the upload failed.

## Import uploads

An import is an ordinary `FileUpload` bound to the importer's own storage, declared inside `ImportAction::form()`:

```php
FileUpload::make('file')
    ->label('File')
    ->disk($importer::disk())
    ->directory($importer::directory())
    ->acceptedTypes([
        'text/csv', 'text/plain', 'application/csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip',
    ])
    ->maxSize(20480)
    ->required();
```

Three failures are specific to it:

- **`404` `That file is no longer there.`** on submit. The upload succeeded, then something removed it — including a previous submit of the same dialog, which deletes the file after a successful run.
- **A validation error on `file` listing missing columns.** The importer needs a column the file has no heading for. It is raised before a single row is read, and the upload is deleted, so re-upload the corrected file rather than resubmitting.
- **`fopen(...): Failed to open stream` from a worker.** An importer's disk must be local: `ImportAction` reads `Storage::disk($importer::disk())->path($stored)` and hands the result to `fopen()` or `ZipArchive`. A driver whose `path()` is not a readable filesystem path cannot be read at all.

## Gotchas

- **A single-file field disables its input once it holds a file.** `atLimit` is true whenever the field is not `multiple()`, so the picker is disabled until Remove is pressed. The same applies to a `multiple()` field at `maxFiles()`.
- **The client size check runs first and per file.** An oversized file in a multi-select is reported and skipped; the others still upload.
- **The input is cleared on every change**, so picking the same file twice in a row still fires.
- **`accepts()` hits the disk on every submitted path.** On a remote disk that is one round trip per path, per save.
- **`419` means the session, not the file.** The token comes from the `csrf-token` meta tag, falling back to the `XSRF-TOKEN` cookie; a page left open past the session lifetime has neither valid.
- **A `500` from `noCurrentPanel()` means the route was reached outside a panel.** The panel's own routes always resolve one; a route registered by hand does not.
- **`page` is an allowlist.** An unrecognised value is `422`, never a fallback to `create` — that fallback was the branch that needs no record, which is the check being dodged.
- **`Storage::fake()` covers all of it.** Every path here is an ordinary disk write, so a test that fakes the disk touches nothing under `storage/`.

## See also

- [File uploads](../forms/file-uploads.md) and the [file upload field](../forms/fields/file-upload.md)
- [Authorization 403s](authorization-403.md), [Authorization](../concepts/authorization.md)
- [Storage setup](../deployment/storage.md)
- [Import action](../import-export/import-action.md), [Import and export troubleshooting](import-export.md)
- [Options endpoints](../forms/options-endpoints.md), [Live fields](../forms/live-fields.md)
- [Action forms](../actions/forms.md), [Relation forms](../relations/relation-forms.md)
- [Negative security tests](../testing/negative-security-tests.md)
