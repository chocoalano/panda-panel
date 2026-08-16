# App Panel Example

The second panel in [`examples/`](../../examples/): an end-user area at `/app` that any authenticated, verified user may enter. It registers none of the Admin panel's resources, which is what makes panel isolation something you can point at rather than something you hope for. Read this page when an application needs a customer-facing or member-facing panel alongside an administrative one.

## A minimal working example

```bash
php artisan make:panel App
```

```php
// config/panda-panel.php

'panels' => [
    App\Panels\Admin\AdminPanelProvider::class,
    App\Panels\App\AppPanelProvider::class,
],
```

Two panels, two URL prefixes, two registries. Nothing is shared between them except the application underneath.

## The example provider, in full

`examples/app/Panels/App/AppPanelProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Panels\App;

use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('app')
            ->name('Application')
            ->brandName((string) config('app.name'))
            ->icon('layout-grid')
            ->auth()
            ->navigationGroups([
                'Account',
            ])
            ->discoverResources(app_path('Panels/App/Resources'))
            ->discoverPages(app_path('Panels/App/Pages'))
            ->discoverWidgets(app_path('Panels/App/Widgets'));
    }
}
```

There is no `canAccess()`. That is the whole difference in access terms: `auth()` requires a signed-in, verified user and nothing more, so every account that can sign in can open `/app`. The Admin panel adds a predicate on top of the same middleware.

`App\Models\User::canAccessPanel()` still applies — it is asked for *every* panel, and in the examples it requires a verified email. A rule about the account belongs there; a rule about one panel belongs in that panel's `canAccess()`.

## What it discovers

`Panels/App/Resources` is empty apart from a `.gitkeep`. The panel finds one page and one widget.

### The page

`examples/app/Panels/App/Pages/Profile.php` is a standalone `PandaPanel\Pages\Page` — no model, no table, no records, and still the panel layout, navigation, breadcrumbs, and authorization.

```php
final class Profile extends Page
{
    protected static ?string $title = 'Account overview';

    protected static ?string $subheading = 'Your account details.';

    protected static ?string $slug = 'profile';

    protected static string $component = 'Panels/App/Pages/Profile';

    protected static ?string $navigationIcon = 'user';

    protected static string|BackedEnum|null $navigationGroup = 'Account';

    protected static int $navigationSort = 5;

    /** @return array<string, mixed> */
    public function props(): array
    {
        $user = Auth::user();

        return [
            'profile' => $user instanceof User ? [
                'name' => $user->name,
                'email' => $user->email,
                'verified' => $user->email_verified_at !== null,
                'joined' => $user->created_at?->format('M j, Y'),
            ] : null,
        ];
    }

    /** @return list<array<string, mixed>> */
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
}
```

Two things are worth copying from it.

`props()` returns scalars, never the model. What crosses to Vue is a description, and a serialized Eloquent model would carry whatever happened to be loaded.

The header action links to the panel's own built-in profile settings page rather than duplicating an edit form. `PandaPanel\Pages\Settings\ProfileSettings::url($panel)` produces `/app/settings/profile`, so editing never leaves the shell the user is already in.

The Vue side is `examples/resources/js/pages/Panels/App/Pages/Profile.vue`. A page needs no Vue file at all — leaving `$component` at its default (`panel/Page`) renders the generic shell. Set it when the page has something bespoke to draw.

### The widget

`examples/app/Panels/App/Widgets/AccountSummary.php` is a `StatsWidget` scoped to the signed-in user rather than to the users table:

```php
final class AccountSummary extends StatsWidget
{
    protected static int $sort = 10;

    protected static int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'lg' => 3, 'xl' => 4];

    public static function canView(): bool
    {
        return Auth::user() instanceof User;
    }

    /** @return list<Stat> */
    public function stats(): array
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        return [
            Stat::make('Signed in as', $user->name)->icon('user'),

            Stat::make('Email', $user->email_verified_at === null ? 'Unverified' : 'Verified')
                ->icon('mail')
                ->color($user->email_verified_at === null ? StatColor::Warning : StatColor::Success),

            Stat::make('Member since', $user->created_at?->format('M Y') ?? '—')->icon('receipt'),
        ];
    }
}
```

`canView()` is checked **before** `data()` runs, so a widget the user may not see never executes its queries. Here it is belt and braces behind the panel's `auth()` middleware, which is the right shape for a widget that might be reused somewhere less protected.

Nothing in this widget counts other users. That is the App panel's job description, stated in code.

## The navigation it produces

```
Account
  Account overview   /app/profile
  Profile            /app/settings/profile
  Security           /app/settings/security
  Appearance         /app/settings/appearance
```

`tests/Feature/Panel/AppPanelExampleTest.php` asserts exactly that list, in that order. Everything in it came from a discovered class or from the panel's built-in settings pages; there is no hardcoded navigation array anywhere in the framework.

