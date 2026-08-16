# Events, Jobs and Controllers Reference

The moving parts behind a panel request: the fifteen controllers every panel route points at, the three queued jobs, the two broadcast events, and the nine pieces of middleware. Reach for this page when you need to know what an endpoint accepts, what a job carries, or which event to fake in a test.

## What a panel request touches

```text
request
  ↓  web middleware        ResetPanelContext → RedirectPanelHome → ShareFlashToast → SharePanelData
  ↓  panel route group     panel middleware, ResolvePanel:{id}, RequireTwoFactor, RequireEmailCode, [ResolveTenant]
  ↓  controller            a page class, or one of the framework's own controllers
  ↓  Inertia or JSON       shared props + page props
```

## Listening to a panel notification

The only event an application normally subscribes to:

```php
use Illuminate\Support\Facades\Event;
use PandaPanel\Notifications\PanelNotificationSent;

Event::listen(function (PanelNotificationSent $event): void {
    logger()->info('Notified', [
        'user' => $event->user->getAuthIdentifier(),
        'title' => $event->payload['title'] ?? null,
    ]);
});
```

In a test, fake it narrowly:

```php
Event::fake([PanelNotificationSent::class]);
```

Faking every event would silence the Eloquent model events the integrations feature relies on, which is why `PandaPanel\Testing\TestsNotifications::fake()` names one class.

## Events

Both implement `Illuminate\Contracts\Broadcasting\ShouldBroadcast`, both broadcast as `panel.notification`, and both use the private channel `App.Models.User.{id}` — Laravel's own default, so a notification broadcast by anything else in the application arrives on the same one.

### `PandaPanel\Notifications\PanelNotificationSent`

```php
public function __construct(
    public readonly Authenticatable $user,
    public readonly array $payload,
);

public function broadcastOn(): array;
public function broadcastAs(): string;    // 'panel.notification'
public function broadcastWith(): array;   // payload + message + persistent
```

Fired by `Notification::send()`. One event for both concerns: to the frontend, a message to show and a bell to increment are the same thing arriving.

### `PandaPanel\Broadcasting\PanelNotification`

```php
public function __construct(
    public readonly Authenticatable $user,
    public readonly string $message,
    public readonly string $type = 'info',   // 'success'|'info'|'warning'|'error'
    public readonly ?string $url = null,
    public readonly ?string $urlLabel = null,
);

public static function channelFor(Authenticatable $user): string;   // 'App.Models.User.{id}'
public function broadcastWith(): array;                             // {type, message, url, urlLabel}
```

A bare toast, for work that finishes long after the request that started it and has nothing to store.

### Eloquent events

Integrations are hung off the model's own events rather than off a panel screen, so a record written by a form, an action, an importer, a console command, or a queued job all fire them.

```php
PandaPanel\Integrations\IntegrationObserver::register(
    string $model,
    string $panelId,
    string $resourceSlug,
    Integrations $integrations,
): void;
```

Called from `PandaPanelServiceProvider` at boot for every resource whose `integrations()` opted in. A resource that has not opted in registers nothing, so the cost to everybody else is one method call per resource during boot.

What it deliberately does not catch: `Model::query()->update()` and `->delete()`, which never hydrate a model and therefore fire no events. That is a property of Eloquent; the panel itself never writes that way.

## Jobs

Three, all `ShouldQueue`, all carrying scalars and plain arrays rather than models — an Eloquent builder holds a closure and cannot be serialized, and a record that no longer exists cannot be reloaded.

| Job | `$tries` | Backoff | Dispatched by |
| --- | --- | --- | --- |
| `PandaPanel\Jobs\RunPanelExport` | 3 | `[10, 60]` | `ExportAction` above `Exporter::queueAfter()` |
| `PandaPanel\Jobs\RunPanelImport` | 1 | — | `ImportAction` above `Importer::queueAfter()` |
| `PandaPanel\Jobs\SendPanelIntegration` | 3 | see below | `IntegrationObserver` for an `after` trigger |

