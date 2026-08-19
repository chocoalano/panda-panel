# Translations

The package ships its own strings in English and Indonesian, under the `panda-panel`
translation namespace. Nothing has to be published, configured, or copied for either to work:
set the application's locale and every button, confirmation, empty state and error notification
the package renders follows it. Your *own* names — column names, model names — follow too, through
one file you write: see [Labels derived from your own names](#labels-derived-from-your-own-names).

```php
// Anywhere the application already decides a locale.
app()->setLocale('id');
```

| Locale | File | Status |
| --- | --- | --- |
| `en` | `lang/en/*.php` | Complete — the source language |
| `id` | `lang/id/*.php` | Complete — key-for-key with `en`, enforced by a test |

A locale the package does not ship falls back to `en`, because that is Laravel's
`fallback_locale` default. A panel in French renders English rather than raw keys.

## What is translated

Nine groups, one file each per locale.

| Group | What it covers | Example key |
| --- | --- | --- |
| `actions` | Every built-in action: label, modal, confirmation, success message | `panda-panel::actions.delete.heading` |
| `tables` | Empty state, search placeholder, filter chrome, the trashed filter | `panda-panel::tables.empty_state.heading` |
| `forms` | Validation the package's own fields raise | `panda-panel::forms.builder.unknown_block` |
| `notifications` | The HTTP-status toasts, the two-factor email | `panda-panel::notifications.http.403.title` |
| `errors` | Every refusal a user can reach | `panda-panel::errors.unknown_resource` |
| `pages` | The settings pages the package registers, widget empty states | `panda-panel::pages.profile.title` |
| `integrations` | The lifecycle points an integration fires at | `panda-panel::integrations.trigger.after_create` |
| `frontend` | Everything the Vue components draw themselves with | `panda-panel::frontend.tables.rows_per_page` |
| `formats` | Number separators and default date patterns | `panda-panel::formats.decimal_separator` |

Read a key the way any namespaced package translation is read:

```php
__('panda-panel::actions.delete.label');   // "Delete" / "Hapus"
```

## What is not translated, and why

**Exception messages in `PandaPanel\Exceptions`.** A duplicate column name, a resource with no
model, a missing policy — these mean the package is wired up wrong. Their reader is a developer
holding a stack trace, and a translated message is one that cannot be pasted into a search box.

**Console output.** `panel:install`, `panel:cache` and the generators speak to whoever is at the
terminal, which is the same person.

**Test assertion messages** in `PandaPanel\Testing`, for the same reason.

**Labels derived from your own code.** A column named `created_at`, a resource for a `User`
model, an action named `impersonate` — those are your application's words, not the package's.
They have their own mechanism, below.

## Labels derived from your own names

Translating what the package wrote is only half a translation. A column named `created_at` still
renders as "Created At" in every locale, because that string is `Str::headline('created_at')` and
`Str::headline()` knows English. Before, the only fix was `->label()` on every column of every
table.

So the derivation asks first. Write one file:

```php
// lang/id/panel.php

return [
    'fields' => [
        'name' => 'Nama',
        'email' => 'Surel',
        'created_at' => 'Dibuat pada',
    ],

    'resources' => [
        'User' => 'Pengguna',
    ],
];
```

Every column, form field, infolist entry, filter, summary and export column named `created_at`
now reads "Dibuat pada" — in every table, in every panel, without a single `->label()` call.

The file is the application's, not the package's, because the names in it are the application's.
Its name comes from `panda-panel.labels.file` and defaults to `panel`.

### The groups

| Group | Keyed by | Read by |
| --- | --- | --- |
| `fields` | attribute name | `Column`, `Field`, `Entry`, `ExportColumn`, `ImportColumn`, `Filter`, `Constraint`, `Summarizer`, table `Group` |
| `relations` | relationship name | `RelationManager::title()`, the `Relationship` form layout's heading |
| `resources` | model class basename | `Resource::defaultLabel()` |
| `resources_plural` | model class basename | `Resource::defaultPluralLabel()` |
| `pages` | page class basename | `Page::title()` |
| `clusters` | cluster class basename, minus `Cluster` | `Cluster::title()` |
| `actions` | action name | `Action::getLabel()`, `NotificationAction` |
| `notifications` | notification name | `Notification` title |
| `tabs` | tab key | `Tab::getLabel()` |
| `blocks` | block name | builder `Block::getLabel()` |
| `values` | the value itself | `BadgeColumn` value labels |
| `panels` | panel id | `Panel::getName()` |

### Order of resolution

Three steps, and the first one that answers wins:

1. **`->label()`, `$title`, `$pluralLabel`** — whatever was set explicitly in code. Unchanged, and
   still first.
2. **The application's label file** for that group and name.
3. **`Str::headline()`** — the derivation, exactly as before.

An application with no such file gets step 1 and step 3, which is the behaviour it always had.

### Plurals are words, not inflections

`Str::plural()` knows English. Applied to a translated singular it produces "Penggunas", so a
resource whose singular is translated and whose plural is not keeps the singular unchanged:

```php
'resources' => ['User' => 'Pengguna'],            // label: "Pengguna", plural: "Pengguna"
'resources_plural' => ['User' => 'Para pengguna'], // plural: "Para pengguna"
```

### Relation attributes

An entry or export column named `user.name` is looked up as the literal key `user.name`, not as a
`user` array containing `name`:

```php
'fields' => [
    'user' => 'Pengguna',        // the field named `user`
    'user.name' => 'Nama pemilik', // the field named `user.name`
],
```

Both can coexist, which is why the lookup reads the group and indexes it rather than asking
Laravel to walk the dots. Nesting still works for an application that prefers it and has no plain
field to clash with.

### One answer per name

The lookup is flat: `fields.name` is one word for every table in every panel. Two resources that
need different words for the same attribute name say so the way they always could — `->label()`
on the one that differs, which is checked before the file is ever read.

### Not covered

`Plugin::metadata()` still derives its name with `Str::headline()`. A plugin's name is read by
`panel:plugins` and by the plugin list, both of which are developer surfaces.

## The frontend

The Vue components have their own group, `frontend`, and it is the only one
that leaves the server. `SharePanelData` puts it on every page as
`translations`, beside a `locale` string, and the components read it through
`useTranslator()`:

```ts
import { useTranslator } from '@/composables/useTranslator';

const { t, locale } = useTranslator();
```

```vue
<span>{{ t('tables.rows_per_page') }}</span>
<Input :aria-label="t('tables.search_column', { column: column.label })" />
```

Laravel's own `:name` placeholder syntax, so a line reads the same in the PHP
file whether it is rendered on the server or in the browser.

### No vue-i18n

The components are *published* into an application, so a runtime dependency
here is a line every application has to add to its own `package.json` and keep
in step with this package's — for a lookup that is thirty lines. The cost of
the dependency is larger than the thing it replaces.

### What crosses the wire

Only `frontend`. The other groups are read in PHP and would put a hundred
abort messages in the page source of every screen.

It is a closure like every other shared prop, so Inertia leaves it out of a
partial reload: sorting a table or turning a page carries none of it. The
whole dictionary is 232 keys and around 9 KB of JSON, on full visits only.

Schema labels are not in it. A column header, a field label, an action's
button — those are resolved on the server and arrive inside the payload
already translated, because that is where the schema lives.

### A missing key reads as English, not as a key

`t('tables.rows_per_page')` with nothing behind it renders "Rows per page",
not `tables.rows_per_page`. The keys are named after their English text, so a
gap degrades to approximately the source language rather than to something
that looks broken in the middle of a sentence.

That is the runtime behaviour, not the plan. Two tests read every `t('…')`
call and every frontend key held in a component constant, and assert each one
resolves in **both** locales — so a missing key fails CI rather than shipping
quietly.

### The shadcn primitives

`resources/js/components/ui/**` is vendored from shadcn-vue and deliberately
kept in that project's formatting, so an upstream update is not a diff about
whitespace. Ten of those files now call `t()` — for the screen-reader labels
on dialogs, sheets, the sidebar and the spinner.

That is a real cost: those specific lines will conflict the next time you pull
a component from shadcn-vue directly. It was taken deliberately. Leaving them
English means a screen-reader user in Indonesian hears "Close" on every dialog
in the panel, which is worse than a ten-line merge.

## Letting a reader choose

The panel follows `app()->getLocale()` however that was set, so an application
with `APP_LOCALE=id` renders entirely in Indonesian with no configuration at
all. The switcher is for the other case: letting each reader disagree.

```php
// config/panda-panel.php
'locales' => [
    'en' => 'English',
    'id' => 'Bahasa Indonesia',
],
```

That is the whole setup. A language menu appears in the panel header and on
the login screen, and the choice survives the sign-in between them.

**Empty by default, and empty means no switcher.** An application that serves
one language should not grow a language menu in every panel header because it
upgraded. One locale is the same as none — there is nothing to switch to.

**Names are written in their own language.** Somebody looking for their
language is looking for the word they would use for it, and a reader who
cannot read the current locale cannot read "Indonesian" in it either.

**A panel can narrow it**, for a customer portal in two languages beside an
internal admin in one:

```php
Panel::make('admin')->locales(['en' => 'English']);
```

### Where the choice is kept

In the session, under `PandaPanel\Http\Middleware\SetPanelLocale::SESSION_KEY`.

Not a column on the user: a panel installs into somebody else's `users` table,
and a package that required a migration to let a reader change language would
be asking for a schema change to render a dropdown. To make the choice follow
an account across devices, set the locale yourself in your own middleware —
`SetPanelLocale` only ever *narrows*, and never insists.

A stored locale the current panel does not offer is ignored rather than
cleared. Two panels may offer different languages, and forgetting the choice
on the way through the narrower one would lose it for the panel that did offer
it.

### The routes

| Route | Name | Who |
| --- | --- | --- |
| `POST {panel}/locale` | `{panel}.locale` | signed in |
| `POST {panel}/locale` | `{panel}.auth.locale` | guests |

Two, because a login screen is exactly where somebody notices they are reading
the wrong language — and a reader who cannot read it cannot sign in to reach
the other one. Both check the submitted code against the panel's own list:
`app()->setLocale()` accepts any string, and an unchecked one would let a
request write a directory traversal into the session for the translator to try
to load.

`SetPanelLocale` runs directly after `ResolvePanel` in both route groups —
before every controller, because a schema is built with its labels already
resolved and a controller that ran first would have built it in the wrong
language.

## Numbers and dates

`number_format($value, 2, '.', ',')` is English, and it was English in every
panel however the locale was set. Grouping is the one place a half-translated
interface is not merely awkward: `1,234.56` shown to a reader for whom that
means one and a bit is a number misread without anybody noticing.

`lang/{locale}/formats.php` holds the separators and the default date
patterns, and `PandaPanel\Support\Format` is what every figure and date in the
panel goes through:

| Key | `en` | `id` | Read by |
| --- | --- | --- | --- |
| `decimal_separator` | `.` | `,` | every number |
| `thousands_separator` | `,` | `.` | every number |
| `date` | `M j, Y` | `j M Y` | `DateColumn` |
| `date_time` | `M j, Y H:i` | `j M Y H:i` | `DateTimeColumn` |
| `date_time_verbose` | `M j, Y g:ia` | `j M Y H:i` | `DateTimeEntry` |
| `date_compact` | `j M Y` | `j M Y` | the date filter's chips |

The date patterns are **defaults**. A column or entry that calls `->format()`
has said what it wants and is never overridden from here.

### Why not `Illuminate\Support\Number`

It would do this properly, through ICU. It also calls
`ensureIntlExtensionIsInstalled()`, and this package requires only `ext-json`
and `ext-zip` — making `ext-intl` a hard requirement of an admin panel is a
real install barrier on shared hosting. A two-key table gets the grouping
right for every locale anybody has asked for, and an application that needs
ICU can format its own values and pass strings through.

A locale that ships no `formats.php` falls back to the English separators
rather than to nothing: `1234.56` grouped with nothing is still readable,
`1234 56` is not.

### Carbon follows too

`diffForHumans()` — the relative dates on `DateColumn::relative()`, the
notification timestamps, the passkey list — is Carbon's, and Carbon's locale
follows `app()->setLocale()`. Nothing here does anything about it because
nothing needs to.

## Overriding a sentence

Publish the files and edit them:

```bash
php artisan vendor:publish --tag=panda-panel-translations
```

They land in `lang/vendor/panda-panel/{en,id}`, which Laravel reads *before* the package's own
copy. A key you leave out of the published file still comes from the package, so a file with one
line in it overrides one sentence and nothing else.

Publishing is for **rewording**, not for adding a locale the package already ships. A published
`lang/vendor/panda-panel/en/actions.php` stops following the package, so an upgrade that adds a
key there will not reach your copy — you get the raw key until you add it by hand. The
translation test in this repository exists to catch exactly that class of gap.

## Adding a locale

Add a directory beside the package's own — no publish required, because Laravel merges the
application's `lang/vendor/panda-panel` over whatever the package has:

```
lang/vendor/panda-panel/fr/actions.php
lang/vendor/panda-panel/fr/tables.php
...
```

Copy `lang/en` as the starting point: it is the source language, and every other locale is
key-for-key with it.

## Where the strings are resolved

Every translated string is read **per request**, never at boot and never into a constant.

That is not an accident of style — it is what makes a locale switch work at all. A `const` or a
static property default is evaluated before the translator can answer, so several places that
used to hold a sentence now hold `null` and resolve it where it is read:

| Was | Now |
| --- | --- |
| `Panel::DEFAULT_ERROR_NOTIFICATIONS` (const) | `Panel::defaultErrorNotifications()` |
| `TrashedFilter::LABELS` (const) | `TrashedFilter::labels()` |
| `TableSchema::$emptyStateHeading = 'No records found'` | `?string $emptyStateHeading = null`, resolved in `toArray()` |
| `TableWidget::$emptyMessage = 'Nothing to show yet.'` | `string $emptyMessage = ''`, resolved in the payload |
| `Page::$subheading`, `Page::$navigationGroup` read directly | `Page::subheading()`, `Page::navigationGroup()` |

A page or widget that assigns those properties still works exactly as before — the seams read the
property first and fall back to the translation only when nothing was set.

## `panel:cache` is safe

`PandaPanel\Cache\PanelManifest` stores class names and nothing else — no labels, no titles, no
navigation text. Caching a panel does not freeze it into the locale it was cached in.

## Related

- [config/panda-panel.php](../configuration/panda-panel.md)
- [Publish tags](../cli/publish-tags.md)
- [Custom pages](../pages-navigation/custom-pages.md)
- [`panda-panel.labels`](../configuration/panda-panel.md#labels)
- [`panda-panel.locales`](../configuration/panda-panel.md#locales)
- [Server metadata to Vue](../concepts/metadata-to-vue.md)
