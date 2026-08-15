<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use PandaPanel\Actions\Action;
use PandaPanel\Actions\Support\Modal;
use PandaPanel\Exceptions\PanelSchemaException;
use PandaPanel\Forms\Components\TextInput;
use PandaPanel\Forms\FormSchema;
use PandaPanel\Forms\Layouts\Relationship;
use PandaPanel\Forms\Layouts\Section;
use PandaPanel\Resources\Resource;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Filters\SelectFilter;
use PandaPanel\Tables\TableSchema;
use PandaPanel\Widgets\Support\ColumnSpan;

/*
 * Mistakes a schema can make, and what it says about them.
 *
 * Every one of these used to be silent. A schema that cannot mean what it says
 * is not a style problem — two columns with one name fight over one key in the
 * row map, two fields with one name means one is submitted and discarded, and
 * two actions with one name means the endpoint always runs the first.
 *
 * The assertions are on the *message* as much as on the throw. A refusal that
 * does not say which name is wrong leaves somebody reading a resource with
 * forty columns.
 */

it('refuses two columns with the same name, and says which', function (): void {
    expect(fn () => TableSchema::make()->columns([
        TextColumn::make('name'),
        TextColumn::make('email'),
        TextColumn::make('name'),
    ]))
        ->toThrow(PanelSchemaException::class, 'more than one column named [name]');
});

it('names every duplicated column rather than only the first', function (): void {
    try {
        TableSchema::make()->columns([
            TextColumn::make('a'), TextColumn::make('a'),
            TextColumn::make('b'), TextColumn::make('b'),
        ]);
    } catch (PanelSchemaException $exception) {
        expect($exception->getMessage())->toContain('[a], [b]');
    }
});

it('refuses two form fields with the same name', function (): void {
    $schema = FormSchema::make()->schema([
        TextInput::make('email'),
        Section::make('More')->schema([TextInput::make('email')]),
    ]);

    // Nested, because that is the way it actually happens: nobody writes the
    // same field twice side by side.
    expect(fn () => $schema->validationRules())
        ->toThrow(PanelSchemaException::class, 'more than one field named [email]');
});

it('lets a relation group repeat a name the owner also uses', function (): void {
    // `profile.bio` and `bio` are two names. Checking before the relation
    // group namespaced its children would call them one and refuse a form
    // that is perfectly correct.
    $schema = FormSchema::make()->schema([
        TextInput::make('name'),
        Relationship::make('profile')->schema([
            TextInput::make('name'),
        ]),
    ]);

    expect(array_keys($schema->validationRules()))->toBe(['name', 'profile.name']);
});

it('refuses two actions with the same name in one set', function (): void {
    expect(fn () => TableSchema::make()->recordActions([
        Action::make('approve')->action(static fn () => null),
        Action::make('approve')->action(static fn () => null),
    ]))
        ->toThrow(PanelSchemaException::class, 'more than one action named [approve]');
});

it('names the set the duplicate is in', function (): void {
    // "Duplicate action" in a resource with four action sets is four places
    // to look.
    expect(fn () => TableSchema::make()->bulkActions([
        Action::make('delete')->bulkAction(static fn () => null),
        Action::make('delete')->bulkAction(static fn () => null),
    ]))
        ->toThrow(PanelSchemaException::class, 'The bulk actions declare');
});

it('allows the same action name in two different sets', function (): void {
    // A row action and a bulk action called `delete` are two different
    // endpoints, and naming them the same is the natural thing to do.
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->recordActions([Action::make('delete')->action(static fn () => null)])
        ->bulkActions([Action::make('delete')->bulkAction(static fn () => null)]);

    expect($schema->toArray()['bulkActions'])->toHaveCount(1);
});

it('refuses an action that would do nothing when pressed', function (): void {
    expect(fn () => TableSchema::make()->recordActions([Action::make('approve')]))
        ->toThrow(PanelSchemaException::class, 'The action [approve] does nothing');
});

it('accepts an action whose only job is to open a modal', function (): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->recordActions([
            Action::make('details')->modal(static function (Modal $modal): void {
                $modal->heading('Details');
            }),
        ]);

    // A modal is something happening. Refusing this would be the check being
    // wrong rather than the schema.
    expect($schema->getRecordAction('details'))->not->toBeNull();
});

