<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PandaPanel\Exceptions\PanelSchemaException;
use PandaPanel\Forms\Components\Repeater;
use PandaPanel\Forms\Components\Select;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Section;
use PandaPanel\Forms\Layouts\Tab;
use PandaPanel\Forms\Layouts\Tabs;
use PandaPanel\Forms\Support\FieldPaths;
use PandaPanel\Forms\Support\SchemaDiagnostics;
use PandaPanel\Support\FrontendContract;
use PandaPanel\Support\FrontendPaths;

/*
|--------------------------------------------------------------------------
| Schemas that contradict themselves, and drift that hides
|--------------------------------------------------------------------------
|
| Three mistakes that produce a form which looks entirely correct and behaves
| inexplicably, which is the worst kind to have:
|
| - two fields writing to one place, so one of them is rendered, filled in,
|   submitted and discarded without a word;
| - a field that is required on a page where the browser will not submit it,
|   so an unrelated edit fails on a greyed-out box;
| - a published frontend a protocol behind the backend, so a feature is simply
|   absent and nothing is logged.
|
| None of the three is visible from the symptom. All three are cheap to notice
| at the moment the schema is compiled or the request is served.
|
*/

/*
 * Session 7 — a field's address, not its name
 */

it('addresses a field by where it writes rather than by what it is called', function (): void {
    $paths = FieldPaths::collect([
        TextInput::make('name'),
        Section::make('Details')->schema([TextInput::make('note')]),
        Repeater::make('items')->schema([TextInput::make('title')]),
    ]);

    // A layout is presentation and adds no segment; a repeater holds a list
    // and its children live one wildcard deeper.
    expect(array_column($paths, 'path'))
        ->toBe(['name', 'note', 'items', 'items.*.title']);
});

it('refuses two fields with one name at the top level, as it always has', function (): void {
    $schema = FormSchema::make()->schema([
        TextInput::make('work_day'),
        TextInput::make('work_day'),
    ]);

    expect(fn () => $schema->validationRules())
        ->toThrow(PanelSchemaException::class, 'work_day');
});

it('finds a duplicate the name check cannot see, inside one repeater', function (): void {
    $schema = FormSchema::make()->schema([
        Repeater::make('items')->schema([
            TextInput::make('title'),
            TextInput::make('title'),
        ]),
    ]);

    // Flattened, this schema has one name — the repeater's. The collision is
    // only visible as an address.
    expect(fn () => $schema->validationRules())
        ->toThrow(PanelSchemaException::class, 'items.*.title');
});

it('allows one name in two scopes, which is not a collision', function (): void {
    $schema = FormSchema::make()->schema([
        TextInput::make('title'),
        Repeater::make('items')->schema([TextInput::make('title')]),
        Repeater::make('others')->schema([TextInput::make('title')]),
    ]);

    // `title`, `items.*.title` and `others.*.title` are three places.
    expect($schema->validationRules())->toBeArray();
});

it('does not read a repeater\'s own entries as duplicates of each other', function (): void {
    $schema = FormSchema::make()->schema([
        Repeater::make('items')->schema([
            TextInput::make('title'),
            TextInput::make('note'),
        ]),
    ]);

    expect($schema->validationRules())->toBeArray();
});

it('treats two tabs as two places in one form, not two scopes', function (): void {
    $schema = FormSchema::make()->schema([
        Tabs::make([
            Tab::make('One')->schema([TextInput::make('shared')]),
            Tab::make('Two')->schema([TextInput::make('shared')]),
        ]),
    ]);

    // A tab set is presentation. Both fields submit under `shared`, and only
    // one of them survives.
    expect(fn () => $schema->validationRules())
        ->toThrow(PanelSchemaException::class);
});

it('names the address and the component that holds it', function (): void {
    $schema = FormSchema::make()->schema([
        Repeater::make('items')->schema([
            TextInput::make('title'),
            TextInput::make('title'),
        ]),
    ]);

    try {
        $schema->validationRules();
    } catch (PanelSchemaException $exception) {
        expect($exception->getMessage())
            ->toContain('items.*.title')
            ->toContain('items');

        return;
    }

    $this->fail('Expected a schema exception.');
});

/*
 * Session 8 — required and disabled cannot both be true and both be meant
 */

it('surfaces a field that is required on a page where it is disabled', function (): void {
    $schema = FormSchema::make()
        ->forPage('edit')
        ->schema([
            TextInput::make('code')->required()->disabledOn(['edit']),
        ]);

    // The rule asks for a value the browser will not send, so an edit to some
    // other field fails on a box nobody can type into.
    expect(fn () => $schema->validationRules())
        ->toThrow(PanelSchemaException::class, 'code');
});

it('leaves a disabled field alone on the page where it is writable', function (): void {
    $schema = FormSchema::make()
        ->forPage('create')
        ->schema([
            TextInput::make('code')->required()->disabledOn(['edit']),
        ]);

    expect($schema->validationRules())->toHaveKey('code');
});

