---
title: Localization
---

# Localization

PandaPanel ships its own interface copy in **English and Indonesian**, and picks between them from Laravel's application locale. Nothing has to be configured for either to work.

## A minimal working example

```php
// config/app.php
'locale' => 'id',
```

That is the whole setup. Every string the panel owns — buttons, empty states, table controls, the query builder, date and time pickers, upload messages, the accessible names a screen reader announces — comes back in Indonesian.

```bash
php artisan test    # the package's own parity guards run here too
```

## Which strings the package owns

The line matters, and it is not subtle:

| Copy | Who owns it | Translated by the package |
| --- | --- | --- |
| "Add condition", "No columns available to filter by." | the package | yes |
| Column labels, field labels, page headings | your application | no — shown exactly as you set them |
| A table `description()` or `Callout` body | your application | no |
| Record data | your database | no |

The package translates its own words. Yours are shown as given, because translating them would be the package guessing at your data. Wrap them in `__()` yourself where you want them to follow the locale:

```php
TextColumn::make('name')->label(__('users.name'));

$table->description(__('reports.excludes_archived'));
```

## Where the strings live

```text
lang/en/frontend.php     the Vue components' dictionary
lang/id/frontend.php
lang/en/tables.php       server-rendered table copy
lang/en/forms.php
lang/en/actions.php
…
```

`frontend.php` is the one the browser sees. `SharePanelData` serializes it onto every panel page, and `useTranslator()` reads it from there — which is why switching locale does not need a rebuild, only a request in the new language.

## Overriding the package's copy

```bash
php artisan vendor:publish --tag=panda-panel-translations
```

Published files land in `lang/en` and `lang/id` and override the package per key: a file you publish does not have to be complete, and any key you leave out still comes from the package. They are tracked in `.panel-assets.json`, so `php artisan panel:assets` reports the ones the package has since changed rather than freezing your copy at the release you published from — see [Asset manifest](../upgrading/asset-manifest.md).

## Placeholders

A translated string carries the same `:placeholders` in every locale:

```php
'max_conditions' => 'Up to :count conditions.',   // en
'max_conditions' => 'Maksimal :count kondisi.',   // id
```

Dropping one is not a cosmetic difference — the caller still passes the value, and the reader sees a sentence that has silently lost it. Both locales are checked against each other by `TranslationTest`, key by key and placeholder by placeholder, and a mismatch fails the suite naming the file and the key.

That guard has already earned its place: the Indonesian import error omitted the `:verb` placeholder, so it never pluralised — it said "kolom tersebut" whether one column was missing or six.

## Adding a string

Any new package-owned user-facing string ships with **both** locales in the same change. There is no fallback that makes half of it acceptable: a missing Indonesian key renders the English one, which looks like a translation nobody wrote rather than an error somebody can see.

```php
// lang/en/frontend.php
'tables' => [
    'no_queryable_columns' => 'No columns available to filter by.',
],

// lang/id/frontend.php
'tables' => [
    'no_queryable_columns' => 'Tidak ada kolom yang tersedia untuk difilter.',
],
```

Then read it in the component:

```vue
<script setup lang="ts">
import { useTranslator } from '@/composables/useTranslator';

const { t } = useTranslator();
</script>

<template>
    <p>{{ t('tables.no_queryable_columns') }}</p>
</template>
```

## What the guards check

Three of them run in the ordinary suite:

| Guard | What fails it |
| --- | --- |
| Key parity | A key in one locale and not the other, at any depth |
| Placeholder parity | A string whose `:tokens` differ between locales |
| Structural copy audit | A literal bound into rendered text, an `aria-label`, a `title`, a `placeholder` or an `alt` in a package-owned component |

The third is the one worth understanding. It scans the *positions* copy reaches a reader through, not just visible text — an `aria-label` a screen reader announces is copy even though nobody sees it, and that is precisely where two hardcoded English strings were still hiding after a text-only sweep reported none.

It is a static check with an allowlist, not a dataflow analysis, and it does not claim to be one. Each allowed literal carries the reason it is not copy — `HH`, `MM` and `SS` are format hints that read the same in both shipped locales.

## Runtime, not just dictionaries

Parity proves the dictionaries agree. It does not prove a component reads them: a control with a literal label passes every parity check while showing English to an Indonesian reader.

`resources/js/panel/localeMatrix.test.ts` mounts the real components against each dictionary in turn and reads what comes out — the query builder, the date and time pickers, a colour field, and the table's description and callouts. `tests/browser/datatable.mjs` does the same in Chrome, switching locale through the page props the panel itself reads and asserting the button changes without a reload.

## See also

- [Frontend assets](../concepts/frontend-assets.md) — how the published components reach an application
- [Asset manifest](../upgrading/asset-manifest.md) — how published translations stay in step with the package
- [Contributing: pull requests](../contributing/pull-requests.md) — what a change is expected to carry
