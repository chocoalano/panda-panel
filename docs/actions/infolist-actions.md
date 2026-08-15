# Infolist Actions

An infolist action is an operation offered on a record's view page. You reach for one when the page that shows a record should also be where something is done to it — approving an order, resending a verification email, adding a note.

They are the same `PandaPanel\Actions\Action` a table row uses, so a thing that can be done to a record is described once however it is reached. What differs is the whitelist: a view page's actions are declared by `Resource::infolist()`, and they are resolved through their own endpoint rather than the table's.

## A minimal working example

```php
<?php

declare(strict_types=1);

namespace App\Panels\Admin\Resources\Orders\Infolists;

use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Enums\ActionVariant;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Section;

final class OrderInfolist
{
    public static function configure(InfolistSchema $schema): InfolistSchema
    {
        return $schema
            ->columns(2)
            ->actions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('check')
                    ->variant(ActionVariant::Outline)
                    ->requiresConfirmation(
                        heading: 'Approve this order?',
                        description: 'The customer is notified immediately.',
                        button: 'Approve',
                    )
                    ->successMessage('Order approved.')
                    ->visible(static fn (?Model $record): bool => $record?->getAttribute('status') === 'pending')
                    ->authorize(static fn (?Model $record): bool => $record !== null
                        && auth()->user()?->can('update', $record) === true)
                    ->action(static fn (Order $record) => $record->approve()),
            ])
            ->schema([
                Section::make('Order')->schema([
                    TextEntry::make('reference'),
                    TextEntry::make('status'),
                ]),
            ]);
    }
}
```

The button sits above the infolist, which is where "approve this order" belongs — it is about the record, not about one of its columns.

## Three places an action can sit

```php
InfolistSchema::actions(array $actions): self          // above the whole infolist
Section::headerActions(array $actions): self           // beside a group of entries
Entry::action(Action $action): static                  // beside one value
```

```php
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Section;

InfolistSchema::make()
    ->actions([
        Action::make('note')->label('Add a note'),
    ])
    ->schema([
        Section::make('Identity')
            ->headerActions([
                Action::make('resendVerification')
                    ->label('Resend verification')
                    ->icon('mail')
                    ->requiresConfirmation(
                        heading: 'Send a verification email?',
                        description: 'The account will be asked to confirm its address again.',
                        button: 'Send it',
                    )
                    ->successMessage('Verification email sent.')
                    ->visible(static fn (?Model $record): bool => $record !== null
                        && $record->getAttribute('email_verified_at') === null)
                    ->action(static function (Model $record): void {
                        if ($record instanceof User) {
                            $record->sendEmailVerificationNotification();
                        }
                    }),
            ])
            ->schema([
                TextEntry::make('name'),
                TextEntry::make('email')->action(
                    Action::make('copyEmail')->label('Copy'),
                ),
            ]),
    ]);
```

| Placement | Reads as | Serialized in |
| --- | --- | --- |
| `InfolistSchema::actions()` | about the record as a whole | `toArray()['actions']` |
| `Section::headerActions()` | about this group of entries — "resend invitation" beside the invitation details | the section's own payload |
| `Entry::action()` | about this one value | the entry's `action` key |

All three are resolved against the record being viewed, so an action the user may not run is absent rather than a button that answers 403.

## The whitelist

```php
InfolistSchema::allActions(): array          // array<string, Action>
InfolistSchema::getAction(string $name): ?Action
```

`allActions()` collects, keyed by name:

1. the schema's own `actions()`;
2. every `Section`'s `headerActions()`;
3. every entry's `action()`;
4. the `registerModalActions()` of all of the above.

That is the whitelist the endpoint resolves against: an action that is not in there does not exist, however the request spells it. Header actions, section actions, and entry actions are all reachable because all three were declared by this schema — and nothing else is.

Point 4 is a real difference from a table. `TableSchema::getRecordAction()` does not walk registered modal actions, so an action reachable only from inside a table action's dialog is not addressable by name. `InfolistSchema::allActions()` does walk them, because a dialog on a view page is a place actions genuinely run from. They are still reachable only *through* their parent in the UI — registering them is what put them in the lookup.