### `RunPanelExport`

```php
public function __construct(
    private readonly string $exporter,          // class-string<Exporter>
    private readonly string $resource,          // class-string<Resource>
    private readonly array $columns,            // list<string>
    private readonly SpreadsheetFormat $format,
    private readonly int|string $owner,
    private readonly array $tableState,         // the query string the list was showing
    private readonly ?array $keys,              // an explicit selection, or null for the whole list
    private readonly string $panelId,
);

public function handle(PanelManager $manager): void;
public function failed(?Throwable $exception): void;
public function backoff(): array;               // [10, 60]
```

It takes the *description* of a query rather than the query, and rebuilds it through the same `TableQuery` the list uses — which is what keeps the file honest: the rows in it are the rows that were on screen, filters and search included.

`handle()` binds the panel first (`$manager->setCurrentPanel($panel)`), because a resource's scope, its table, and its URLs are all read through the current panel.

Three tries is safe because an export only reads rows and writes a file: a run that failed halfway has changed nothing anybody can see, and the half-written file is replaced by the next attempt.

### `RunPanelImport`

```php
public function __construct(
    private readonly string $importer,   // class-string<Importer>
    private readonly string $path,
    private readonly array $mapping,     // array<string, int>
    private readonly int|string $owner,
    private readonly string $panelId,
);

public function handle(PanelManager $manager): void;
public function failed(?Throwable $exception): void;
```

One try, deliberately. An import writes rows; a run that failed halfway has already written some of them, and there is no general way to know which. Retrying would turn one bad import into two, and the second failure would look exactly like the first. So a failure is reported rather than retried: the user gets the report of what did land and re-uploads the rest.

The file is already on the disk by the time this runs — the upload put it there, and the job carries the path. A queue payload holding a spreadsheet would be a spreadsheet in the database. The upload is deleted afterwards; keeping it would accumulate copies of customer data nobody asked to store.

### `SendPanelIntegration`

```php
public function __construct(
    private readonly int $integrationId,
    private readonly array $payload,
    private readonly int $timeout,
    private readonly ?string $deliveryId = null,
);

public function handle(): void;
public function backoff(): array;
```

Only `after` triggers are queued. A `before` trigger describes the record as it is about to be written, and by the time a worker picked it up that state would be gone — so those are sent inline, with a short timeout and their failures swallowed.

The payload travels as an array rather than a serialized model, because `after_delete` is precisely the case where the record no longer exists and `SerializesModels` would try to reload it and throw.

`deliveryId` is assigned when the write happened, not when the worker runs, so all three attempts of one delivery carry the same id and a receiver can deduplicate them.

## Controllers

Every panel route points at a controller method, never a closure, so `route:cache` keeps working. Each one resolves the resource against *this panel's* registry and loads records through `Resource::query()`, so a resource or a record from elsewhere cannot be addressed.

### `PanelDashboardController`

`GET /` → `panel.{id}.dashboard`. Resolves `Panel::getDashboard()` and calls `render()` on it. Almost nothing happens here; the work belongs to the `Page` class the panel nominated.

### `PanelPageController`

`GET {routePath}` → `panel.{id}.pages.{slug}`. The page class arrives through the route's `defaults('page', $page)`, so the controller never resolves a class name from a request.

```php
public function __invoke(string $page): Response;   // (new $page)->render()
```

### `PanelActionController`

Six POST endpoints under `/actions`. All six validate a `resource` and look the action up in the schema that declared it.

