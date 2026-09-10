<?php

declare(strict_types=1);

use PandaPanel\Forms\Enums\CalloutTone;
use PandaPanel\Forms\Layouts\Callout;
use PandaPanel\Tables\Columns\BooleanColumn;
use PandaPanel\Tables\Columns\CustomColumn;
use PandaPanel\Tables\Columns\DateColumn;
use PandaPanel\Tables\Columns\IconColumn;
use PandaPanel\Tables\Columns\ImageColumn;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Filters\Constraints\TextConstraint;
use PandaPanel\Tables\Filters\QueryBuilderFilter;
use PandaPanel\Tables\TableSchema;

/**
 * The columns a table shows are the things it can be filtered by.
 *
 * A query builder used to take its own list of `Constraint` objects, declared
 * separately from the columns. Two lists describing the same table is two
 * lists that drift: a column added to one and not the other is a column the
 * reader can see and cannot filter by, and nothing says so.
 *
 * The columns are the list now. A constraint the developer declared still
 * wins over one derived from a column of the same name, which is what keeps
 * every table written before this compiling and behaving as it did.
 */

/** @return array<string, mixed> */
function queryFilterArray(TableSchema $table): array
{
    foreach ($table->toArray()['filters'] as $filter) {
        if ($filter['type'] === 'query_builder') {
            return $filter;
        }
    }

    return [];
}

/** @return list<string> */
function constraintNames(TableSchema $table): array
{
    return array_map(
        static fn (array $constraint): string => $constraint['name'],
        queryFilterArray($table)['constraints'] ?? [],
    );
}

function tableWithColumns(array $columns): TableSchema
{
    return TableSchema::make()
        ->columns($columns)
        ->filters([QueryBuilderFilter::make('advanced')]);
}

it('offers a text column as a condition without a second declaration', function (): void {
    $table = tableWithColumns([TextColumn::make('name')]);

    expect(constraintNames($table))->toBe(['name']);
});

it('gives a text column text semantics', function (): void {
    $table = tableWithColumns([TextColumn::make('name')]);
    $constraint = queryFilterArray($table)['constraints'][0];

    expect($constraint['input'])->toBe('text')
        ->and(array_column($constraint['operators'], 'value'))->toContain('contains');
});

it('gives a number column number semantics', function (): void {
    $table = tableWithColumns([NumberColumn::make('total')]);
    $constraint = queryFilterArray($table)['constraints'][0];

    expect($constraint['input'])->toBe('number');
});

it('gives a date column date semantics', function (): void {
    $table = tableWithColumns([DateColumn::make('created_at')]);
    $constraint = queryFilterArray($table)['constraints'][0];

    // The frontend renders `PanelDatePicker` for this, never a native input.
    expect($constraint['input'])->toBe('date');
});

it('gives a boolean column boolean semantics', function (): void {
    $table = tableWithColumns([BooleanColumn::make('active')]);
    $constraint = queryFilterArray($table)['constraints'][0];

    expect($constraint['input'])->toBe('none');
});

it('offers no condition for a display-only column', function (): void {
    // An image and an icon draw something *from* a value rather than showing
    // it. There is no comparison to offer, and offering a text filter that
    // silently matches nothing would be worse than offering none.
    $table = tableWithColumns([
        ImageColumn::make('avatar'),
        IconColumn::make('status_icon'),
    ]);

    expect(constraintNames($table))->toBe([]);
});

it('offers no condition for a custom column by default', function (): void {
    // Its value is whatever the application decided; the package cannot know.
    $table = tableWithColumns([CustomColumn::make('score', 'ScoreCell')]);

    expect(constraintNames($table))->toBe([]);
});

it('lets a custom column opt in with an explicit constraint', function (): void {
    $table = tableWithColumns([
        CustomColumn::make('score', 'ScoreCell')
            ->queryConstraint(TextConstraint::make('score')),
    ]);

    expect(constraintNames($table))->toBe(['score']);
});

it('lets a column opt out', function (): void {
    $table = tableWithColumns([
        TextColumn::make('name'),
        TextColumn::make('secret')->queryable(false),
    ]);

    expect(constraintNames($table))->toBe(['name']);
});

it('uses the column label for the condition', function (): void {
    $table = tableWithColumns([TextColumn::make('created_at')->label('Dibuat pada')]);
    $constraint = queryFilterArray($table)['constraints'][0];

    // The value is the stable column key; the label is what the reader sees,
    // and it follows whatever the developer set.
    expect($constraint['name'])->toBe('created_at')
        ->and($constraint['label'])->toBe('Dibuat pada');
});

it('lets an explicit constraint override the derived one', function (): void {
    $table = TableSchema::make()
        ->columns([TextColumn::make('created_at')])
        ->filters([
            QueryBuilderFilter::make('advanced')->constraints([
                TextConstraint::make('created_at')->label('Declared'),
            ]),
        ]);

    $constraints = queryFilterArray($table)['constraints'];

    // One entry, not two: a declared constraint replaces the derived one of
    // the same name rather than sitting beside it.
    expect($constraints)->toHaveCount(1)
        ->and($constraints[0]['label'])->toBe('Declared');
});

it('keeps a declared constraint that matches no column', function (): void {
    $table = TableSchema::make()
        ->columns([TextColumn::make('name')])
        ->filters([
            QueryBuilderFilter::make('advanced')->constraints([
                TextConstraint::make('internal_note'),
            ]),
        ]);

    // Nothing on screen to hide, and dropping it would break every table
    // written before columns could describe themselves.
    expect(constraintNames($table))->toBe(['internal_note', 'name']);
});

it('resolves a derived constraint when a rule arrives', function (): void {
    $table = tableWithColumns([TextColumn::make('name')]);
    $filter = $table->getFilter('advanced');

    // The gate every incoming rule passes. If only serialization knew about
    // the derived constraints, a condition the user built would be offered
    // and then silently dropped.
    expect($filter)->toBeInstanceOf(QueryBuilderFilter::class)
        ->and($filter->constraint('name'))->not->toBeNull();
});

/*
 * Description and callouts
 */

it('serializes no description by default', function (): void {
    expect(TableSchema::make()->toArray()['description'])->toBeNull();
});

it('serializes a description', function (): void {
    $table = TableSchema::make()->description('Excludes archived records.');

    expect($table->toArray()['description'])->toBe('Excludes archived records.');
});

it('serializes no callouts by default', function (): void {
    expect(TableSchema::make()->toArray()['callouts'])->toBe([]);
});

it('serializes a callout with its tone and icon', function (): void {
    $table = TableSchema::make()->callout(
        Callout::make('Payroll period is locked.')->tone(CalloutTone::Warning),
    );

    $callout = $table->toArray()['callouts'][0];

    expect($callout['body'])->toBe('Payroll period is locked.')
        ->and($callout['tone'])->toBe('warning')
        ->and($callout['icon'])->not->toBeNull()
        ->and($callout['component'])->toBe('callout');
});

it('stacks callouts in declaration order', function (): void {
    $table = TableSchema::make()
        ->callout(Callout::make('First'))
        ->callout(Callout::make('Second'));

    expect(array_column($table->toArray()['callouts'], 'body'))
        ->toBe(['First', 'Second']);
});

it('replaces the list when callouts are set together', function (): void {
    $table = TableSchema::make()
        ->callout(Callout::make('Dropped'))
        ->callouts([Callout::make('Kept')]);

    expect(array_column($table->toArray()['callouts'], 'body'))->toBe(['Kept']);
});
