# Markdown Editor

`PandaPanel\Forms\Components\MarkdownEditor` stores formatted text as Markdown. The toolbar only inserts the characters a user would otherwise type and the preview renders a copy — neither one rewrites what is submitted. Reach for it when a column holds prose that something will render later; reach for `RichEditor` when the column must hold HTML, and for a [Code editor](code-editor.md) when it holds source.

## The minimal example

```php
use PandaPanel\Forms\Components\MarkdownEditor;
use PandaPanel\Forms\FormSchema;

FormSchema::make()->schema([
    MarkdownEditor::make('body')
        ->rows(16)
        ->maxLength(20_000)
        ->columnSpanFull(),
]);
```

A plain `text` column is all it needs. The stored value is exactly the characters that were typed.

## Why Markdown rather than HTML

Markdown is safer to store than HTML because it is inert until something renders it, and whatever does the rendering is where escaping belongs. That is why this field does not sanitize and `RichEditor` does: the danger is in the storage format, not in the editor. If you switch a column from one to the other, the sanitizing moves with it.

## The methods

```php
public function toolbar(array $buttons): self   // list<string>
public function maxLength(int $length): self    // default: null, clamped to >= 1
public function rows(int $rows): self           // default: 10, clamped to >= 1
```

| Method | Default | Effect |
| --- | --- | --- |
| `toolbar()` | the ten buttons below | which buttons the strip draws, in the order given |
| `maxLength()` | `null` | adds `max:n` to the rules and sets the textarea's `maxlength` |
| `rows()` | `10` | the textarea's `rows` |

```php
use PandaPanel\Forms\Components\MarkdownEditor;

MarkdownEditor::make('summary')
    ->toolbar(['bold', 'italic', 'link', 'preview'])
    ->rows(6)
    ->maxLength(500)
    ->helperText('Shown in listings. Keep it to a couple of sentences.');
```

### The toolbar buttons

The default list, in order:

```php
['bold', 'italic', 'strike', 'link', 'heading', 'bulletList', 'orderedList', 'blockquote', 'code', 'preview']
```

Each name maps to something the compiled-in editor knows how to do. There is no registry to extend: a name the editor does not recognise draws nothing.

| Name | Label | Inserts |
| --- | --- | --- |
| `bold` | **B** | `**` around the selection |
| `italic` | *I* | `*` around the selection |
| `strike` | S | `~~` around the selection |
| `code` | `</>` | backticks around the selection |
| `link` | Link | `[` … `](https://)` |
| `heading` | H | `## ` before the selection |
| `bulletList` | • List | `- ` before the selection |
| `orderedList` | 1. List | `1. ` before the selection |
| `blockquote` | ❝ | `> ` before the selection |
| `preview` | Preview / Write | toggles the preview pane; drawn on the right of the strip |

After an insert, the caret goes back around what was selected, so typing continues where the user was rather than at the end of the inserted syntax.

Pass an empty array for a bare textarea:

```php
use PandaPanel\Forms\Components\MarkdownEditor;

MarkdownEditor::make('notes')->toolbar([]);
```

## Validation

```php
use PandaPanel\Forms\Components\MarkdownEditor;
use PandaPanel\Forms\FormSchema;

FormSchema::make()
    ->schema([MarkdownEditor::make('body')->required()->maxLength(5000)])
    ->validationRules();

// ['body' => ['required', 'string', 'max:5000']]
```

`max:` on a string counts characters. The same number reaches the browser as a `validation.max` hint and as the textarea's `maxlength`, so the limit is visible before the round trip — and checked again on the server, which is the authority.

## Hydration

```php
protected function castForForm(mixed $value): ?string
```

A string is passed through unchanged; anything else becomes `null`. There is no conversion in either direction — what was typed is what is stored, and what is stored is what is edited.

## The preview

The preview pane is rendered by `resources/js/panel/forms/markdown.ts`, a small and deliberately limited renderer. It exists so the button shows something true about what was typed, without pulling in a parser the application has not agreed to depend on.

It handles headings, blockquotes, fenced code, lists, paragraphs, and the inline marks the toolbar inserts. Anything else is shown as the text it is.

Two properties are worth knowing because they are load-bearing:

- **Every character is HTML-escaped first**, and the handful of tags the renderer adds are the only markup that can exist afterwards. That is what makes the `v-html` safe: by the time markup is added there is no author input left that could be mistaken for it.
- **Only `http`, `https`, `mailto`, and root-relative URLs survive** in a link. A `javascript:` URL would be the one way typed text could still become behaviour, so a link with one is left as the literal text it was typed as.

None of this touches what is persisted. The preview is a view of the value, never a conversion step.

## Rendering the stored value

The field stores Markdown and nothing in the panel renders it back out for the public side of your application — that is the application's decision, and where escaping belongs. Two honest options:

- render it server-side with a Markdown package of your choice and escape the result, or
- show it in an infolist as text, which is what the panel itself does.

The preview renderer is a bundled editor concern and is not exported for general use.

## What crosses the wire

```ts
interface MarkdownEditorFieldDefinition extends BaseFieldDefinition {
    type: 'markdown_editor';
    toolbar: string[];
    maxLength: number | null;
    rows: number;
}
```

## Gotchas

**Nothing is sanitized.** That is the design — Markdown is inert text — but it means the value can contain raw HTML if a user types it, and a renderer that allows raw HTML will render it. Escape at render time, or use a renderer that does not pass HTML through.

**`toolbar()` replaces the list, it does not add to it.** Passing `['preview']` leaves a strip with only the preview toggle. Spell out the full order you want.

**An unknown button name is silently ignored.** The editor renders only the names it knows, so a typo drops a button without a word.

**`maxLength()` is characters, not bytes.** `max:` on a string measures characters in Laravel, and `maxlength` counts UTF-16 code units in the browser. They disagree for astral characters such as emoji; the server's answer is the one that decides.

**A cleared editor submits `''`, not `null`.** `nullable` excuses only a real `null`, so a `string` rule accepts the empty string and it reaches the column. Normalize in `mutateUsing()` if the column should be null when empty.

**`rows()` is the initial height only.** The textarea can be resized by the user; nothing pins it.

## See also

- [Code Editor](code-editor.md) — source text, monospaced, with a `json` rule
- [Text](text.md) — a plain textarea when no formatting is wanted
- [Rich Editor](rich-editor.md) — HTML, sanitized on the way to the record
- [Validation](../validation.md)
- [Infolist Entries](../../infolists/entries.md) — showing the stored text
- [Forms and Schemas](../overview.md)
