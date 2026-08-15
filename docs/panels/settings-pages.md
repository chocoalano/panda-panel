# Settings Pages

Every panel ships three account pages — profile, security, and appearance —
under its own path and inside its own shell. They are ordinary `Page` classes,
so they authorize, appear in navigation, and carry breadcrumbs exactly as a
page you wrote would. You reach for this page to turn them off, to keep an
existing application's `/settings/*` URLs working, or to write a settings page
of your own.

## A minimal working example

There is nothing to register. A panel that says nothing about settings has all
three:

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin;

use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelProvider;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->path('admin')->auth();
    }
}
```

```bash
curl -I https://example.test/admin/settings/profile   # 200 for a signed-in admin
```

A panel that has no business showing them — a kiosk, a single-purpose reporting
panel — turns them off:

```php
$panel->settings(false);
```

## The three pages

All three live in `PandaPanel\Pages\Settings`.

| Class | Title | Slug | Path | Inertia component |
| --- | --- | --- | --- | --- |
| `ProfileSettings` | Profile | `settings-profile` | `{panel}/settings/profile` | `panel/settings/Profile` |
| `SecuritySettings` | Security | `settings-security` | `{panel}/settings/security` | `panel/settings/Security` |
| `AppearanceSettings` | Appearance | `settings-appearance` | `{panel}/settings/appearance` | `panel/settings/Appearance` |

Navigation and route details:

| Class | Icon | Group | Sort | Route middleware |
| --- | --- | --- | --- | --- |
| `ProfileSettings` | `user` | `Account` | `10` | — |
| `SecuritySettings` | `shield` | `Account` | `20` | `Illuminate\Auth\Middleware\RequirePassword` |
| `AppearanceSettings` | `palette` | `Account` | `30` | — |

The slug is one segment while the path is two. That split is deliberate: the
slug is the route name and the registry key, and `routePath()` is what the
address bar shows.

```php
public static function routePath(): string   // 'settings/profile'
```

Route names follow the panel's own prefix, so URLs are asked for rather than
written:

```php
use PandaPanel\Pages\Settings\ProfileSettings;
use PandaPanel\Pages\Settings\SecuritySettings;