| Method | Route name | Body | Aborts |
| --- | --- | --- | --- |
| `record()` | `actions.record` | `resource`, `action`, `record` | 404 unknown action, 400 not executable, 422 non-scalar key, 404 no record, 403 unauthorized |
| `infolist()` | `actions.infolist` | `resource`, `action`, `record` | the same, against `Resource::infolist()`'s whitelist |
| `bulk()` | `actions.bulk` | `resource`, `action`, `records` (1–500) | 404 unknown, 400 not executable, 403 unauthorized, 404 when any key is outside the scope |
| `table()` | `actions.table` | `resource`, `action` | 404 unknown, 400 not table-executable, 403 unauthorized |
| `reorder()` | `actions.reorder` | `resource`, `records` (1–500) | 400 not reorderable, 404 missing record, 403 per record |
| `cell()` | `actions.cell` | `resource`, `record`, `column`, `value` | 404 unknown column, 400 not editable, 403 unauthorized or disabled for that record |

A view page's actions are a different whitelist from a table's, which is why `infolist()` exists rather than a flag on `record()`: one lookup would let either page run the other's actions.

`bulk()` checks that every submitted key resolved. Keys outside the resource scope silently disappear from `findRecords()`, and the count check is what turns that into a visible failure rather than a partial bulk operation.

`reorder()` writes position-in-the-submitted-list to the column `TableSchema::reorderable()` named, in one transaction, after authorizing every record. Sending keys rather than positions keeps the client from inventing values for a column it knows nothing about.

`cell()` validates the value against the column's own `validationRules()` and refuses a cell the column reports as disabled *for that record* — a disabled control is not a permission.

Every one answers `back()->with('success', ...)`, so the result is a redirect carrying a flash toast.

### `PanelActionFormController`

```php
public function show(Request $request): JsonResponse;      // GET  actions.form
public function submit(Request $request): RedirectResponse; // POST actions.submit
```

`show()` takes `resource`, `action`, `scope` (`record`, `table`, `bulk`, or `infolist`), and an optional `record`, and answers the serialized `FormSchema`. `submit()` takes the same plus `records` (up to 500) and the form body.

Two requests rather than one, because a table shows one button per record: shipping a filled-in form beside every one of them would put twenty copies of the same schema on the wire to open at most one.

### `PanelRelationController`

Four endpoints under `/relations`, each resolving the resource, the relation manager, and the owner from the request and checking them against the resource's own `relationManagers()`.

| Method | Route name | Body |
| --- | --- | --- |
| `form()` | `relations.form` | context in the query string |
| `save()` | `relations.save` | the form body |
| `action()` | `relations.action` | `resource`, `record`, `relation`, `action`, `related` |
| `bulk()` | `relations.bulk` | `resource`, `record`, `relation`, `action`, `records` (1–500) |

Actions are resolved through `RelationTable::actionFor()` and `bulkActionFor()`, so a manager that is not declared on the resource cannot be addressed by a request that names it.

### `PanelFormOptionsController`

`GET /options` → `panel.{id}.options`. A searchable `Select` asks here for the rows its bounded first page could not show. The field is resolved out of the schema that declared it, so nothing about the query comes from the request.

### `PanelFormStateController`

`POST /form-state` → `panel.{id}.form-state`. Rebuilds a form after a `live()` field changed.

The resource slug and the page (`create` or `edit`) come from the query string; the typed values come from the body. Authorization — `canCreate()` or `canEdit($record)` — is asked *before* the schema is built, because building it runs the schema's own closures.

This is not a submit: nothing is validated and nothing is written. It answers what the form should *look* like now, which is what makes it safe to call on every keystroke of a live field.

### `PanelUploadController`

`POST /uploads` → `panel.{id}.uploads`. Body: `field` and `file`.

The resource is read from the **query string**, never from the body — the body is the form's values, and a field that happens to be named `resource` must not be able to point the upload at a different one. The disk, the directory, the accepted types, and the size all come from the `FileUpload` field's own declaration.

### `PanelExportController`

`GET /exports/{file}` → `panel.{id}.export-file`.

