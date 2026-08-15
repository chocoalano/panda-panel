# Page Headings

Every panel screen ships three pieces of text: a `title` for the browser tab, a `heading` for the `<h1>` above the content, and an optional `subheading` under it. They are separate because they answer different questions — an edit page is titled `Edit Ada Lovelace` in the tab and headed `Ada Lovelace` on screen, because the breadcrumb above the heading already says which page this is.

Standalone pages and resource pages both carry the three, with slightly different machinery: a `Page` declares them as static properties, a `ResourcePage` resolves them through methods that can see the record.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Pages;

use PandaPanel\Pages\Page;

final class Settings extends Page
{
    protected static ?string $title = 'Settings';

    protected static ?string $subheading = 'Application-wide configuration.';
}
```

```php
Settings::title();     // 'Settings'
Settings::heading();   // 'Settings' — follows the title
```

The tab reads `Settings`, the page is headed `Settings`, and `Application-wide configuration.` sits underneath in muted text.

## On a standalone page

```php
protected static ?string $title = null;
protected static ?string $heading = null;
protected static ?string $subheading = null;

public static function title(): string;
public static function heading(): string;
```

| Piece | Declared as | Falls back to |
| --- | --- | --- |
| `title` | `$title` | `Str::headline(class_basename(static::class))` |
| `heading` | `$heading` | `title()` |
| `subheading` | `$subheading` | `null` |

So a page named `AuditLog` that declares nothing is titled and headed `Audit Log`.

```php
use PandaPanel\Pages\Page;

final class AuditLog extends Page
{
    protected static ?string $title = 'Audit log';

    // Separate on screen: the tab says what the page is, the heading says
    // what is on it.
    protected static ?string $heading = 'Recent activity';

    protected static ?string $subheading = 'Everything written in the last 30 days.';
}
```

There are `title()` and `heading()` accessors but **no `subheading()`**. A subheading that depends on runtime state is set by overriding `metadata()`:

```php
/**
 * @return array<string, mixed>
 */
protected function metadata(): array
{
    return [
        ...parent::metadata(),
        'subheading' => 'Last run '.$this->lastRunAt()->diffForHumans(),
    ];
}
```

`title()` and `heading()` are static and can be overridden the same way:

```php
public static function title(): string
{
    return 'Audit log — '.now()->year;
}
```

`$navigationLabel` falls back to `title()`, so overriding the title also renames the sidebar entry unless the page declares a label of its own. See [Navigation groups](../panels/navigation-groups.md).

## On a resource page

`PandaPanel\Resources\Pages\ResourcePage` declares the same three properties and resolves them through methods that are handed the record:

```php
public function getTitle(?Model $record = null): string;
public function getHeading(?Model $record = null): string;
public function getSubheading(?Model $record = null): ?string;

protected function defaultTitle(?Model $record): string;
protected function defaultHeading(?Model $record): string;
protected function defaultSubheading(?Model $record): ?string;

/** @return array{title: string, heading: string, subheading: string|null} */
protected function headingMetadata(?Model $record = null): array;
```

A declared static property always wins; otherwise the page's own default runs.

| Page | `title` | `heading` | `subheading` |
| --- | --- | --- | --- |
| `ListRecords` | `Resource::pluralLabel()` | follows the title | `null` |
| `CreateRecord` | `'New '.Resource::label()` | follows the title | `null` |
| `ViewRecord` | `Resource::recordTitle($record)` | follows the title | `Resource::label()` |
| `EditRecord` | `'Edit '.recordTitle($record)` | `recordTitle($record)` | `'Edit '.Resource::label()` |
| `ManageRelatedRecords` | `RelationManager::title()` | follows the title | `recordTitle($owner)` |

Read as text for a user named Ada Lovelace on a `UserResource`:

```php
(new ListUsers)   => ['Users',            'Users',         null]
(new CreateUser)  => ['New User',         'New User',      null]
(new ViewUser)    => ['Ada Lovelace',     'Ada Lovelace',  'User']
(new EditUser)    => ['Edit Ada Lovelace','Ada Lovelace',  'Edit User']
```

The edit page separates its title from its heading deliberately: repeating the verb in the breadcrumb, the heading and the tab reads as a mistake, so the heading is the record and the tab carries the verb.

### Declaring them

```php
use PandaPanel\Resources\Pages\ListRecords;