## The built-in settings pages

Every panel gets three account pages in its own shell and under its own path.

| Page | Path | Notes |
| --- | --- | --- |
| `PandaPanel\Pages\Settings\ProfileSettings` | `/app/settings/profile` | name and email |
| `PandaPanel\Pages\Settings\SecuritySettings` | `/app/settings/security` | password, two-factor, passkeys; behind `RequirePassword` |
| `PandaPanel\Pages\Settings\AppearanceSettings` | `/app/settings/appearance` | theme, entirely client-side |

They render only. Writing still goes to the application's own `ProfileController` and `SecurityController`, which redirect `back()` — so there is one place a profile is updated no matter which panel the form was submitted from.

Turn them off for a panel that has no business showing them:

```php
$panel->settings(false);
```

The starter kit's `/settings/*` URLs are kept as aliases and redirect into the first panel the user can enter, so existing links and Wayfinder output keep working.

## What keeps the two panels isolated

Panels key their registries independently, and asking for a URL in a panel that does not register the resource **throws**:

```php
use App\Panels\Admin\Resources\Users\UserResource;

UserResource::url();               // fine inside /admin
UserResource::url(panel: 'app');   // PanelRegistrationException
```

`tests/Feature/Panel/PanelIsolationTest.php` is that guarantee written down. It is not a convention: `Resource::assertRegisteredIn()` runs on every `url()` call, which is what makes isolation provable rather than accidental.

Three further consequences of the same rule:

- The App panel's routes are a different group with a different middleware stack. A resource route that was never registered is a 404, not a 403.
- The action endpoints (`panel.app.actions.record` and friends) resolve the resource slug against **this panel's** registry, so a payload naming `users` from `/app` finds nothing.
- Global search asks each resource `canViewAny()` before querying it, and only searches resources this panel registered.

## Sharing one resource between two panels

When a class genuinely belongs in both, register it in both and configure it per panel rather than subclassing to change a label:

```php
use PandaPanel\Resources\ResourceConfiguration;

$panel->resources([
    ResourceConfiguration::for(UserResource::class)
        ->slug('people')
        ->pluralLabel('People')
        ->navigationLabel('Directory')
        ->navigationGroup('Account')
        ->modifyQueryUsing(fn (Builder $query) => $query->whereKey(auth()->id())),
]);
```

`ResourceConfiguration` methods, all fluent and all optional:

| Method | Signature |
| --- | --- |
| `for` | `public static function for(string $resource): self` |
| `slug` | `public function slug(string $slug): self` |
| `label` | `public function label(string $label): self` |
| `pluralLabel` | `public function pluralLabel(string $pluralLabel): self` |
| `navigationLabel` | `public function navigationLabel(string $navigationLabel): self` |
| `navigationGroup` | `public function navigationGroup(?string $navigationGroup): self` |
| `navigationIcon` | `public function navigationIcon(?string $navigationIcon): self` |
| `navigationSort` | `public function navigationSort(int $navigationSort): self` |
| `registerNavigation` | `public function registerNavigation(bool $register = true): self` |
| `modifyQueryUsing` | `public function modifyQueryUsing(Closure $callback): self` |

`modifyQueryUsing()` narrows `Resource::query()`, and every read goes through that one query — list, view, edit, delete, bulk, action lookup, global search. A record the panel may not reach is a **404**, not a filtered row.

Note what this deliberately is not: a way to register one class twice inside a single panel. A panel keys resources by slug, and two registrations of one class would make `Resource::url()` ambiguous.

## Gotchas

- **A panel with no `canAccess()` is open to every signed-in user.** That is usually right for an app panel and never right for an admin one. Add the predicate deliberately, not by default.
- **`->auth()` accumulates.** It appends `auth` and `verified` to the auth middleware rather than replacing the stack; `->auth(verified: false)` appends only `auth`.
- **An empty resources directory is fine.** Discovery over a directory with nothing in it registers nothing and costs one scan, which the panel manifest removes entirely.
- **Two panels can be mounted on different domains.** `->domain('app.example.com')` keeps a panel off other hosts; without it both panels answer on every host the application does.
- **The settings pages are per panel, not global.** Turning them off in one panel leaves them in the other.

## See also

- [Admin Panel Example](admin-panel.md) — the administrative panel this one sits beside
- [Multiple Panels](../panels/multi-panel.md)
- [Per-Panel Resource Configuration](../resources/per-panel-configuration.md)
- [Built-in Settings Pages](../panels/settings-pages.md)
- [Custom Pages](../pages-navigation/custom-pages.md)
- [Widgets Overview](../widgets/overview.md)
- [Panel Access](../panels/access.md)
- [Authorization](../concepts/authorization.md)
