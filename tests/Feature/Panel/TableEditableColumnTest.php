<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\TestResponse;
use PandaPanel\Tables\Columns\SelectColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Columns\TextInputColumn;
use PandaPanel\Tables\Columns\ToggleColumn;
use PandaPanel\Tables\TableSchema;
use Tests\Fixtures\Panel\Relations\EditableTaskResource;
use Tests\Fixtures\Panel\Relations\RelationPanel;
use Tests\Fixtures\Panel\Relations\Task;
use Tests\Fixtures\Panel\Relations\TaskPolicy;

beforeEach(function (): void {
    RelationPanel::boot();
    RelationPanel::reset();

    $this->actingAs(User::factory()->admin()->create());

    $this->task = Task::query()->create(['name' => 'Alpha', 'project_id' => null]);
});

function writeCell(string $column, mixed $value, int|string|null $record = null): TestResponse
{
    return test()->post(RelationPanel::url('actions/cell'), [
        'resource' => 'editable-tasks',
        'record' => $record ?? test()->task->getKey(),
        'column' => $column,
        'value' => $value,
    ]);
}

/*
 * Writing
 */

it('writes a text cell', function (): void {
    writeCell('name', 'Renamed')->assertRedirect();

    expect($this->task->fresh()->name)->toBe('Renamed');
});

it('writes a select cell', function (): void {
    writeCell('status', 'done')->assertRedirect();

    expect($this->task->fresh()->status)->toBe('done');
});

it('writes a toggle cell as a boolean', function (): void {
    writeCell('is_pinned', true)->assertRedirect();

    expect((bool) $this->task->fresh()->is_pinned)->toBeTrue();
});

it('refuses a toggle value that is not a boolean', function (): void {
    // `boolean` accepts true/false/1/0, not the word — the rule is the
    // server's, and the control only decides what is easy to click.
    writeCell('is_pinned', 'yes')->assertSessionHasErrors('value');

    expect((bool) $this->task->fresh()->is_pinned)->toBeFalse();
});

/*
 * Only declared, editable columns
 */

it('refuses a column the table does not declare', function (): void {
    writeCell('deleted_at', '2020-01-01')->assertNotFound();
});

it('refuses a column that is not editable', function (): void {
    // Declared, and shown, but a read-only column: an editable cell is a
    // write endpoint, and only a column that says so is one.
    writeCell('id', '99')->assertStatus(400);

    expect($this->task->fresh()->getKey())->toBe($this->task->getKey());
});

/*
 * Validation is the server's
 */

it('validates the value against the column\'s own rules', function (): void {
    writeCell('status', 'invented')->assertSessionHasErrors('value');

    expect($this->task->fresh()->status)->toBeNull();
});

it('enforces a max length the column declared', function (): void {
    writeCell('name', str_repeat('x', 300))->assertSessionHasErrors('value');

    expect($this->task->fresh()->name)->toBe('Alpha');
});

/*
 * Authorization, per record and per cell
 */

it('refuses a record the policy will not let the user edit', function (): void {
    TaskPolicy::$updatable = false;

    writeCell('name', 'Renamed')->assertForbidden();

    expect($this->task->fresh()->name)->toBe('Alpha');
});

it('refuses a cell the column reports as disabled for that record', function (): void {
    $locked = Task::query()->create(['name' => 'locked', 'project_id' => null]);

    // A disabled control is not a permission: the same question is asked
    // again on the way in.
    writeCell('name', 'Renamed', $locked->getKey())->assertForbidden();

    expect($locked->fresh()->name)->toBe('locked');
});

it('404s a record outside the resource query', function (): void {
    writeCell('name', 'Renamed', 99999)->assertNotFound();
});

/*
 * Serialization
 */

it('carries the disabled state per record, not per column', function (): void {
    $locked = Task::query()->create(['name' => 'locked', 'project_id' => null]);

    $schema = EditableTaskResource::table(TableSchema::make());

    expect($schema->toRow($this->task)['cells']['name']['disabled'])->toBeFalse()
        ->and($schema->toRow($locked)['cells']['name']['disabled'])->toBeTrue();
});

it('marks an editable column as such in the definition', function (): void {
    $definition = TableSchema::make()
        ->columns([
            TextColumn::make('name'),
            ToggleColumn::make('is_pinned'),
        ])
        ->toArray();

    expect($definition['columns'][0])->not->toHaveKey('editable')
        ->and($definition['columns'][1]['editable'])->toBeTrue();
});

it('lets a column write to a different attribute', function (): void {
    $column = TextInputColumn::make('display_name')->writeTo('name');

    expect($column->getWriteAttribute())->toBe('name');
});

it('lets a column replace the write entirely', function (): void {
    $seen = null;

    $column = TextInputColumn::make('name')->updateUsing(
        static function (mixed $value, Model $record) use (&$seen): void {
            $seen = $value;
        },
    );

    $column->write($this->task, 'Through a service');

    // The record is untouched: the closure owns the write.
    expect($seen)->toBe('Through a service')
        ->and($this->task->fresh()->name)->toBe('Alpha');
});

it('validates a select against the options it declared', function (): void {
    $rules = SelectColumn::make('status')
        ->options(['open' => 'Open', 'done' => 'Done'])
        ->validationRules();

    expect(collect($rules)->map(strval(...))->implode(' '))
        ->toContain('in:"open","done"');
});
