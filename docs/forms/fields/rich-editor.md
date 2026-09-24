# Rich Editor Field

`PandaPanel\Forms\Components\RichEditor` is formatted text, stored as HTML. Reach for it when an author needs headings, emphasis, lists and links and the output is going to be rendered as markup. When the output should stay plain text, use [`Textarea`](text.md); when the author is comfortable with markup, use [`MarkdownEditor`](markdown.md), which stores text rather than HTML.

## A minimal form

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Posts\Forms;

use PandaPanel\Forms\Components\RichEditor;
use PandaPanel\Forms\FormSchema;

final class PostForm
{
    public static function configure(FormSchema $schema): FormSchema
    {
        return $schema->schema([
            RichEditor::make('body')
                ->maxLength(20000)
                ->columnSpanFull(),
        ]);
    }
}
```

That renders an editable region with the default toolbar, validates `body` as `nullable|string|max:20000`, and sanitizes the HTML before it reaches the record.

## The value is sanitized on the way in

HTML from a form is the one field value that is dangerous by default: it is written by a user and later rendered as markup, which is the definition of stored XSS. This field answers that in one place — `mutate()`, on the way to the record — for three reasons:

- Not on the way out, where a single unescaped render would undo it.
- Not by trusting the editor, which is a control the browser can be told to skip.
- Not at display time in each of the places the value is shown, which is a list nobody can keep complete.

So the stored value is already safe, and every later read of it is too.

```php
use PandaPanel\Forms\Components\RichEditor;

RichEditor::make('body')->mutate(
    '<p>ok</p><iframe src="https://evil.test"></iframe>',
    null,
);
// => '<p>ok</p>'
```

## Methods

### `allowedTags(array $tags): self`

`list<string>`. The tags that survive sanitizing. The default is deliberately small:

```php
['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
 'ul', 'ol', 'li', 'blockquote', 'code', 'pre',
 'h2', 'h3', 'h4', 'a', 'hr']
```

No `script`, `style`, `iframe`, `object`, `embed` or `form` — each of those turns stored text into behaviour.

```php
RichEditor::make('body')->allowedTags([
    'p', 'br', 'strong', 'em', 'a', 'ul', 'ol', 'li',
]);
```

Calling this replaces the list; it does not add to it. Widening it is a decision a schema makes explicitly, and every tag added is a tag somebody has to be sure about.

The list never reaches the browser. It is the server's answer, and the toolbar is only a suggestion to the editor.

### `toolbar(array $buttons): self`

`list<string>`. Which buttons the editor draws. The default is:

```php
['bold', 'italic', 'strike', 'link',
 'h2', 'h3', 'bulletList', 'orderedList', 'blockquote', 'undo', 'redo']
```

Every name the renderer understands, and the editing command behind it:

| Name | Button | Produces | Reports pressed |
| --- | --- | --- | --- |
| `bold` | `B` | `<strong>` | yes |
| `italic` | `I` | `<em>` | yes |
| `underline` | `U` | `<u>` | yes |
| `strike` | `S` | `<s>` | yes |
| `h2` | `H2` | `<h2>` | yes |
| `h3` | `H3` | `<h3>` | yes |
| `blockquote` | `❝` | `<blockquote>` | yes |
| `bulletList` | `• List` | `<ul><li>` | yes |
| `orderedList` | `1. List` | `<ol><li>` | yes |
| `link` | `Link` | `<a href>`, from a prompt | yes |
| `undo` | `↶` | — | no |
| `redo` | `↷` | — | no |

The buttons that toggle something carry `aria-pressed`, and it reflects where the caret is rather than what was last clicked. The two that are actions carry none: `aria-pressed` on `undo` would be claiming the editor is "currently undone".

A name not in that table draws no button, exactly as an unregistered icon renders nothing. Passing `[]` renders the editor with no toolbar at all.

```php
RichEditor::make('excerpt')->toolbar(['bold', 'italic', 'link']);
```

### `maxLength(int $length): self`

Default `null`. Adds `max:N` to the rules and reaches the browser as `maxLength`. Values below `1` are clamped to `1`.

```php
RichEditor::make('body')->maxLength(20000);
```

### `sanitize(string $html): string`

Public, and the actual implementation of the guarantee above. It is called for you by `mutate()`; call it directly when you need the same treatment somewhere else, or in a test.

```php
$field = RichEditor::make('body');

$field->sanitize('<p>Hello <script>alert(1)</script><b onclick="steal()">there</b></p>');
// => '<p>Hello alert(1)<b>there</b></p>'

$field->sanitize('<a href="javascript:alert(1)">click</a>');
// => '<a>click</a>'
```

Note what happened to the script: the tag is gone and its text is not. `strip_tags()` removes elements and keeps their contents, so `alert(1)` survives as the plain text it now is. That is safe — it is no longer markup — but it is not the same as deleting the node, and a schema that expects disallowed elements to vanish entirely will be surprised.

Two passes, and the second is the half that matters:

1. `strip_tags()` against the allowlist. This removes disallowed elements but keeps every attribute on the ones that remain, so it is not enough by itself.
2. Attribute cleanup that removes `on*` handlers, decodes URL entities, and keeps `href` or `src` only when they are relative URLs or use `http`, `https`, `mailto`, or `tel`. Encoded or whitespace-padded `javascript:`, `data:` and similar schemes are stripped with the attribute.

### `mutate(mixed $value, ?Model $record): mixed`

Overridden from `Field`. A string value is sanitized first, then handed to whatever `dehydrateStateUsing()` or `mutateUsing()` declared. A non-string value passes through untouched.

```php
use Illuminate\Support\Str;

