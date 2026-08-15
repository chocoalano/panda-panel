# File Upload

`PandaPanel\Forms\Components\FileUpload` stores a file **before** the form is submitted and holds the path that came back. The field never carries file contents: the browser posts the file to the panel's upload endpoint, the endpoint stores it and answers with a path, and the form then submits that path like any other string. Reach for it whenever a record references something on a disk — an avatar, an attachment, a gallery.

## The minimal example

```php
use PandaPanel\Forms\Components\FileUpload;
use PandaPanel\Forms\FormSchema;

FormSchema::make()->schema([
    FileUpload::make('avatar')
        ->disk('public')
        ->directory('avatars')
        ->image()
        ->maxSize(1024),
]);
```

The column holds a path such as `avatars/9f2c….png`, so a plain `string` column is enough. A `multiple()` field holds a list and needs an array cast.

## Why two requests

A form submit that carried files would have to be told where to put them, and being told is exactly what must not happen. Splitting the upload out means every decision about *where* a file may land is made on the server, from the field's own declaration:

- **the disk**, so a path cannot be made to point at another one;
- **the directory**, so a submitted path outside it is refused rather than attached to the record;
- **the accepted types and size**, because "the browser only offered images" is a control, not a constraint.

All three are enforced twice — once by the upload endpoint against the real file, and again on submit against the path — because they are two requests and only the second attaches anything to a record.

## The methods

```php
public function disk(string $disk): self                  // default: 'public'
public function directory(string $directory): self        // default: 'uploads'
public function multiple(bool $multiple = true): self     // default: false
public function maxSize(int $kilobytes): self             // default: 5120, clamped to >= 1
public function maxFiles(int $max): self                  // default: null, clamped to >= 1
public function acceptedTypes(array $types): self         // default: []
public function image(bool $image = true): self           // default: false
```

| Method | Default | What it constrains |
| --- | --- | --- |
| `disk()` | `'public'` | the Laravel filesystem disk the file is stored on and read back from |
| `directory()` | `'uploads'` | where files land, and the prefix a submitted path must start with |
| `multiple()` | `false` | whether the value is a string or a list of strings |
| `maxSize()` | `5120` (kilobytes) | `max:` on the uploaded file, checked against the real file |
| `maxFiles()` | `null` | `max:` on the array, for a `multiple()` field only |
| `acceptedTypes()` | `[]` | `mimetypes:` on the uploaded file, plus the picker's `accept` filter |
| `image()` | `false` | renders previews, and fills `acceptedTypes()` when it is still empty |

```php
use PandaPanel\Forms\Components\FileUpload;

FileUpload::make('gallery')
    ->disk('s3')
    ->directory('products/gallery')
    ->multiple()
    ->maxFiles(8)
    ->maxSize(4096)
    ->acceptedTypes(['image/jpeg', 'image/png', 'image/webp'])
    ->image()
    ->columnSpanFull();
```

### `directory()` is normalized

```php
$this->directory = trim(str_replace('..', '', $directory), '/');
```

The whole guarantee rests on a prefix comparison, so a directory with a trailing slash or a `..` in it would compare against paths that do not have one. `'/avatars/'` and `'avatars'` are the same directory here.

### `image()` fills the types, once

```php
use PandaPanel\Forms\Components\FileUpload;

FileUpload::make('avatar')->image()->getAcceptedTypes();
// ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif']

FileUpload::make('avatar')->acceptedTypes(['image/png'])->image()->getAcceptedTypes();
// ['image/png'] — image() only fills an empty list
```

Order matters in the other direction: `->image()->acceptedTypes([...])` replaces the defaults outright.

## Reading the declaration

Five getters, public because the upload endpoint reads them off the field it resolved out of the schema:

```php
public function getDisk(): string
public function getDirectory(): string
public function getMaxSize(): int
public function isMultiple(): bool
public function getAcceptedTypes(): array
```

```php
use PandaPanel\Forms\Components\FileUpload;

$field = FileUpload::make('avatar')->disk('local')->directory('avatars');

$field->getDisk();          // 'local'
$field->getDirectory();     // 'avatars'
$field->getMaxSize();       // 5120
```

## `accepts()` — the check on the way in

```php
public function accepts(string $path): bool
```

Whether a submitted path is one this field could have produced. Three conditions, all of them:

1. the path is not empty and contains no `..`,
2. it starts with `directory/`,
3. the file exists on the declared disk.

```php
use Illuminate\Support\Facades\Storage;
use PandaPanel\Forms\Components\FileUpload;

Storage::fake('local');
Storage::disk('local')->put('avatars/one.png', 'x');
Storage::disk('local')->put('elsewhere/two.png', 'x');

$field = FileUpload::make('avatar')->disk('local')->directory('avatars');

$field->accepts('avatars/one.png');                   // true
$field->accepts('elsewhere/two.png');                 // false — outside the directory
$field->accepts('avatars/missing.png');               // false — not there
$field->accepts('avatars/../elsewhere/two.png');      // false — climbing out
```

`mutate()` applies it on the way to the record, and drops what fails **silently** — a path that fails this check is not a value the user typed, it is one that arrived from somewhere else:

```php
$field->mutate('avatars/never-uploaded.png', null);   // null

FileUpload::make('gallery')->disk('local')->directory('avatars')->multiple()
    ->mutate(['avatars/one.png', 'avatars/fake.png'], null);
// ['avatars/one.png']
```