it('refuses an empty name on a column, a field, and an action', function (): void {
    expect(fn () => TextColumn::make(''))
        ->toThrow(PanelSchemaException::class, 'A column was declared with an empty name')
        ->and(fn () => TextInput::make('  '))
        ->toThrow(PanelSchemaException::class, 'A field was declared with an empty name')
        ->and(fn () => Action::make(''))
        ->toThrow(PanelSchemaException::class, 'An action was declared with an empty name');
});

it('refuses an action name the endpoint could never match, and suggests one', function (): void {
    // It travels as an identifier and is matched against the schema, so a
    // space in it is a button that fails only when pressed.
    expect(fn () => Action::make('send invoice'))
        ->toThrow(PanelSchemaException::class, 'try [send-invoice]');
});

it('refuses a default sort on a column that does not exist, and lists the ones that do', function (): void {
    $schema = TableSchema::make()
        ->columns([TextColumn::make('name'), TextColumn::make('created_at')])
        ->defaultSort('createdAt');

    // The sort whitelist is the column list, so this would have fallen back to
    // the table's natural order with nothing to say why.
    expect(fn () => $schema->toArray())
        ->toThrow(PanelSchemaException::class, 'It has: name, created_at');
});

it('accepts a default sort on a column that does exist', function (): void {
    $definition = TableSchema::make()
        ->columns([TextColumn::make('created_at')])
        ->defaultSort('created_at')
        ->toArray();

    expect($definition['defaultSort']['column'])->toBe('created_at');
});

it('tells a resource that forgot its model what to add', function (): void {
    $resource = new class extends Resource
    {
        public static function table(TableSchema $table): TableSchema
        {
            return $table;
        }

        public static function form(FormSchema $schema): FormSchema
        {
            return $schema;
        }

        /** @return array<string, class-string> */
        public static function pages(): array
        {
            return [];
        }
    };

    // PHP's own answer names `Resource::$model` — this framework's class,
    // not the one that forgot — and does not say what to write.
    expect(fn () => $resource::getModel())
        ->toThrow(PanelSchemaException::class, 'protected static string $model = YourModel::class;');
});

/*
 * Round two: names and values that point at nothing
 */

it('refuses two filters with the same name', function (): void {
    // Filter state travels in the query string keyed by name, so the second
    // control writes over the first one's value.
    expect(fn () => TableSchema::make()
        ->columns([TextColumn::make('status')])
        ->filters([
            SelectFilter::make('status')->options(['a' => 'A']),
            SelectFilter::make('status')->options(['b' => 'B']),
        ]))
        ->toThrow(PanelSchemaException::class, 'more than one filter named [status]');
});

it('allows a filter on a column the table does not display', function (): void {
    // Legitimate and common: filtering by something that is not a column of
    // its own. Refusing this would be the check being wrong.
    $definition = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([SelectFilter::make('archived')->options(['1' => 'Yes'])])
        ->toArray();

    expect($definition['filters'])->toHaveCount(1);
});

it('names the widget whose span it cannot read', function (): void {
    // Without the class name, "a column span of [ful]" is a hunt through
    // every widget in the panel.
    expect(fn () => ColumnSpan::normalize('ful', 'App\\Panels\\Admin\\Widgets\\Revenue'))
        ->toThrow(PanelSchemaException::class, 'App\Panels\Admin\Widgets\Revenue declares');
});

it('says which breakpoints a widget grid actually has', function (): void {
    expect(fn () => ColumnSpan::normalize(['default' => 1, 'sm' => 2, 'xxl' => 4]))
        ->toThrow(PanelSchemaException::class, '[sm], [xxl]');
});

it('still clamps a number that is merely out of range', function (): void {
    // The distinction the refusal rests on: 99 is an ask, 'ful' is a typo.
    expect(ColumnSpan::normalize(99)['default'])->toBe(4)
        ->and(ColumnSpan::normalize(-5)['default'])->toBe(1);
});

it('tells a developer when an icon is missing rather than drawing nothing', function (): void {
    $registry = File::get(base_path('resources/js/panel/icons/registry.ts'));

    // The registry is generated by `panel:icons`, and forgetting to re-run it
    // after declaring a new icon was indistinguishable from declaring none:
    // both drew nothing.
    expect($registry)
        ->toContain('is not in the icon registry')
        ->toContain('php artisan panel:icons')
        // Development only. A console message on a live panel helps nobody,
        // and this is a build problem rather than a runtime one.
        ->toContain('import.meta.env.DEV')
        // Once per name, so one typo is one warning rather than one per row.
        ->toContain('missing.has(name)');
});
