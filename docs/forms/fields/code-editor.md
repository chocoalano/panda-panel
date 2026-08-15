# Code Editor

`PandaPanel\Forms\Components\CodeEditor` stores source text and edits it in a monospaced control that keeps a tab a tab. Reach for it when a column holds something a person writes as code — a JSON configuration blob, a snippet of CSS, a SQL fragment — rather than prose, which belongs in a [Markdown editor](markdown.md) or a rich editor.

## The minimal example

```php
use PandaPanel\Forms\Components\CodeEditor;
use PandaPanel\Forms\Enums\CodeLanguage;
use PandaPanel\Forms\FormSchema;

FormSchema::make()->schema([
    CodeEditor::make('settings')
        ->language(CodeLanguage::Json)
        ->rows(16)
        ->columnSpanFull(),
]);
```

## The methods

```php
public function language(CodeLanguage $language): self   // default: CodeLanguage::Plain
public function rows(int $rows): self                    // default: 12, clamped to >= 1
public function maxLength(int $length): self             // default: null, clamped to >= 1
```

| Method | Default | Effect on rules | Effect on the control |
| --- | --- | --- | --- |
| `language()` | `CodeLanguage::Plain` | adds `json` for `Json` only | names the language in the header strip |
| `rows()` | `12` | none | the textarea's `rows` |
| `maxLength()` | `null` | adds `max:n` | the textarea's `maxlength` |

```php
use PandaPanel\Forms\Components\CodeEditor;
use PandaPanel\Forms\Enums\CodeLanguage;

CodeEditor::make('stylesheet')
    ->language(CodeLanguage::Css)
    ->rows(24)
    ->maxLength(20_000)
    ->helperText('Injected into the storefront head, unchanged.');
```

## The languages

`PandaPanel\Forms\Enums\CodeLanguage` is a closed set, because each case maps to something the build already knows about. A free string would be a request for a grammar that is not in the bundle, which fails silently as unformatted text.

| Case | Wire value | Header label |
| --- | --- | --- |
| `CodeLanguage::Plain` | `plain` | Plain text |
| `CodeLanguage::Json` | `json` | JSON |
| `CodeLanguage::Html` | `html` | HTML |
| `CodeLanguage::Css` | `css` | CSS |
| `CodeLanguage::JavaScript` | `javascript` | JavaScript |
| `CodeLanguage::Php` | `php` | PHP |
| `CodeLanguage::Sql` | `sql` | SQL |
| `CodeLanguage::Yaml` | `yaml` | YAML |
| `CodeLanguage::Markdown` | `markdown` | Markdown |

Only `Json` changes behaviour beyond the label: it adds Laravel's `json` rule, so a document that will not parse is rejected before it reaches a column.

```php
use PandaPanel\Forms\Components\CodeEditor;
use PandaPanel\Forms\Enums\CodeLanguage;
use PandaPanel\Forms\FormSchema;

FormSchema::make()
    ->schema([CodeEditor::make('settings')->language(CodeLanguage::Json)->maxLength(5000)])
    ->validationRules();

// ['settings' => ['nullable', 'string', 'json', 'max:5000']]
```

## What the value is

The field holds a **string**, on the way in and on the way out.

`castForForm()` makes one accommodation: an array — which is what an `array`- or `json`-cast attribute returns — is encoded for display with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`. Anything that is neither an array nor a string becomes `null`.

```php
use PandaPanel\Forms\Components\CodeEditor;
use PandaPanel\Forms\Enums\CodeLanguage;

// $record->settings is cast to 'array' and holds ['theme' => 'dark']
CodeEditor::make('settings')->language(CodeLanguage::Json)->formValue($record);

// "{\n    \"theme\": \"dark\"\n}"
```

There is no matching decode on the way out. A JSON editor over an array-cast column therefore needs one:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Forms\Components\CodeEditor;
use PandaPanel\Forms\Enums\CodeLanguage;

CodeEditor::make('settings')
    ->language(CodeLanguage::Json)
    ->mutateUsing(static fn (mixed $value, ?Model $record): array => is_string($value)
        ? (array) json_decode($value, associative: true)
        : []);
```

The `json` rule has already run by then, so the decode cannot be handed something unparseable.

## What the control does

`resources/js/panel/forms/fields/CodeEditorField.vue` is a textarea, deliberately, with the things that matter for editing code:

- a fixed-width face and a header strip showing the language and the live line count,
- `spellcheck`, `autocorrect`, `autocapitalize`, and `autocomplete` all off, so identifiers are not rewritten under the cursor,
- **Tab inserts four spaces** rather than moving focus. Escape and then Tab is the way out, which is the convention every code editor on the web already uses.

There is no syntax highlighting. Adding one would mean adding a highlighter dependency to every panel bundle, which is a decision the package does not make on an application's behalf. If you need it, a [custom field](../custom-fields.md) renders whatever editor you choose against the same value.

## What crosses the wire

```ts
interface CodeEditorFieldDefinition extends BaseFieldDefinition {
    type: 'code_editor';
    language: CodeLanguage;
    rows: number;
    maxLength: number | null;
}
```

## Gotchas

**`maxLength()` counts characters, not lines.** It becomes `max:n` on a string, which Laravel measures in characters, and the browser enforces the same number as `maxlength`. A long JSON document hits it faster than it looks.

**`language(CodeLanguage::Json)` does not make the value an array.** The rule proves it parses; the stored value is still the text the user typed, including their whitespace. Decode in `mutateUsing()` if the column expects structure.

**The `json` rule rejects an empty editor.** A cleared textarea submits `''`, and `nullable` only excuses a real `null`, so an optional JSON field fails on being emptied. Give it a `default('{}')`, or normalize the blank to `null` before validation in a page's `beforeValidate()` hook:

```php
/**
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
protected function beforeValidate(array $input): array
{
    if (($input['settings'] ?? null) === '') {
        $input['settings'] = null;
    }

    return $input;
}
```

**Four spaces, always.** The Tab handler inserts a fixed four spaces; it is not configurable and does not detect the surrounding indentation.

**A language with no case is not extensible from userland.** `CodeLanguage` is a PHP enum; adding a case means changing the package and the TypeScript union together. Use `Plain` for anything not listed.

## See also

- [Markdown Editor](markdown.md) — prose, with a toolbar and a preview
- [Key Value](key-value.md) — structured settings without a text format
- [Custom Fields](../custom-fields.md) — bringing your own editor component
- [Validation](../validation.md)
- [Forms and Schemas](../overview.md)
