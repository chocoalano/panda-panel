# Actions in Infolists

An infolist can offer operations beside what it shows: approve the order, resend the invitation, verify this address. They are the same `PandaPanel\Actions\Action` a table row or a page header uses, so a thing that can be done to a record is described once however it is reached.

You reach for one when a view page should be able to *do* something, rather than link to an edit form that does it.

## A minimal set of actions

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Section;

public static function infolist(InfolistSchema $schema): InfolistSchema
{
    return $schema
        ->actions([                                     // the record as a whole
            Action::make('approve')
                ->icon('check')
                ->requiresConfirmation()
                ->successMessage('Order approved.')
                ->action(static fn (Model $record) => $record->approve()),
        ])
        ->schema([
            Section::make('Invitation')
                ->headerActions([                       // this group of entries
                    Action::make('resend')
                        ->label('Resend')
                        ->icon('mail')
                        ->action(static fn (Model $record) => $record->sendInvitation()),
                ])
                ->schema([
                    TextEntry::make('email')->action(   // one value
                        Action::make('verify')
                            ->icon('check')
                            ->action(static fn (Model $record) => $record->markEmailAsVerified()),
                    ),
                ]),
        ]);
}
```

## The three places an action can sit

| Where | Declared with | Rendered |
| --- | --- | --- |
| The record as a whole | `InfolistSchema::actions()` | A row of buttons above the infolist |
| A group of entries | `Section::headerActions()` | In the section's header, right-aligned |
| One value | `Entry::action()` | A small icon button beside the entry's label |

The placement is the meaning. "Approve this order" is about the record, so it sits above everything; "resend invitation" belongs beside the invitation details; "verify" belongs beside the address it verifies.

### `InfolistSchema::actions()`

```php
public function actions(array $actions): self      // array<array-key, Action>
```

Serialized against the record, so an action the user may not run is absent rather than a button that answers 403:

```php
$schema->actions([
    Action::make('approve')->authorize(static fn (?Model $record): bool => auth()->user()?->can('approve', $record) === true),
    Action::make('cancel')->visible(static fn (?Model $record): bool => $record?->status === 'open'),
]);
```

### `Section::headerActions()`

```php
public function headerActions(array $actions): self       // array<array-key, Action>
public function getHeaderActions(): list<Action>
```

```php
Section::make('Invitation')
    ->headerActions([Action::make('resend')->icon('mail')->action(...)])
    ->schema([TextEntry::make('email')]);
```

### `Entry::action()`

```php
public function action(Action $action): static
public function getAction(): ?Action
```

One action per entry — the setter replaces rather than appends. It renders as an `icon-sm` button next to the label, so give it an `icon()`:

```php
TextEntry::make('api_key')->action(
    Action::make('rotate')
        ->icon('rotate-ccw')
        ->requiresConfirmation(
            heading: 'Rotate this key?',
            description: 'Anything using the old key stops working immediately.',
            button: 'Rotate it',
        )
        ->action(static fn (Model $record) => $record->rotateApiKey()),
);
```

## The whitelist

`InfolistSchema::allActions()` collects every action the schema declares, keyed by name:

```php
$schema = InfolistSchema::make()
    ->actions([Action::make('approve')])
    ->schema([
        Section::make('Details')
            ->headerActions([Action::make('resend')])
            ->schema([TextEntry::make('name')->action(Action::make('rename'))]),
    ]);

array_keys($schema->allActions());        // ['approve', 'resend', 'rename']
$schema->getAction('resend');             // the Action, or null
```

That map is what `PandaPanel\Http\Controllers\PanelActionController::infolist()` resolves against. An action that is not in it does not exist, however the request spells it.

It is a *different* whitelist from the table's. A view page's actions come from `Resource::infolist()`, and looking them up in the table schema would mean an action shown on one page could be run from the other. That is why there is a separate endpoint rather than a flag on the existing one.

Names are the key, so two actions sharing a name in one infolist are one action — the last one declared wins. A record action and an infolist action *may* share a name, because they are looked up in different scopes.

### Actions inside a dialog

An action registered on another action's modal is reachable through its parent:

```php
$schema->actions([
    Action::make('approve')->registerModalActions([Action::make('explain')]),
]);

