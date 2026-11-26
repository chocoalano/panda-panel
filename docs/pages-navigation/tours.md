# Page Tours

A tour is a few steps of information or tutorial over a screen: what this page is for, where to start, what a column means. It is optional. Every page and resource answers `null` until you say otherwise, and a page with no tour draws nothing.

The first time somebody opens a toured page the tour starts by itself. After that it opens only from the **?** button beside the page heading.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Users;

use PandaPanel\Resources\Resource;
use PandaPanel\Tours\Tour;
use PandaPanel\Tours\TourStep;

final class UserResource extends Resource
{
    // …

    public static function tour(string $page): ?Tour
    {
        return match ($page) {
            'index' => Tour::make()->steps([
                TourStep::make('Your team')
                    ->description('Everyone who can sign in is listed here.'),
                TourStep::make('Find someone')
                    ->description('Search reads the name and the email address.')
                    ->region('table'),
                TourStep::make('Add a colleague')
                    ->action('create'),
            ]),
            default => null,
        };
    }
}
```

`$page` is the resource page key, the same one `getHeaderWidgets()` receives: `index`, `create`, `view`, `edit`, or `relation:{key}` for a dedicated relation page.

## On a page

A resource page asks its resource. Override `tour()` on the page when the tour depends on something only the page knows, such as the record:

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Resources\Pages\EditRecord;
use PandaPanel\Tours\Tour;
use PandaPanel\Tours\TourStep;

final class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    public function tour(?Model $record = null): ?Tour
    {
        if ($record?->getAttribute('is_admin') !== true) {
            return null;
        }

        return Tour::make()->steps([
            TourStep::make('This is an administrator')
                ->description('Changing the email address changes who can administer the panel.')
                ->field('email'),
        ]);
    }
}
```

A standalone page, a dashboard included, declares its own:

```php
use PandaPanel\Pages\Page;
use PandaPanel\Tours\Tour;
use PandaPanel\Tours\TourStep;

final class Reports extends Page
{
    public function tour(): ?Tour
    {
        return Tour::make()->steps([
            TourStep::make(__('tours.reports.title'))
                ->description(__('tours.reports.body'))
                ->region('widget'),
        ]);
    }
}
```

The sentences are yours, so translate them here. Only the buttons come from the package's `lang/{locale}/frontend.php`.

## What a step can point at

| Method | Points at |
| --- | --- |
| `field('email')` | the form field of that name |
| `column('name')` | the table column's header |
| `action('create')` | the first button on screen for that action |
| `region('table')` | a part of the shell: `shell`, `sidebar`, `topbar`, `page`, `page-header`, `table`, `table-row`, `form`, `infolist`, `widget`, `modal` |
| `selector('#export')` | any CSS selector |
| nothing | a dialog in the middle of the screen |

A step that points at something is a popover against it, with the rest of the page dimmed. `side()` says which side it prefers; it flips when there is no room:

```php
use PandaPanel\Enums\TourSide;

TourStep::make('Filters')->region('table')->side(TourSide::Top);
```

When the target is not on screen — a column the reader hid, the card layout (which has no column headers), an action inside a closed menu, a field their role does not see — the step is shown in the middle of the screen instead. It is not skipped.

An unknown `region()` raises `InvalidArgumentException`. The other methods cannot be checked on the server, so a misspelled name shows a centred step.

## Showing a tour again

"Seen" is remembered in the reader's browser (`localStorage`), per panel, per user, and per tour key. Nothing is stored on the server, so the same person on another device sees the tour once more.

The key defaults to the screen: `resource:{slug}.{page}` or `page:{slug}`. When you rewrite a tour and want everybody to see it again, name a new one:

```php
Tour::make('users-index-v2')->steps([/* … */]);
```

Two screens given the same key share one memory: seeing either counts as both.

Where the browser blocks site data nothing can be remembered, so the tour does not open by itself; the button still works.

## Conditional steps

Steps are an ordinary array, so leave out what does not apply. A tour with no steps left is no tour:

```php
Tour::make()->steps(array_filter([
    TourStep::make('Your team'),
    UserResource::canCreate() ? TourStep::make('Add a colleague')->action('create') : null,
]));
```

## Custom pages and components

A custom `ResourcePage` builds its own metadata, so it spreads the tour in, and names its own page key so it does not take the index page's:

```php
protected static string $page = 'audit';

// inside the metadata array
...$this->tourMetadata($record),
```

The button and the tour are drawn by `PageHeader.vue`. A custom Vue page has them when it renders that component. To make your own element a target, give it any class or attribute and use `selector()`.

## Upgrading

The tour is part of the published frontend. After updating the package run:

```bash
php artisan panel:assets --update
npm run build
```

## See also

- [Page Headings](headings.md)
- [Custom Pages](custom-pages.md)
- [Frontend assets](../frontend/assets.md)