The request names a file and an `exporter` class in the query string. The directory is built from the authenticated user, so the only files reachable are the ones that user's own exports produced — a path traversal has nowhere to go because the caller never supplies a path. A name containing `/`, `\`, or `..` is a 404 whatever it would resolve to.

The route name is `export-file` rather than `exports` because it becomes an identifier in the generated Wayfinder module, and `exports` is not a name a TypeScript module can bind.

### `PanelImportController`

`GET /imports/{file}` → `panel.{id}.import-file`. The rows an import could not accept, filed the same way and reachable only by the user whose import produced them.

### `PanelSearchController`

`GET /search` → `panel.{id}.search`. Query: `q` (nullable, max 255). Answers `{groups: ...}` from `PandaPanel\Search\GlobalSearch`.

JSON rather than an Inertia page: the palette asks while the user is typing, and re-rendering the page they are on to answer would be absurd. It sits behind the panel's own middleware, so an unauthenticated request never reaches the search.

### `PanelNotificationController`

```php
public function index(Request $request): JsonResponse;   // notifications.index
public function read(Request $request): JsonResponse;    // notifications.read
public function clear(Request $request): JsonResponse;   // notifications.clear
```

Every query starts from `$request->user()`, so the scope *is* the authorization and none of them needs a policy. See [Notifications reference](notifications.md).

### `PanelAuthController`

The panel's own front door, registered outside the panel's auth middleware. Render-only — the forms post to Fortify's endpoints, because duplicating the login POST per panel would mean duplicating rate limiting, two-factor, passkeys, and session handling.

```php
public function login(Request $request): Response;                  // auth.login
public function register(): Response;                               // auth.register
public function requestPasswordReset(Request $request): Response;   // auth.password.request
public function resetPassword(Request $request): Response;          // auth.password.reset
public function verifyEmail(Request $request): Response;            // auth.verification.notice
```

Only `login` is unconditional; the other four are registered when the panel calls `registration()`, `passwordReset()`, or `emailVerification()`.

### `PanelTwoFactorController`

Five endpoints under `/two-factor`, inside the panel's middleware but exempt from the email-code check itself — answering it must not be refused by the thing being answered.

```php
public function challenge(Request $request): Response;        // auth.two-factor.challenge
public function send(Request $request): RedirectResponse;     // auth.two-factor.send
public function verify(Request $request): RedirectResponse;   // auth.two-factor.verify
public function enable(Request $request): RedirectResponse;   // auth.two-factor.enable  (RequirePassword)
public function disable(Request $request): RedirectResponse;  // auth.two-factor.disable (RequirePassword)
```

Turning the factor on or off is a change to the account, so both carry `Illuminate\Auth\Middleware\RequirePassword`.

### `PanelIntegrationController`

Registered per resource, and only for one that called `integrations()->isEnabled(true)`. A resource that did not registers nothing, so the URL 404s rather than answering 403 — there is no screen to be refused.

```php
public function index(Request $request, string $resource): Response;
public function store(Request $request, string $resource): RedirectResponse;
public function update(Request $request, string $resource, string $integration): RedirectResponse;
public function destroy(Request $request, string $resource, string $integration): RedirectResponse;
public function send(Request $request, string $resource, string $integration): JsonResponse;
public function rotate(Request $request, string $resource, string $integration): RedirectResponse;
```

Route names: `resources.{slug}.integrations`, plus `.store`, `.update`, `.destroy`, `.send`, `.rotate`. The slug travels as a route default rather than as a segment — the path is already inside the resource's prefix, and a second copy would be a second thing that could disagree with the first.

`{integration}` is a plain id looked up scoped to this panel and this resource, so an id from another screen cannot be edited here.

## Middleware