ProfileSettings::routeName('admin');   // 'panel.admin.pages.settings-profile'
ProfileSettings::url('admin');         // '/admin/settings/profile'
ProfileSettings::url('app');           // '/app/settings/profile'
SecuritySettings::url('admin');        // '/admin/settings/security'
```

Both accept `Panel|string|null`. With `null` they resolve the panel for the
current request, and throw outside a panel:

```php
public static function routeName(Panel|string|null $panel = null): string
public static function url(Panel|string|null $panel = null): string
```

Every panel gets its own copies. Three panels means three routes to the profile
page, each rendering inside its own shell, theme, and navigation.

## Turning them on and off

```php
public function settings(bool $settings = true): self
public function hasSettings(): bool
```

The pages join the panel's own page list rather than being special-cased
elsewhere, so discovery, caching, and route registration treat them like any
other page:

```php
Panel::make('kiosk')->settings(false)->getPages();   // []
Panel::make('other')->getPages();
// [ProfileSettings::class, SecuritySettings::class, AppearanceSettings::class]
```

`getPages()` puts them first and merges your explicitly registered pages after
them, deduplicated by class.

## What each page ships

The pages render; they do not write. That is the whole design: there remains
exactly one place that updates a profile no matter which shell the form was
submitted from.

**`ProfileSettings`** sends two props and posts to the application's own
`ProfileController`:

```php
public function props(): array
{
    return [
        'mustVerifyEmail' => Auth::user() instanceof MustVerifyEmail,
        'status' => session('status'),
    ];
}
```

**`SecuritySettings`** mirrors the props the application's security screen
builds, so the two-factor and passkey components need no panel-specific branch:

| Prop | Type | Source |
| --- | --- | --- |
| `canManageTwoFactor` | `bool` | `Features::canManageTwoFactorAuthentication()` |
| `canManagePasskeys` | `bool` | `Features::canManagePasskeys()` |
| `passkeys` | `array` | The user's passkeys, `id`, `name`, `authenticator`, and two humanized dates |
| `passwordRules` | `string` | `PandaPanel\Support\PasswordRules::attribute()`, the browser `passwordrules` value built from `Password::defaults()` |
| `emailCodeEnabled` | `bool` | `PandaPanel\Auth\EmailCodeFactor::isEnabledFor($user)` |
| `emailCodeUrls` | `array{enable: string, disable: string}` | The panel's own two-factor routes |
| `twoFactorEnabled` | `bool` | Present only when Fortify's two-factor feature is on |
| `requiresConfirmation` | `bool` | Present only when Fortify's two-factor feature is on |

The two URLs are the panel's own, named under its prefix:

```php
route($this->panel()->routeName('auth.two-factor.enable'), absolute: false);
route($this->panel()->routeName('auth.two-factor.disable'), absolute: false);
```

Both are behind `RequirePassword`, like the page itself. The panel also
registers `auth.two-factor.challenge`, `.send` and `.verify` for answering an
emailed code — see [Email Code Challenge](../authentication/email-code-challenge.md).

**`AppearanceSettings`** ships no props at all. The theme choice is held in
local storage and a cookie by `useAppearance`, so there is no server state to
send and nothing to save.

## Navigation

All three declare `Account` as their navigation group and sort 10, 20, 30, so
they render in that order under one heading. A panel that wants the group in a
particular place in the sidebar declares it:

```php
$panel->navigationGroups([
    'User Management',
    'System',
    'Account',
]);
```

A group the panel never declares is still rendered — declaring one is about
order, not existence.

## Keeping an existing application's settings URLs

A Laravel starter kit already answers `/settings/profile` and friends. Once the
panel owns those screens, keep the addresses as aliases rather than as a second
implementation, so bookmarks and generated links still resolve. The package
ships this as an example rather than as behaviour, because it is the
application's routing decision:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use PandaPanel\Core\PanelManager;
use PandaPanel\Pages\Page;

final class SettingsRedirectController
{
    /**
     * @param  class-string<Page>  $page
     */
    private function toPanel(Request $request, string $page): RedirectResponse
    {
        $panel = app(PanelManager::class)->firstAccessibleTo($request->user());

        abort_if($panel === null || ! $panel->hasSettings(), 403);

        return redirect($page::url($panel));
    }
}
```

The full example is in
`examples/app/Http/Controllers/Settings/SettingsRedirectController.php`, wired
up in `examples/routes/web.php`. Two details are worth copying: the GET routes
redirect while the PATCH route still points at the application's own
`ProfileController` — the screen moved into the panel, the write did not — and
`hasSettings()` is checked, so a redirect into a panel that turned settings off
is a refusal rather than a 404.

## Customizing the screens

The Vue components are published into the application by `panel:install`, or by
hand:

```bash
php artisan vendor:publish --tag=panda-panel-assets
```

They land at `resources/js/pages/panel/settings/Profile.vue`, `Security.vue`
and `Appearance.vue` and are yours from then on: in your repository, in your
build, editable. The cost of that is that a package update cannot improve them
silently, which is what `panel:assets` exists to report:

```bash
php artisan panel:assets           # which published files are out of date
```

## Writing your own settings page

A settings page is a standalone `Page` with a nested `routePath()`. Nothing
about it is special:

```bash
php artisan make:panel-page BillingSettings --panel=Admin --component
```

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Pages;

use BackedEnum;
use PandaPanel\Pages\Page;

final class BillingSettings extends Page
{
    protected static ?string $title = 'Billing';

    protected static ?string $subheading = 'Plan, invoices, and payment method.';

    protected static ?string $slug = 'settings-billing';

    protected static string $component = 'Panels/Admin/Pages/BillingSettings';

    protected static ?string $navigationIcon = 'receipt';

    protected static string|BackedEnum|null $navigationGroup = 'Account';

    protected static int $navigationSort = 40;

