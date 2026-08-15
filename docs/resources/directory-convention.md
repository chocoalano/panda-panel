# Resource Directory Convention

Panda Panel finds resources by scanning a directory, so where a class lives decides which panel it belongs to. This page describes the layout the generators write and discovery expects — worth reading once before the first resource, and again when a resource grows an exporter or a relation manager.

## The shape

```bash
php artisan make:panel Admin
php artisan make:panel-resource Post --panel=Admin
```

Those two commands produce:

```text
app/Panels/Admin/
├── AdminPanelProvider.php
├── Pages/
│   └── .gitkeep
├── Resources/
│   └── Posts/
│       ├── PostResource.php
│       ├── Forms/
│       │   └── PostForm.php
│       ├── Pages/
│       │   ├── CreatePost.php
│       │   ├── EditPost.php
│       │   ├── ListPosts.php
│       │   └── ViewPost.php
│       └── Tables/
│           └── PostsTable.php
└── Widgets/
    └── .gitkeep
```

The `.gitkeep` files exist because discovery scans those directories and Git does not track an empty one: without them a fresh clone would point the provider at paths that vanished.

## The rules the directory follows

| Level | Convention | Where it comes from |
| --- | --- | --- |
| `app/Panels/{Panel}` | Studly panel name | `make:panel`, `PanelGeneratorCommand::panelName()` |
| `Resources/{Plural}` | Studly plural of the resource name | `make:panel-resource` |
| `{Class}Resource.php` | Studly singular plus `Resource` | `make:panel-resource` |
| `Pages/`, `Tables/`, `Forms/` | One class each, delegated to from the resource | `make:panel-resource` |
| `RelationManagers/` | Added when you generate one | `make:panel-relation-manager` |

`make:panel-resource User --panel=Admin` and `make:panel-resource Users --panel=Admin` write the same files: the name is singularised for the class and pluralised for the directory, so both spellings land in one place.

## Directories the generators add later

```bash
php artisan make:panel-relation-manager comments --panel=Admin --resource=Post
php artisan make:panel-relation-manager comments --panel=Admin --resource=Post --page
```

The first writes `app/Panels/Admin/Resources/Posts/RelationManagers/CommentsRelationManager.php`. With `--page` it also writes `app/Panels/Admin/Resources/Posts/Pages/ManagePostsComments.php`, a `ManageRelatedRecords` page beside the four standard ones.

Nothing is registered by being written there. The command says so on the way out: a relation manager is only reachable once it is named in `PostResource::relationManagers()`, and a page only once it is named in `PostResource::pages()`.

Anything else a resource needs — an exporter, an importer, an infolist class — is ordinary PHP with no convention the framework enforces. The example application uses:

```text
app/Panels/Admin/Resources/Users/
├── UserResource.php
├── Exports/UserExporter.php
├── Forms/UserForm.php
├── Imports/UserImporter.php
├── Infolists/UserInfolist.php
├── Pages/
└── Tables/
```

`UserResource::infolist()` calls `UserInfolist::configure()`, exactly as `table()` calls `UsersTable::configure()`. Those helper classes are found by the autoloader, never by discovery.

## How discovery reads the tree

```php
$panel
    ->discoverResources(app_path('Panels/Admin/Resources'))
    ->discoverPages(app_path('Panels/Admin/Pages'))
    ->discoverWidgets(app_path('Panels/Admin/Widgets'));
```

Each call takes one or more paths and merges with what is already declared. The scan then:

- Turns each file path into a class name through Composer's registered PSR-4 prefixes — `PandaPanel\Discovery\ClassResolver::forPath()` — rather than by parsing or evaluating the file. A path outside every PSR-4 root resolves to `null` and is skipped, because nothing could autoload it anyway.
- Keeps only concrete classes implementing the expected contract. `PostResource` is kept; `PostForm`, `PostsTable`, and the page classes in the same tree are not, because they are not resources. Abstract classes are skipped too, which is why `PandaPanel\Resources\Resource` itself is never discovered.
- Recurses, so `Resources/Posts/PostResource.php` is found by a scan of `Resources`.
- Sorts its results, so two machines produce the same manifest.
- Returns `[]` for a path that does not exist rather than failing.