| Class | Alias | Where | What it does |
| --- | --- | --- | --- |
| `ResetPanelContext` | — | `web`, first | `PanelContext::forget()` at the start of every request |
| `RedirectPanelHome` | — | `web` | sends a signed-in user on `home_redirect.paths` into the first panel they can enter |
| `ShareFlashToast` | — | `web` | maps `error`/`warning`/`success`/`info` onto the `toast` channel |
| `SharePanelData` | — | `web` | shares `panel`, `navigation`, `panels`, `broadcasting`, `search`, `notifications`, `tenancy` |
| `ResolvePanel` | `panel` | panel group | binds the panel named by the route parameter, runs `Panel::boot()` |
| `RequireTwoFactor` | `panel.two-factor` | after `ResolvePanel` | enforces `Panel::requireTwoFactor()`; a passkey counts |
| `RequireEmailCode` | `panel.email-code` | after that | holds a session until the emailed code is answered |
| `ResolveTenant` | — | last, tenant panels only | identifies the tenant, checks the user, binds it |
| `ResolveParentRecord` | `panel.parent` | nested resource groups | binds the parent record every page of the group is scoped to |

Signatures:

```php
public function handle(Request $request, Closure $next): Response;                        // the four web ones
public function handle(Request $request, Closure $next, ?string $panelId = null): Response; // ResolvePanel, RequireTwoFactor, RequireEmailCode
public function handle(Request $request, Closure $next, string $panelId): Response;        // ResolveTenant
public function handle(Request $request, Closure $next, string $resource): Response;       // ResolveParentRecord
```

The four `web` middleware are appended by the service provider through the kernel, after `bootstrap/app.php` has configured the group — a package that pushed straight onto the router would have its middleware silently dropped. Set `panda-panel.register_web_middleware` to `false` to register them yourself.

The aliases exist for applications that want to reference them in their own route definitions; the registrar names the classes directly.

## Notes

- **Every route is a controller method, never a closure.** That is what keeps `route:cache` working, and it is why the resource pages are controllers too.
- **`ResolvePanel` boots the panel after the access check.** A user who is refused must not be able to trigger the panel's boot work.
- **A guest never reaches `ResolvePanel` on an authenticated panel.** `auth` redirects earlier; a signed-in user who is refused gets a 403 rather than a redirect, because hiding navigation is not an access control.
- **`RedirectPanelHome` is GET-only and never answers a JSON request.** An application that has hung an API or a form post off `/dashboard` keeps it.
- **Bulk endpoints cap the selection at 500 keys.** A request naming more is a validation failure, not a slow query.
- **A queued job binds its panel before touching a resource.** Without it, `Resource::query()` would answer for no panel and drop every per-panel scope.
- **`RunPanelImport` retries once and never more.** Replaying a partial write would turn one bad import into two.

## Rules every endpoint follows

- **The resource comes from the request and is resolved against this panel's registry.** A slug belonging to another panel resolves to nothing.
- **Records are loaded through `Resource::query()` (or `findRecord()`).** A key outside the resource scope resolves to nothing rather than to a record from elsewhere.
- **The action, column, or relation is looked up in the schema that declared it.** Anything the schema does not declare does not exist, however the request spells it.
- **Authorization is asked here, not inferred from the button having been rendered.**
- **Nothing resolves a class name from a request body.** Page classes arrive as route defaults; resource classes come from a slug lookup; component names are build-time registry keys.
- **Context that decides *what* is addressed comes from the query string, not the body.** The body is form values, and a field named `resource` must not be able to redirect the request.

## See also

- [Request lifecycle](../concepts/request-lifecycle.md)
- [Routing](../concepts/routing.md)
- [Server metadata to Vue](../concepts/metadata-to-vue.md)
- [Middleware configuration](../configuration/middleware.md)
- [Route registration](../configuration/routes.md)
- [Queued exports](../import-export/queued-exports.md)
- [Queued imports](../import-export/queued-imports.md)
- [Broadcasting](../notifications/broadcasting.md)
- [Core API reference](core.md)
- [Actions reference](actions.md)
- [Notifications reference](notifications.md)
- [Exceptions reference](exceptions.md)