    /**
     * One segment as a slug, two in the address bar — the same split the
     * built-in settings pages use.
     */
    public static function routePath(): string
    {
        return 'settings/billing';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-billing') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function props(): array
    {
        return ['plan' => 'pro'];
    }
}
```

The pieces this uses, each of which the base class defines:

| Member | Signature or type | Purpose |
| --- | --- | --- |
| `$title` | `?string` | Falls back to a headline of the class name |
| `$heading` | `?string` | Falls back to `title()` |
| `$subheading` | `?string` | The line under the heading |
| `$slug` | `?string` | Route name and registry key; falls back to a kebab-cased class name |
| `$component` | `string` | Defaults to `panel/Page`, the generic renderer |
| `$navigationIcon`, `$activeNavigationIcon` | `?string` | Icon registry keys |
| `$navigationGroup` | `string\|BackedEnum\|null` | The sidebar heading |
| `$navigationSort` | `int` | Order within the group |
| `$shouldRegisterNavigation` | `bool` | `false` hides it from the sidebar; the route stays |
| `$middleware` | `list<string>` | Appended to this page's route |
| `routePath()` | `static function (): string` | The URL, defaulting to the slug |
| `canAccess()` | `static function (): bool` | Enforced by the route, not only by navigation |
| `props()` | `function (): array<string, mixed>` | Serializable page props |
| `widgets()` | `function (): list<class-string<Widget>>` | Widgets to render on the page |
| `breadcrumbs()` | `function (): list<Breadcrumb>` | Defaults to dashboard → group → title |
| `headerActions()` | `function (): list<array<string, mixed>>` | Buttons beside the heading |

Leaving `$component` alone renders the generic page shell, which is enough for
a page whose content is widgets or a list of values. Set it when the page has a
form to draw.

A page registers a **GET** route and nothing else. A settings page that saves
posts to a route of your own — which is what the built-in profile page does:

```php
public function headerActions(): array
{
    return [[
        'name' => 'manage-plan',
        'label' => 'Manage plan',
        'icon' => 'link',
        'variant' => 'default',
        'type' => 'link',
        'url' => route('billing.portal'),
        'confirmation' => null,
    ]];
}
```

## Replacing a built-in page

Page slugs are unique within a panel. Registering your own page with the slug
`settings-profile` while the built-ins are on throws
`PanelRegistrationException::duplicatePageSlug()` at boot — loudly, because two
pages claiming one route is a mistake rather than an override. Turn the
built-ins off first, then register all the account pages you want:

```php
$panel
    ->settings(false)
    ->pages([
        App\Panels\Admin\Pages\ProfileSettings::class,
        App\Panels\Admin\Pages\BillingSettings::class,
    ]);
```

`settings(false)` is all-or-nothing: it removes the three together. Replacing
one and keeping two means registering the two you kept yourself.

## Notes

- **The routes are GET only.** A POST to `/admin/settings/profile` answers 405.
  The write endpoints belong to the application and to Fortify.
- **The security page redirects, it does not refuse.** `RequirePassword` is on
  the route rather than in `canAccess()`, because a stale session has to reach
  the confirmation screen and `canAccess()` can only answer yes or no. An
  unconfirmed session is redirected to `route('password.confirm')`.
- **Panel access is checked first.** A user the panel refuses gets a 403 on
  `/admin/settings/profile` just as on any other page of it, and a guest is
  redirected to the login — the panel's own when it has one.
- **`settings(false)` removes the routes.** `ProfileSettings::url($panel)` then
  has no route to build from and throws, which is why the redirect example
  checks `hasSettings()` before calling it.
- **Appearance is per device, not per user.** It writes local storage and a
  cookie and stores nothing server-side, so the choice does not follow a user
  to another browser.
- **The security page degrades with Fortify's features.** `twoFactorEnabled`
  and `requiresConfirmation` are absent when two-factor is off, and `passkeys`
  is empty when the user model is not a `PasskeyUser`. The page still renders.
- The behaviour above is pinned by `tests/Feature/Panel/PanelSettingsTest.php`.

## See also

- [Custom Pages](../pages-navigation/custom-pages.md)
- [Page URLs and Route Names](../pages-navigation/urls.md)
- [Page Authorization](../pages-navigation/authorization.md)
- [Navigation Groups](navigation-groups.md)
- [Profile](../authentication/profile.md)
- [Security](../authentication/security.md)
- [Appearance](../authentication/appearance.md)
- [Two-Factor Authentication](../authentication/two-factor.md)
- [make:panel-page](../cli/make-panel-page.md)
- [Publish Tags](../cli/publish-tags.md)
- [Updating Assets](../frontend/updating-assets.md)