Explicit registration still works and merges without duplicating:

```php
$panel
    ->resources([PostResource::class])
    ->discoverResources(app_path('Panels/Admin/Resources'));
```

Registering a class that discovery also finds leaves one registration, not two.

## Living outside the convention

The convention is the generators' opinion, not a constraint of the framework. A resource in a module package, a domain directory, or anywhere else works the same way, as long as one of the two is true:

```php
// Point discovery at it,
$panel->discoverResources(base_path('modules/Blog/Panel/Resources'));

// or name the class.
$panel->resources([\Modules\Blog\Panel\PostResource::class]);
```

Both require the path to be under a registered PSR-4 root; discovery has no other way to name the class.

## Frontend paths

Resource pages render framework components — `panel/resources/Index`, `Create`, `View`, `Edit`, `ManageRelated` — which are published into the application rather than imported from the package, because every component registry in the frontend is an `import.meta.glob` over the application's own tree.

| Path | Default | Config key |
| --- | --- | --- |
| Panel components | `resources/js/panel` | `panda-panel.frontend.panel_path` |
| Generated page and widget components | `resources/js/pages/Panels` | `panda-panel.frontend.pages_path` |

```php
'frontend' => [
    'panel_path' => 'js/panel',
    'pages_path' => 'js/pages/Panels',
],
```

Both are relative to `resources/`, and both are read through `PandaPanel\Support\FrontendPaths` so the publisher, the generators, and the icon command cannot spell them differently. Moving `pages_path` means changing the frontend's glob to match.

## Customising what the generators write

```bash
php artisan vendor:publish --tag=panda-panel-stubs
```

That copies the package's stubs to `stubs/panel/` in the application. A published stub always wins over the package's own, so editing `stubs/panel/resource.stub` changes every resource generated from then on. The stubs are:

| Stub | Written by |
| --- | --- |
| `resource.stub` | `make:panel-resource` |
| `resource-page.stub` | `make:panel-resource`, once per page |
| `resource-table.stub` | `make:panel-resource` |
| `resource-form.stub` | `make:panel-resource` |
| `relation-manager.stub` | `make:panel-relation-manager` |
| `relation-page.stub` | `make:panel-relation-manager --page` |
| `panel-provider.stub` | `make:panel` |
| `page.stub`, `page-component.stub` | `make:panel-page` |
| `widget-stats.stub`, `widget-table.stub`, `widget-chart.stub`, `widget-custom.stub`, `widget-component.stub` | `make:panel-widget` |

## Notes

- **Directory placement decides panel membership only through discovery.** A resource under `Panels/Admin/Resources` is in the Admin panel because that panel scans that path, not because of the namespace. Two panels may scan the same path deliberately.
- **A file in the tree that is not a resource is not an error.** The scan filters by contract, which is why the tables, forms, and pages sitting beside a resource are ignored rather than rejected.
- **Generators never overwrite.** A file that exists is skipped with a warning naming it; `--force` overwrites. A run where everything was skipped exits with a failure status, so a scripted regeneration does not look successful.
- **`php artisan panel:cache` freezes the discovered class list.** After adding a resource in production, rebuild the manifest or discovery will not run to notice it.

## See also

- [Creating resources](creating-resources.md)
- [Resource pages](resource-pages.md)
- [make:panel-resource](../cli/make-panel-resource.md)
- [make:panel-relation-manager](../cli/make-panel-relation-manager.md)
- [Discovery](../concepts/discovery.md)
- [Caching](../concepts/caching.md)
- [Directory structure](../getting-started/directory-structure.md)
- [Frontend paths](../configuration/frontend-paths.md)
- [Relation managers](../relations/relation-managers.md)
