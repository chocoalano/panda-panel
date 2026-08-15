# File Uploads

`PandaPanel\Forms\Components\FileUpload` is a stored file referenced by its path. The field never carries file contents: the browser posts the file to the panel's upload endpoint, which stores it and answers with a path, and the form then submits that path like any other string. You reach for it whenever a record needs an avatar, an attachment, or a gallery.

## A minimal example

```php
use PandaPanel\Forms\Components\FileUpload;
use PandaPanel\Forms\FormSchema;

public static function form(FormSchema $schema): FormSchema
{
    return $schema->schema([
        FileUpload::make('avatar')
            ->disk('public')
            ->directory('avatars')
            ->image()
            ->maxSize(1024),
    ]);
}
```

The column holds `avatars/abc123.png`. Rendering it is your application's job — the field only stores and returns the path.

## Method reference

| Method | Signature | Default |
| --- | --- | --- |
| `disk()` | `disk(string $disk): self` | `'public'` |
| `directory()` | `directory(string $directory): self` | `'uploads'` |
| `multiple()` | `multiple(bool $multiple = true): self` | `false` |
| `maxSize()` | `maxSize(int $kilobytes): self` | `5120` (5 MB), floored at 1 |
| `maxFiles()` | `maxFiles(int $max): self` | `null`, floored at 1 |
| `acceptedTypes()` | `acceptedTypes(list<string> $types): self` | `[]` — MIME types |
| `image()` | `image(bool $image = true): self` | `false` |
| `getDisk()` | `getDisk(): string` | |
| `getDirectory()` | `getDirectory(): string` | |
| `getMaxSize()` | `getMaxSize(): int` | |
| `isMultiple()` | `isMultiple(): bool` | |
| `getAcceptedTypes()` | `getAcceptedTypes(): list<string>` | |
| `accepts()` | `accepts(string $path): bool` | |

```php
use PandaPanel\Forms\Components\FileUpload;

FileUpload::make('gallery')
    ->disk('s3')
    ->directory('posts/gallery')
    ->multiple()
    ->maxFiles(8)
    ->maxSize(2048)
    ->acceptedTypes(['image/png', 'image/jpeg'])
    ->image();
```

`image()` renders a preview and, when no types were declared yet, fills `acceptedTypes()` with `image/jpeg`, `image/png`, `image/gif`, `image/webp`, and `image/avif`. Declaring your own types first keeps them.

`directory()` is normalized — `..` removed, slashes trimmed — because the whole guarantee rests on a prefix comparison, and a directory with a trailing slash would compare against paths that do not have one.

## Three things declared once and enforced twice

- **The disk**, so a path cannot be made to point at another one.
- **The directory**, so a submitted path outside it is refused rather than attached to the record.
- **The accepted types and size**, because "the browser only offered images" is a control, not a constraint.

The upload endpoint applies all three when the file arrives, and the form submit applies the first two again — they are two requests, and only the second attaches a path to a record.

```php
use Illuminate\Support\Facades\Storage;
use PandaPanel\Forms\Components\FileUpload;

Storage::fake('local');
Storage::disk('local')->put('avatars/one.png', 'x');
Storage::disk('local')->put('elsewhere/two.png', 'x');

$field = FileUpload::make('avatar')->disk('local')->directory('avatars');

$field->accepts('avatars/one.png');                 // true
$field->accepts('elsewhere/two.png');               // false — outside the directory
$field->accepts('avatars/missing.png');             // false — not there
$field->accepts('avatars/../elsewhere/two.png');    // false — climbing out
```

`FileUpload::mutate()` drops any path the field could not have produced, silently, because a path that fails the check is not a value the user typed:

```php
$field->mutate('avatars/never-uploaded.png', null);       // null
$field->mutate(['avatars/one.png', 'avatars/fake.png'], null);
// ['avatars/one.png']
```

## Validation

| Case | Rules |
| --- | --- |
| Single | `string` |
| Multiple | `array`, plus `max:{maxFiles}` when set, and `string` under `field.*` |

Plus `required` or `nullable` like every field. The size and MIME checks are not here: they belong to the upload request, where the real file exists.

## The endpoint

Route name `panel.{panel_id}.uploads`, handled by `PandaPanel\Http\Controllers\PanelUploadController`. It is a `POST` carrying exactly two body fields:

