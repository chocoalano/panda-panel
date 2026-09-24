# Markdown Editor

`PandaPanel\Forms\Components\MarkdownEditor` stores formatted text as Markdown. The toolbar edits the Markdown a user would otherwise type and the preview renders a copy — neither one rewrites what is submitted. Reach for it when a column holds prose that something will render later; reach for `RichEditor` when the column must hold HTML, and for a [Code editor](code-editor.md) when it holds source.

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
| `maxLength()` | `null` | adds `max:n` to the rules, and stops the editor past that many characters |
| `rows()` | `10` | how many lines of text the editor is tall |

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

| Name | Does |
| --- | --- |
| `bold` | toggles `**` around the selection |
| `italic` | toggles `*` around the selection |
| `strike` | toggles `~~` around the selection |
| `underline` | toggles `<u>` around the selection |
| `code` | toggles backticks around the selection |
| `codeBlock` | wraps the selection in a fenced block |
| `link` | opens a dialog for the text and the URL |
| `heading` | a menu of `#` through `######` |
| `bulletList` | toggles `- ` on the selected lines |
| `orderedList` | toggles `1. ` on the selected lines |
| `taskList` | toggles `- [ ] ` on the selected lines |
| `blockquote` | toggles `> ` on the selected lines |
| `table` | inserts a table skeleton |
| `undo` / `redo` | the editor's own history |
| `divider` | a vertical rule in the strip, not a Markdown one |
| `preview` | toggles the preview pane |

**These toggle rather than insert.** Pressing **B** on already-bold text un-bolds it; the old toolbar pasted a second pair of asterisks and produced `****text****`. That is the single most visible consequence of the editor understanding Markdown rather than inserting characters into a textarea.

Everything before `underline` in that table was in the previous toolbar and means the same thing. The rest are new names the editor already knew how to draw.

Pass an empty array for a bare editing surface with no strip:

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

`max:` on a string counts characters. The same number reaches the browser as a `validation.max` hint and as the editor's own limit, so typing stops at the limit rather than being refused after the round trip — and it is checked again on the server, which is the authority.

## Hydration

```php
protected function castForForm(mixed $value): ?string
```

A string is passed through unchanged; anything else becomes `null`. There is no conversion in either direction — what was typed is what is stored, and what is stored is what is edited.

## The editor and its preview

`MarkdownEditorField.vue` is [`md-editor-v3`](https://imzbf.github.io/md-editor-v3/en-US/): CodeMirror with a Markdown grammar for the editing surface, and `markdown-it` for the preview.

What it replaced was a textarea, a table of strings to wrap the selection in, and a hand-written renderer covering a subset of Markdown. Each of those three was a small lie:

- The toolbar inserted syntax without understanding it, so a toggle was not a toggle.
- The textarea had no idea it held Markdown, so a list continued only if the user typed the next marker themselves.
- The preview implemented headings, quotes, fenced code and the inline marks the toolbar knew about — which meant a table, a footnote or a reference link, all valid Markdown and all stored perfectly well, rendered as the literal characters they were typed as. The button showed something true about the *toolbar*, not about the value.

The preview is now a real Markdown renderer, so what it shows is what a Markdown renderer produces. What has not changed is the part that matters: **nothing is sanitized and nothing is converted.** What is typed is what is submitted, and the preview is a view of the value rather than a step on the way to storing it.

### What is deliberately turned off

`md-editor-v3` fetches highlight.js, Prettier, Mermaid, KaTeX, ECharts and Cropper from unpkg the first time a feature needs one. Every one of those is disabled, because a panel that reaches out to a CDN mid-edit behaves differently on a network that blocks it, under a strict `Content-Security-Policy`, and on an air-gapped install — and the failure lands on whoever is in the middle of writing something.

Nothing this field renders loads anything at runtime that your application did not build. The cost is honest and small: fenced code in the preview is monospaced rather than colourised, and there is no reformat button.

### The colours are the panel's

`md-editor-v3` brings its own content theme, and its defaults are literal hex colours: a white background, a `#2d8cf0` link, a `#e6e6e6` border. In a panel whose `--background` is not `#fff` that is a stark white rectangle in the middle of a form, and in a re-themed panel it is somebody else's brand colour on every link in a preview.

So the editor's theme is mapped to the panel's tokens — `--md-theme-link-color` to `--primary`, `--md-theme-bg-color` to `--background`, and so on for the chrome, the borders, the quotes, the code plates and the radius. That is the library's own extension point rather than a fight with its selectors, and it follows a re-themed panel and a dark one for free, because the tokens already do. See [the Tailwind theme](../../frontend/tailwind-theme.md) for what those tokens are.

The mapping lives in the one `<style scoped>` block in the panel's frontend, in `MarkdownEditorField.vue`. It is not in `panda-panel.css`, deliberately: that stylesheet is published into your application, and a bare `.md-editor` rule in it would restyle an editor you mounted for your own purposes.

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

**`maxLength()` is characters, not bytes.** `max:` on a string measures characters in Laravel, and the browser counts UTF-16 code units. They disagree for astral characters such as emoji; the server's answer is the one that decides.

**A cleared editor submits `''`, not `null`.** `nullable` excuses only a real `null`, so a `string` rule accepts the empty string and it reaches the column. Normalize in `mutateUsing()` if the column should be null when empty.

**`rows()` is a fixed height, not an initial one.** The editor is a sized box rather than a growing textarea, so a long document scrolls inside it. Give a field that holds an article a larger `rows()` than one that holds a summary.

**The editor's own labels are English.** `md-editor-v3` ships `zh-CN` and `en-US`, and this field asks for `en-US` regardless of the panel's locale. Tooltips and the link dialog are therefore in English even in a panel that is not — the field's own label, helper text and error come from the panel's translations as usual.

**The toolbar dropped nothing, but it did rename what a button does.** A name the editor does not recognise still draws nothing, silently, exactly as before.

## See also

- [Code Editor](code-editor.md) — source text, monospaced, with a `json` rule
- [Text](text.md) — a plain textarea when no formatting is wanted
- [Rich Editor](rich-editor.md) — HTML, sanitized on the way to the record
- [Validation](../validation.md)
- [Infolist Entries](../../infolists/entries.md) — showing the stored text
- [Forms and Schemas](../overview.md)