Later declarations win on a name collision: sections overwrite the schema's own, entries overwrite sections, and a nested modal action is added with `??=` so it never displaces one already found.

## Actions with a form

```php
use Illuminate\Database\Eloquent\Model;
use PandaPanel\Actions\Action;
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
        Textarea::make('note')->label('Note')->rows(6)->required()->maxLength(1000),
    ]))
    ->action(static function (Model $record, array $data): void {
        $record->notes()->create(['body' => $data['note']]);
    });
```

The dialog fetches its form from `GET {panel}/actions/form` with `scope=infolist` and submits to `POST {panel}/actions/form` with the same scope. Unlike a table action, `$record` is never null here — a view page is always about one record. See [Action forms](forms.md).

## The endpoint

```text
POST {panel}/actions/infolist      route name: panel.{panelId}.actions.infolist
```

```json
{ "resource": "orders", "action": "approve", "record": 42 }
```

| Key | Required | Meaning |
| --- | --- | --- |
| `resource` | yes | resource slug, resolved against this panel's registry |
| `action` | yes | the action's name |
| `record` | yes | the record key |
| `parent` | for a nested resource | the parent record's key |

`PanelActionController::infolist()` then:

1. resolves the panel and the resource, or 404;
2. binds the parent for a nested resource;
3. builds `Resource::infolist(InfolistSchema::make())` and calls `getAction($name)`, or 404;
4. checks `isExecutable()`, or 400 — a link action has nothing to post;
5. resolves the record with `Resource::findRecord()`, or 404;
6. asks `isAuthorizedFor($record)`, or 403;
7. runs `execute()` and redirects back with the success message.

This is a separate endpoint from `actions/record` on purpose. A view page's actions are declared by `Resource::infolist()`, and looking them up in the table schema would mean an action shown on one page could be run from the other.

## What crosses the wire

`InfolistSchema::toArray(Model $record)` returns:

```php
[
    'columns' => 2,
    'schema' => [/* sections, tabs, entries */],
    'actions' => [
        ['name' => 'approve', 'label' => 'Approve', 'icon' => 'check', 'variant' => 'outline',
         'type' => 'callback', 'url' => null, 'formUrl' => null, 'hasForm' => false,
         'modal' => null, 'modalActions' => [], 'confirmation' => [/* … */]],
    ],
]
```

Each action goes through `Action::toArray($record)`, which returns `null` for one that is hidden or unauthorized, and the list drops it.

An entry serializes its action under `action`, and only when `$record->exists`:

```php
'action' => $record->exists ? $this->action?->toArray($record) : null,
```

Inside a repeatable entry the "record" is a wrapped row with no key, and an action pointing at it would name a record the endpoint could never find.

## Notes

- **A link action works here too.** `ViewAction` and `EditAction` are ordinary links, so putting `EditAction::make(OrderResource::class)` in `actions()` gives the view page an edit button. Posting one to the infolist endpoint is a 400.
- **`visible()` and `authorize()` still receive `?Model`.** The infolist always has a record, but the same closures may be reused on a table where they do not — guard the null.
- **Bulk and table handlers are meaningless here.** The endpoint calls `execute()`, so an action needs `action()`. `isTableExecutable()` and `isBulkExecutable()` are never consulted in this scope.
- **A name collision is resolved by last-one-wins, not by an exception.** `allActions()` is a keyed array rather than a validated set, unlike `TableSchema::recordActions()` which throws. Two actions called `approve` in one infolist means only one of them is ever run.
- **Entry actions are not table column actions.** They look similar but resolve through the infolist whitelist and the infolist endpoint.
- **Success messages flash the same way.** The endpoint redirects back with `success`, which the panel renders as a toast.

## See also

- [Action basics](overview.md)
- [Action scopes](scopes.md)
- [Action forms](forms.md) and [Action modals](modals.md)
- [Action authorization](authorization.md)
- [CRUD actions](crud-actions.md)
- [Infolist basics](../infolists/overview.md)
- [Infolist entries](../infolists/entries.md)
- [Infolist layouts](../infolists/layouts.md)
- [Infolist actions reference](../infolists/actions.md)
- [Resource pages](../resources/resource-pages.md)