| Body field | Meaning |
| --- | --- |
| `field` | The field name. 404 if the schema has no such field, 400 if it is not a `FileUpload` |
| `file` | The file itself |

Everything else — which resource, which page, which record, which relation, which action — comes from the query string, built by the server. The request names a resource and a field, never a disk or a directory.

The answer is JSON:

```json
{ "path": "avatars/9f3c.png", "name": "portrait.png" }
```

The file is stored with `$file->store($field->getDirectory(), $field->getDisk())`, so the stored name is Laravel's hash and the original name comes back only for display.

### Which permission an upload needs

The one that would be needed to submit the form the field belongs to, and nothing weaker.

| Context in the URL | Schema built | Ability asked |
| --- | --- | --- |
| `page=create` | the resource's create form | `canCreate()` |
| `page=edit` + `record` | the resource's edit form | `canEdit($record)` |
| `relation` + `operation` (+ `related`) | the relation form | `canView($owner)`, the manager's `canViewAny($owner)`, and the operation's own |
| `action` + `scope` (+ `record`) | the action's form | the action's `isAuthorizedFor($record)` |

Reading the resource is never enough: an upload writes a file to a disk under a directory this application chose, and the ability to look at a list is not the ability to put something there. A `page` value that is neither `create` nor `edit` is 422 rather than defaulting to the form that needs no record.

For a nested resource the parent key travels as `parent` and is bound before the query runs, so the scope the page had is the scope the upload gets.

### Building the URL

`PandaPanel\Support\FormEndpoints` builds every one of them, so no panel URL is ever constructed in Vue:

```php
use PandaPanel\Support\FormEndpoints;

FormEndpoints::upload(PostResource::class, 'create');
FormEndpoints::upload(PostResource::class, 'edit', $post);
FormEndpoints::uploadForRelation(PostResource::class, CommentsRelationManager::class, $post, 'create');
FormEndpoints::uploadForAction(PostResource::class, 'import', 'table');
```

The resource pages send the first two to Inertia as `uploadUrl`; relation and action dialogs send their own.

## Previews

The serialized field carries `multiple`, `maxSize`, `maxFiles`, `acceptedTypes`, `image`, and `previewBase`. `previewBase` is `Storage::disk($disk)->url('/')` with the trailing slash trimmed, resolved on the server so the browser never builds a URL from a disk name. A disk with no public URL — a private one, or a driver that does not serve files — answers `null`, and the frontend shows the file name instead of a broken image.

## Storing several files

```php
use PandaPanel\Forms\Components\FileUpload;

FileUpload::make('gallery')->multiple()->maxFiles(5);
```

The value is a list of paths, so the model needs somewhere to put a list:

```php
protected function casts(): array
{
    return ['gallery' => 'array'];
}
```

Each file is uploaded in its own request; the field collects the paths and submits them together.

## Notes

- **Removing a file from a form does not delete it.** The form has not been submitted and the record may still be using it. Deleting stored files is your application's decision — a model event, or an [action](../actions/overview.md).
- **`maxSize()` is kilobytes**, matching Laravel's `max:` rule for files.
- **The MIME check reads the file, not the name.** A PDF renamed to `.png` is refused by `mimetypes:`.
- **A failed upload is an ordinary validation failure.** An XHR that accepts JSON gets 422; a plain form post gets a redirect. Either way nothing is stored.
- **`acceptedTypes()` is also what the file picker offers**, but the endpoint is what decides. The browser's `accept` attribute is a convenience.
- **A submitted path is re-checked on save.** It must exist, live under the declared directory, and not climb out of it — so a path from another field, another form, or another disk cannot be attached to a record.
- **`accepts()` hits the disk.** It calls `Storage::disk($disk)->exists($path)`, which is a remote call on a remote driver.
- **Changing `directory()` orphans existing paths.** Old values no longer start with the new prefix, so they are dropped on the next save.

## See also

- [File upload field](fields/file-upload.md)
- [Validation](validation.md)
- [Hydration and dehydration](hydration.md)
- [Options endpoints](options-endpoints.md)
- [FormSchema basics](overview.md)
- [Action forms](../actions/forms.md)
- [Relation forms](../relations/relation-forms.md)
- [Negative security tests](../testing/negative-security-tests.md)