$schema->getAction('explain');      // not null
```

`allActions()` walks one level of `getModalActions()` after collecting the declared ones, and only adds a nested name that is not already taken — registering it on the parent is what made it reachable.

## How one runs

The frontend composable is `useInfolistActions` in `resources/js/panel/composables/useInfolistActions.ts`. Pressing a button posts to the panel's infolist endpoint:

```text
POST /{panel}/actions/infolist       route name: panel.{id}.actions.infolist
{ "resource": "users", "action": "approve", "record": 42 }
```

The URL is handed to the page as `actionEndpoints.infolist` by `ResourcePage::actionEndpoints()`, so no panel URL is ever built in Vue.

The request carries a name, a slug, and a key. It never carries anything executable. The server then, in order:

| Step | Failure |
| --- | --- |
| Validates `resource`, `action`, `record` as present | 422 |
| Resolves the resource against *this* panel's registry | 404 |
| Binds the parent record when the resource is nested | 404 |
| Looks the action up in `Resource::infolist()` | 404 `Unknown action.` |
| Checks `isExecutable()` — an `action()` handler was declared | 400 `This action cannot be executed.` |
| Checks the record key is a string or int | 422 `Invalid record key.` |
| Finds the record with `Resource::findRecord()` | 404 |
| Checks `isAuthorizedFor($record)` again | 403 |
| Runs `execute($record)` | — |

Then `back()->with('success', $action->getSuccessMessage())`, which the panel turns into a toast.

Authorization is asked twice on purpose: once when the page was rendered, to decide whether to draw the button at all, and again here — describing an operation and performing it are two separate moments in time.

## Actions that carry a form

An action with a `schema()` opens a dialog, and the form is fetched when the dialog opens rather than shipped with the page:

```php
use PandaPanel\Actions\Enums\ModalWidth;
use PandaPanel\Forms\Components\Textarea;
use PandaPanel\Forms\FormSchema;

Action::make('note')
    ->label('Add a note')
    ->icon('pencil')
    ->modalHeading('Note about this account')
    ->modalSubmitLabel('Save note')
    ->modalWidth(ModalWidth::Large)
    ->slideOver()
    ->successMessage('Note saved.')
    ->schema(static fn (?Model $record): FormSchema => FormSchema::make()->schema([
        Textarea::make('note')->rows(6)->required()->maxLength(1000),
    ]))
    ->action(static function (Model $record, array $data): void {
        logger()->info('Panel note', ['user' => $record->getKey(), 'note' => $data['note']]);
    });
```

Two more requests, both carrying `scope=infolist`:

```text
GET  /{panel}/actions/form?resource=users&action=note&scope=infolist&record=42
POST /{panel}/actions/form   { resource, action, scope: 'infolist', record, ...form data }
```

`scope` is an allowlist of `record`, `table`, `bulk`, `infolist`, and it selects which schema the action is looked up in — so a form for an infolist action is resolved out of `Resource::infolist()` and nowhere else.

The submitted data is validated and dehydrated by the action's own `FormSchema` before the handler sees it, so an extra key in the request body is discarded exactly as it is on a resource form. A file field inside that form uploads through an endpoint authorized by *the action*, not by the resource: an action the user may not run must not be a way to put a file on a disk.

See [Action forms](../actions/forms.md) and [Modals](../actions/modals.md).

## What is offered, and what is not

`Action::toArray($record)` returns null when the action is hidden or unauthorized for this record, and both `Entry::toArray()` and `Section::toArray()` filter those out. So the page never draws a button that would answer 403.

Two more conditions apply only inside an infolist:

```php
'action' => $record->exists ? $this->action?->toArray($record) : null,
```

- An entry inside a **repeatable** carries no action. A wrapped `InfolistRow` has no key, and an action pointing at one would name a record the endpoint could never find.
- An entry on an **unsaved** model carries none either, for the same reason.

## Authorization

Two closures, and they mean different things:

| Method | Signature | Meaning |
| --- | --- | --- |
| `visible()` | `visible(Closure $callback): static` — `Closure(?Model): bool` | Whether this operation applies to this record at all |
| `authorize()` | `authorize(Closure $callback): static` — `Closure(?Model): bool` | Whether this user may perform it |

```php
Action::make('resendVerification')
    ->visible(static fn (?Model $record): bool => $record?->email_verified_at === null)
    ->authorize(static fn (?Model $record): bool => auth()->user()?->is_admin === true);