RichEditor::make('body')
    // Receives HTML that has already been sanitized.
    ->dehydrateStateUsing(static fn (mixed $value): string => Str::of((string) $value)
        ->trim()
        ->toString());
```

### `type(): FieldType`

Returns `FieldType::RichEditor`, serialized as `'rich_editor'`.

## Serialized shape

`RichEditor::make('body')->maxLength(20000)->toArray(null, 'create')` adds two keys to the base field payload:

| Key | Type | Default |
| --- | --- | --- |
| `toolbar` | `string[]` | the eleven names above |
| `maxLength` | `number \| null` | `null` |

`allowedTags` is not among them, on purpose: the browser has no use for a list it is not the authority on.

## The editor itself

`RichEditorField.vue` is [Tiptap](https://tiptap.dev) — a ProseMirror document, edited in the browser and serialized to HTML on every change. It replaced a `contenteditable` region driven by `document.execCommand`, and the reason has nothing to do with that API's deprecation notice.

A browser's own editing commands are a *suggestion*. What `bold` produced differed between engines, `formatBlock` would happily nest a heading inside a list item, and pasting from a word processor put whatever markup the clipboard carried straight into the value. There was no document model, so there was nothing to be wrong about — only markup, and no way to say which markup was legal.

Tiptap has a schema, and that is the whole of the improvement:

- **Only the configured nodes and marks can exist**, whatever is typed or pasted. Paste is *parsed* against the schema rather than inserted, so the `<span style>` soup a word processor sends arrives as the emphasis it was meant to be.
- **Headings are limited to `h2`, `h3` and `h4`** — the levels `allowedTags()` lets through by default. A heading the server would strip is formatting a user applies and silently loses.
- **`bold` is `<strong>` and `italic` is `<em>`**, every time. `b` and `i` stay in the default allowlist because stored values written by the old editor still contain them.
- **An empty document emits `''`**, not the `<p></p>` it serializes as, so an empty editor does not satisfy a `required` field by accident.

The packages are `@tiptap/vue-3`, `@tiptap/starter-kit` and `@tiptap/pm`, and they are in the panel's own `dependencies`, so `panel:install` names them if your application has not declared them.

None of this changes where the guarantee lives. The editor's schema is the *editor's* answer; `RichEditor::sanitize()` on the server is the actual one, and it runs on every value whatever produced it.

## Rendering the stored value

The value is HTML, and rendering it means `v-html` — which is exactly why the sanitizing happens before storage rather than after.

```vue
<template>
    <article class="prose dark:prose-invert" v-html="post.body" />
</template>
```

Only ever do this with a value this field wrote. HTML from anywhere else has not been through `sanitize()`.

## Gotchas

- **`maxLength` counts the HTML, not the words.** `<p><strong>Hi</strong></p>` is 26 characters, not 2. Size the limit against the column and the markup, not against the visible text.
- **The toolbar and the allowlist are two lists, and they can disagree.** Adding `underline` to the toolbar works because `u` is already allowed; adding a button whose tag you removed from `allowedTags()` produces formatting that the user applies and the server silently strips. Change both together.
- **Attributes on allowed tags mostly survive sanitizing.** `strip_tags()` filters elements, not attributes, and the second pass removes `on*` handlers plus unsafe `href`/`src` URLs. A `style`, `class`, `id` or `data-*` attribute reaching the column is stored. That is not executable, but it is not neutral either — it can restyle the page that renders it. The editor's schema drops these on paste, so in practice they arrive only from somewhere other than this field; the server is still the thing that decides.

- **An attribute the schema does not declare does not survive being loaded, either.** The stored value is parsed into a document when the field opens, so a `<p id="intro">` written by something else comes back as a plain `<p>` the first time the record is edited. Anything meaningful about the content belongs in a column, not in an attribute on its markup.
- **`link` prompts for a URL with `window.prompt`.** The prompt is pre-filled when the caret is already inside a link, so the button edits one as well as making one. Cancelling leaves the selection alone; answering with an empty string *removes* the link the caret is in, which is the only way to take one off again. There is no link editor, and the button adds nothing but `href` — no `target`, no `rel`.
- **There is no image button, and `img` is not in the default allowlist.** Store images with [`FileUpload`](file-upload.md), which goes through the panel's own endpoint under a declared disk and directory. Adding `img` to `allowedTags()` lets stored HTML reference any URL, which is a decision worth taking deliberately.
- **`required()` checks the string, not the meaning.** Markup a user did not intend as content — a stray `<p></p>` — is a non-empty string and satisfies `required`. The `<br>` case is handled; nothing else is.
- **Your hooks run after sanitizing.** `mutate()` sanitizes *before* calling `dehydrateStateUsing()` or `mutateUsing()`, so markup a hook introduces is stored as written, without passing the allowlist.

## See also

- [Markdown editor](markdown.md)
- [Code editor](code-editor.md)
- [Text field](text.md)
- [File upload field](file-upload.md)
- [Builder field](builder.md)
- [Hydration and dehydration](../hydration.md)
- [Validation](../validation.md)
- [Forms overview](../overview.md)