## Validation

```php
use PandaPanel\Forms\Components\FileUpload;
use PandaPanel\Forms\FormSchema;

FormSchema::make()
    ->schema([
        FileUpload::make('avatar'),
        FileUpload::make('gallery')->multiple()->maxFiles(5),
    ])
    ->validationRules();

// [
//     'avatar'    => ['nullable', 'string'],
//     'gallery'   => ['nullable', 'array', 'max:5'],
//     'gallery.*' => ['string'],
// ]
```

The rules describe **paths**, because that is what the form submits. Size and MIME type are not here: they are checked by the upload endpoint against the real file, and re-checked as `accepts()` when the path is dehydrated.

## The upload endpoint

The URL is built by the server and travels with the form. Nothing in Vue constructs a panel URL.

| Builder | Used by |
| --- | --- |
| `FormEndpoints::upload(string $resource, string $page, ?Model $record = null)` | `CreateRecord`, `EditRecord` |
| `FormEndpoints::uploadForRelation(string $resource, string $manager, Model $owner, string $operation, Model\|int\|string\|null $related = null)` | relation forms |
| `FormEndpoints::uploadForAction(string $resource, string $action, string $scope, Model\|int\|string\|null $record = null)` | action modals |

All three point at the panel route named `uploads` (`$panel->routeName('uploads')`), registered as `POST {panel prefix}/uploads`.

The request body carries exactly two things — `field` and `file`. Everything that says *what this form is* travels in the query string, because the body is the form's values and a field that happens to be named `resource` must not be able to point the upload at a different one.

### Which permission an upload needs

The one that would be needed to submit the form the field belongs to, and nothing weaker:

| Context in the URL | Schema built | Ability asked |
| --- | --- | --- |
| `page=create` | the resource's create form | `canCreate()` |
| `page=edit` + `record` | the resource's edit form | `canEdit($record)` |
| `relation` + `operation` | the relation form | `canView($owner)`, `canViewAny($owner)`, and the operation's own |
| `action` + `scope` | the action's form | the action's `isAuthorizedFor($record)` |

Reading the resource is never enough. An upload writes a file to a disk, and the ability to look at a list is not the ability to put something on it.

### What the endpoint does

1. validates `field` and `file` are present,
2. resolves the resource from the query string and authorizes the context above,
3. resolves the named field out of that schema — an unknown name is a 404, a field that is not a `FileUpload` is a 400,
4. validates the real file against `max:{maxSize}` and, when types were declared, `mimetypes:{types}`,
5. stores it with `$file->store($directory, $disk)`,
6. answers `{"path": "...", "name": "..."}`.

`mimetypes:` reads the file, not its extension, so renaming a `.php` to `.png` does not get past it.

## What crosses the wire

```ts
interface FileUploadFieldDefinition extends BaseFieldDefinition {
    type: 'file_upload';
    multiple: boolean;
    /** Kilobytes, matching Laravel's `max:` rule. */
    maxSize: number;
    maxFiles: number | null;
    acceptedTypes: string[];
    image: boolean;
    /** Null when the disk serves no public URL; the name is shown instead. */
    previewBase: string | null;
}
```

`previewBase` is `Storage::disk($disk)->url('/')` with the trailing slash trimmed, resolved on the server so the browser never builds a URL from a disk name. A disk with no public URL — a private one, or a driver that does not serve files — answers `null` rather than a link that 404s, and the field then shows the file name instead of a broken image.

## Gotchas

**Removing a file from a form does not delete it.** The form has not been submitted and the record may still be using the file. Deleting orphans is the application's job — a model observer on `deleting`, or a scheduled sweep of the directory.

**`maxSize()` is in kilobytes.** It is passed straight to Laravel's `max:` rule for files, which measures kilobytes. `maxSize(1024)` is one megabyte.

**A rejected path disappears without a message.** `mutate()` drops what `accepts()` refuses rather than failing validation, because the value was never something a user chose. If a legitimate path is vanishing, check the disk and the directory prefix first.

**Changing `directory()` orphans existing values.** `accepts()` compares against the *current* declaration, so every previously stored path now fails the prefix check and is dropped on the next save. Move the files, or keep the old directory.

**Do not set an empty directory.** The prefix test is `str_starts_with($path, $directory.'/')`; with an empty directory that becomes `'/'`, which no stored path starts with, so every value is rejected.

**A file field needs a form context.** The upload URL is provided by the resource create/edit pages, relation forms, and action modals. A file field in a form built outside those has no URL to post to, and `FileUploadField.vue` says so rather than failing on drop.

**`multiple()` changes the value's type.** Turning it on after records exist leaves strings in a column the field now reads as an array; `castForForm()` answers `[]` for them.

**The `image` flag is presentation plus a default.** It renders previews and pre-fills `acceptedTypes()`. It does not verify the file is an image on its own — the `mimetypes:` rule from those types does.

## See also

- [File Uploads](../file-uploads.md) — the endpoint in the wider request lifecycle
- [Builder](builder.md) — a media block usually holds one of these
- [Repeater](repeater.md)
- [Action Forms](../../actions/forms.md) — uploading from a modal
- [Relation Forms](../../relations/relation-forms.md)
- [Authorization](../../concepts/authorization.md)
- [Forms and Schemas](../overview.md)