it('allows a disabled field that carries state the server supplies', function (): void {
    $schema = FormSchema::make()
        ->forPage('edit')
        ->schema([
            // `disabled` does not mean `required = false`: a value the server
            // put there can legitimately be demanded. Declining to dehydrate
            // is how a field says it takes no part in the exchange.
            TextInput::make('token')->required()->disabled()->dehydrated(false),
        ]);

    expect($schema->validationRules())->toHaveKey('token');
});

it('asks for a value only on the pages the field is writable on', function (): void {
    $create = FormSchema::make()
        ->forPage('create')
        ->schema([TextInput::make('code')->requiredOn(['create'])->disabledOn(['edit'])]);

    $edit = FormSchema::make()
        ->forPage('edit')
        ->schema([TextInput::make('code')->requiredOn(['create'])->disabledOn(['edit'])]);

    expect($create->validationRules()['code'])->toContain('required')
        ->and($edit->validationRules()['code'])->toContain('nullable');
});

it('tells the browser the same thing it tells the validator', function (): void {
    $edit = FormSchema::make()
        ->forPage('edit')
        ->schema([TextInput::make('code')->requiredOn(['create'])]);

    $field = $edit->toArray()['schema'][0];

    // Derived from one declaration, so the asterisk and the rule cannot
    // disagree about the same field.
    expect($field['required'])->toBeFalse()
        ->and($field['validation']['required'])->toBeFalse();
});

/*
 * How loudly, and where
 */

it('throws while a developer is looking', function (): void {
    expect(SchemaDiagnostics::mode())->toBe('throw');
});

it('logs rather than throwing where a user is', function (): void {
    config()->set('panda-panel.forms.diagnostics', 'log');

    Log::spy();

    $schema = FormSchema::make()->schema([
        Repeater::make('items')->schema([
            TextInput::make('title'),
            TextInput::make('title'),
        ]),
    ]);

    // A schema that has been quietly wrong in production for a year should not
    // start failing to render because the framework learned to notice.
    expect($schema->validationRules())->toBeArray();

    Log::shouldHaveReceived('warning')->once();
});

it('can be told to say nothing at all', function (): void {
    config()->set('panda-panel.forms.diagnostics', 'ignore');

    $schema = FormSchema::make()->schema([
        Repeater::make('items')->schema([
            TextInput::make('title'),
            TextInput::make('title'),
        ]),
    ]);

    expect($schema->validationRules())->toBeArray();
});

/*
 * Session 9 — a frontend that is a protocol behind
 */

it('reads the version the published frontend declares', function (): void {
    // This repository is its own test application, so the "published" copy is
    // the package's own file — which is exactly the current version.
    expect(FrontendContract::published())->toBe(FrontendContract::VERSION)
        ->and(FrontendContract::isDrifted())->toBeFalse();
});

it('detects a published frontend that is behind', function (): void {
    $path = FrontendPaths::panel('contract.ts');
    $original = File::get($path);

    try {
        File::put($path, 'export const PANEL_CONTRACT_VERSION = 1;');

        expect(FrontendContract::published())->toBe(1)
            ->and(FrontendContract::isDrifted())->toBeTrue();
    } finally {
        File::put($path, $original);
    }
});

it('says nothing about a frontend it cannot read a version from', function (): void {
    $path = FrontendPaths::panel('contract.ts');
    $original = File::get($path);

    try {
        File::put($path, '// nothing recognisable here');

        // "Cannot tell" is not "mismatched", and warning about a file this
        // cannot parse would be noise.
        expect(FrontendContract::published())->toBeNull()
            ->and(FrontendContract::isDrifted())->toBeFalse();
    } finally {
        File::put($path, $original);
    }
});

it('names the command that fixes it', function (): void {
    expect(FrontendContract::remediation())->toBe('php artisan panel:assets --update');
});

it('reports the drift from panel:assets, with what to run', function (): void {
    $path = FrontendPaths::panel('contract.ts');
    $original = File::get($path);

    try {
        File::put($path, 'export const PANEL_CONTRACT_VERSION = 1;');

        $this->artisan('panel:assets')
            ->expectsOutputToContain('contract v1')
            ->assertSuccessful();
    } finally {
        File::put($path, $original);
    }
});

it('does not warn about a frontend that is ahead', function (): void {
    $path = FrontendPaths::panel('contract.ts');
    $original = File::get($path);

    try {
        File::put($path, 'export const PANEL_CONTRACT_VERSION = 999;');

        // Only happens while somebody is developing the package itself, and is
        // not a state an application can reach by updating.
        expect(FrontendContract::isDrifted())->toBeFalse();
    } finally {
        File::put($path, $original);
    }
});

/*
 * A dependent select still validates against the database, not its own page
 */

it('leaves a callback-backed select to validate through existsIn', function (): void {
    $schema = FormSchema::make()->schema([
        Select::make('employee_id')
            ->searchable()
            ->existsIn('fixture_tasks', 'id')
            ->optionsUsing(static fn (): array => ['1' => 'One']),
    ]);

    // The shown list is one bounded page, so membership of it is not validity
    // — the same rule a relation-backed select follows.
    expect($schema->validationRules()['employee_id'])->not->toBeEmpty();
});
