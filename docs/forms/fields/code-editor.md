# Code Editor

`PandaPanel\Forms\Components\CodeEditor` stores source text and edits it in Monaco, the editor VS Code is built on. Reach for it when a column holds something a person writes as code — a JSON configuration blob, a snippet of CSS, a SQL fragment — rather than prose, which belongs in a [Markdown editor](markdown.md) or a rich editor.

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
| `language()` | `CodeLanguage::Plain` | adds `json` for `Json` only | names the language in the header strip, and selects the grammar |
| `rows()` | `12` | none | how many lines of code the editor is tall |
| `maxLength()` | `null` | adds `max:n` | typing stops at that many characters |

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

| Case | Wire value | Header label | Monaco grammar |
| --- | --- | --- | --- |
| `CodeLanguage::Plain` | `plain` | Plain text | `plaintext` |
| `CodeLanguage::Json` | `json` | JSON | `json` |
| `CodeLanguage::Html` | `html` | HTML | `html` |
| `CodeLanguage::Css` | `css` | CSS | `css` |
| `CodeLanguage::JavaScript` | `javascript` | JavaScript | `javascript` |
| `CodeLanguage::Php` | `php` | PHP | `php` |
| `CodeLanguage::Sql` | `sql` | SQL | `sql` |
| `CodeLanguage::Yaml` | `yaml` | YAML | `yaml` |
| `CodeLanguage::Markdown` | `markdown` | Markdown | `markdown` |

`Json` also adds Laravel's `json` rule, so a document that will not parse is rejected before it reaches a column — and the editor now says so first, underlining the offending character as it is typed.

`json`, `css` and `html` have a language service behind them, which is why those three are checked as you type. The rest are grammars: coloured and folded and bracket-matched, and not validated.

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

`resources/js/panel/forms/fields/CodeEditorField.vue` is [Monaco](https://github.com/imguolao/monaco-vue) — the editor from VS Code — reading and writing the same string as before.

What it replaced was a textarea with a monospace face and a `Tab` handler, and that was not enough for reasons that are not decoration. A textarea cannot tell a string from a key, so a JSON blob was edited without ever being told it did not parse until the server refused the whole form. The four spaces `Tab` inserted were four spaces wherever the caret happened to be, indentation or not. There was no bracket matching, no way to select a block, no way to find anything in a long document.

What you get now:

- **Syntax highlighting** for every case of `CodeLanguage`, and **validation as you type** for `json`, `css` and `html`.
- **A gutter with line numbers**, bracket matching, folding, multiple cursors, and `Ctrl+F` inside the field.
- **Tab is indentation**, four spaces of it, understood as indentation rather than as four characters. Escape and then Tab is still the way out, which is the convention Monaco is itself the origin of.
- Deliberately *not* an IDE: no minimap, no sticky-scroll header, no overview ruler. This is a control in a form.

The header strip is unchanged — the language and the live line count.

### It is bundled, not fetched

`@guolao/vue-monaco-editor` defaults to downloading Monaco from a CDN at runtime. That default is off. The panel configures the loader with the copy your application built, so a code field works behind a proxy, under a `Content-Security-Policy` that names its own origins, and on an air-gapped install — and opening a form does not tell a third party that you did.

`monaco-editor` and `@guolao/vue-monaco-editor` are in the panel's own `dependencies`, so `panel:install` names them if your application has not declared them.

### It arrives late, and the field works before it does

Monaco is large, so it is behind a dynamic `import()` and lands in its own chunk: a panel with no code field never downloads a byte of it, and the language grammars are lazy inside Monaco too — opening a PHP field fetches the PHP grammar and not the other eighty.

Until that chunk arrives, the field renders the textarea it used to be, carrying the same id, the same value and the same describing sentences. **That is not only a loading state.** If the chunk cannot be loaded at all, the textarea stays, and the field stays editable and submittable — a form that cannot be filled in is worse than a form with a plain box in it.

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

**`maxLength()` counts characters, not lines.** It becomes `max:n` on a string, which Laravel measures in characters, and the editor stops at the same number. A long JSON document hits it faster than it looks.

**`language(CodeLanguage::Json)` does not make the value an array.** The rule proves it parses; the stored value is still the text the user typed, including their whitespace. Decode in `mutateUsing()` if the column expects structure.

**The `json` rule rejects an empty editor.** A cleared editor submits `''`, and `nullable` only excuses a real `null`, so an optional JSON field fails on being emptied. Give it a `default('{}')`, or normalize the blank to `null` before validation in a page's `beforeValidate()` hook:

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

**Four spaces, always.** The indent width is a fixed four spaces and is not configurable from a schema.

**Building an application that uses a code field needs more memory than Node gives Vite by default.** Monaco is big enough that Rollup transforming it exceeds the default heap, and the failure is an out-of-memory abort that names no module. Raise it:

```bash
NODE_OPTIONS=--max-old-space-size=4096 npm run build
```

**The editor's own strings are English**, as Monaco ships them. The field's label, helper text and error come from the panel's translations as usual.

**A language with no case is not extensible from userland.** `CodeLanguage` is a PHP enum; adding a case means changing the package and the TypeScript union together. Use `Plain` for anything not listed.

## See also

- [Markdown Editor](markdown.md) — prose, with a toolbar and a preview
- [Key Value](key-value.md) — structured settings without a text format
- [Custom Fields](../custom-fields.md) — bringing your own editor component
- [Validation](../validation.md)
- [Forms and Schemas](../overview.md)