final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected static ?string $title = 'Team directory';

    protected static ?string $subheading = 'Everyone with an account.';
}
```

`heading` is not declared there, so it follows the *title* rather than the resource label: `Team directory` in the tab and on screen.

### Computing them from the record

Override the method rather than the property when the text depends on something a static cannot say:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Pages\EditRecord;

final class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected static ?string $heading = 'Account';

    public function getSubheading(?Model $record = null): ?string
    {
        return $record === null
            ? null
            : 'Editing '.$record->getAttribute('email');
    }
}
```

Every one of the three is called with `null` on a page that has no record — a create page, or a page resolved for its headings outside a request. A closure that dereferences `$record` unguarded fails there, which is why each built-in default begins with `$record === null ? … : …`.

```php
$page = new EditUser;

$page->getHeading();      // 'Account'
$page->getSubheading();   // null
```

## What crosses the wire

All three arrive in the `page` prop, alongside the breadcrumbs and the rest:

```php
[
    'title' => 'Settings',
    'heading' => 'Settings',
    'subheading' => 'Application-wide configuration.',
    'breadcrumbs' => [/* … */],
    'headerActions' => [],
    'scope' => 'page:settings',
    'cluster' => null,
]
```

The TypeScript mirror is `PageMetadata` in `resources/js/panel/types/page.ts`, normalized by `normalizePageMetadata()` — a missing `title` falls back to the heading rather than throwing, because a shape mismatch should degrade to a bare page instead of breaking the layout.

## Rendering

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '@/panel/components/PageHeader.vue';
import type { PageMetadata } from '@/panel/types/page';

defineProps<{ page: PageMetadata }>();
</script>

<template>
    <Head :title="page.title" />

    <PageHeader :heading="page.heading" :subheading="page.subheading" />
</template>
```

`PandaPanel`'s `PageHeader.vue` takes exactly two props:

```ts
defineProps<{
    heading: string;
    subheading?: string | null;
}>();
```

It draws the heading at `text-xl` rather than `text-2xl`, on the reasoning that the breadcrumb above already says where the user is — so the heading is a label rather than a title, and every pixel it does not take is a row of data on screen.

The `#actions` slot on the right is where header actions go:

```vue
<PageHeader :heading="page.heading" :subheading="page.subheading">
    <template #actions>
        <ActionButton
            v-for="action in page.headerActions as ActionDefinition[]"
            :key="action.name"
            :action="action"
            size="default"
        />
    </template>
</PageHeader>
```

Both built-in renderers already do this: `panel/Page` renders header actions in that slot, and `panel/Dashboard` renders the heading with no actions.

## Header actions on a standalone page

```php
/** @return list<array<string, mixed>> */
public function headerActions(): array;
```

Plain arrays shaped like the frontend's `ActionDefinition`:

```php
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Pages\Settings\ProfileSettings;

/**
 * @return list<array<string, mixed>>
 */
public function headerActions(): array
{
    return [[
        'name' => 'edit-profile',
        'label' => 'Edit profile',
        'icon' => 'settings',
        'variant' => ActionVariant::Default->value,
        'type' => 'link',
        'url' => ProfileSettings::url($this->panel()),
        'confirmation' => null,
    ]];
}
```

Keep them links. `ActionButton` renders a `link` action as an anchor and anything else as a button that emits `run`, and the generic page renderer listens for no such event. See [Actions](../actions/overview.md).

## Gotchas

- **`heading` follows `title`, not the label.** Declaring only `$title` on a resource page changes the on-screen heading too. Declare `$heading` as well when they should differ.
- **There is no `subheading()` accessor on `Page`.** Static text goes in `$subheading`; anything computed goes through `metadata()`.
- **`Str::headline()` is the default title, not the class name.** `AuditLog` becomes `Audit Log`, `APIKeys` becomes `A P I Keys`. Declare `$title` for an acronym.
- **`ViewRecord` and `EditRecord` call `Resource::recordTitle()`.** That reads `$recordTitleAttribute`, defaulting to `name`, and falls back to the primary key when the attribute is missing or not scalar. A page headed with an id means the resource has not named a usable title attribute.
- **The tab title is the page's, not the panel's.** The panel's brand name appears in the shell, not in `<Head>`; a page that wants both must render its own `<Head :title="…">`.

## See also

- [Custom pages](custom-pages.md)
- [Breadcrumbs](breadcrumbs.md)
- [Sub navigation](sub-navigation.md)
- [Resource pages](../resources/resource-pages.md)
- [Labels and navigation](../resources/labels-navigation.md)
- [Actions](../actions/overview.md)
- [Navigation groups](../panels/navigation-groups.md)
- [Server metadata to Vue](../concepts/metadata-to-vue.md)