```

`visible()` is not checked again by the endpoint; `authorize()` is. Use `authorize()` for anything that is a permission — `visible()` alone hides a button, and a hidden button is not a lock.

## Testing

`panelInfolistActions()` is `PandaPanel\Testing\TestsActions::infolist()`, scoped to the infolist whitelist. Every lookup goes through `Resource::infolist()`, the same schema the controller resolves against, so an action the helper can find is one the endpoint can find:

```php
use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;

it('offers the note action to an administrator only', function (): void {
    $record = User::factory()->create();

    panelInfolistActions(UserResource::class)
        ->assertExists('note')
        ->assertDoesNotExist('purgeUnverified')     // that one is a table action
        ->assertVisible('note', $record);

    $this->actingAs(User::factory()->create(['is_admin' => false]));

    panelInfolistActions(UserResource::class)->assertCanNotRun('note', $record);
});
```

| Method | Signature | Asserts |
| --- | --- | --- |
| `find()` | `find(string $name): ?Action` | Nothing; returns the action or null |
| `assertExists()` | `assertExists(string $name): self` | The infolist declares it |
| `assertDoesNotExist()` | `assertDoesNotExist(string $name): self` | It does not |
| `assertVisible()` | `assertVisible(string $name, ?Model $record = null): self` | Visible *and* authorized for this record |
| `assertHidden()` | `assertHidden(string $name, ?Model $record = null): self` | Absent, or not offered for this record |
| `assertCanRun()` | `assertCanRun(string $name, ?Model $record = null): self` | `isAuthorizedFor()` is true |
| `assertCanNotRun()` | `assertCanNotRun(string $name, ?Model $record = null): self` | It is false |
| `call()` | `call(string $name, ?Model $record = null, array $data = []): self` | Authorizes first, then runs it |

`call()` checks authorization and fails the test rather than skipping it. A helper that ran an action the user may not run would prove the handler works and nothing about whether it is reachable.

## Gotchas

- **A section nested inside a tab declares header actions the endpoint cannot find.** `allActions()` walks the schema's *top-level* components for sections. A nested section still renders its buttons, but pressing one answers 404. Put the action on the schema with `actions()`, or on an entry, when the section is not top-level.
- **An action with no `action()` handler answers 400.** `isExecutable()` is `handleUsing !== null`. A link action — one with `url()` — is navigated by the browser and never posts here at all.
- **The infolist endpoint requires a record key.** `record` is a required field there, so an action that operates on nothing belongs on a table or a page header. The *form* endpoint accepts a null record, and then requires a `tableAction()` handler rather than an `action()` one — reaching `executeWithoutRecord()` from a view page is possible and almost never what was meant.
- **Deletion has no page hooks.** It runs through the action endpoint, which executes without a page instance. Use `Action::before()` and `Action::after()`.
- **The success message is a flash, not a return value.** `back()->with('success', ...)` is what the panel turns into a toast, so a handler that returns a response has it discarded.
- **Nested modal actions are collected one level deep.** An action registered on an action registered on an action is not in `allActions()`.

## See also

- [InfolistSchema basics](overview.md)
- [Entries](entries.md)
- [Layouts](layouts.md)
- [Repeatable entries](repeatable-entries.md)
- [Actions overview](../actions/overview.md)
- [Infolist actions](../actions/infolist-actions.md)
- [Action forms](../actions/forms.md)
- [Modals](../actions/modals.md)
- [Action authorization](../actions/authorization.md)
- [Action scopes](../actions/scopes.md)
- [Testing actions](../testing/actions.md)
