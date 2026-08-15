<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use PandaPanel\Tables\Columns\ColorColumn;
use PandaPanel\Tables\Columns\CustomColumn;
use PandaPanel\Tables\Columns\IconColumn;
use PandaPanel\Tables\Columns\NumberColumn;
use PandaPanel\Tables\Columns\TextColumn;
use PandaPanel\Tables\Enums\Alignment;
use PandaPanel\Tables\Enums\BadgeColor;
use PandaPanel\Tables\TableSchema;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\RelationSchema;

beforeEach(function (): void {
    RelationSchema::create();

    $this->record = Project::query()->create(['name' => 'Apollo']);
});

/*
 * Placeholder and default state
 */

it('shows a placeholder for an empty cell', function (): void {
    $column = TextColumn::make('missing')->placeholder('Not set');

    expect($column->toCell($this->record))->toBeNull()
        ->and($column->toArray()['placeholder'])->toBe('Not set');
});

it('defaults every column to the same empty rule', function (): void {
    // The placeholder is the base class's, so a date and a text column read
    // the same way rather than each renderer inventing its own.
    expect(NumberColumn::make('missing')->toArray())->toHaveKey('placeholder')
        ->and(TextColumn::make('missing')->toArray()['placeholder'])->toBeNull();
});

it('substitutes a default state before formatting, not after', function (): void {
    // A default is "treat it as this", so it goes through the same formatting
    // a real value would — unlike a placeholder, which is presentation for
    // the absence itself.
    $column = TextColumn::make('missing')
        ->default('fallback')
        ->formatUsing(static fn (mixed $value): string => strtoupper((string) $value));

    expect($column->toCell($this->record))->toBe('FALLBACK');
});

/*
 * Tooltips, links, and attributes
 */

it('resolves a tooltip per record', function (): void {
    $column = TextColumn::make('name')
        ->tooltip(static fn (Model $record): string => 'Project '.$record->getAttribute('name'));

    expect($column->toCellMeta($this->record))->toBe(['tooltip' => 'Project Apollo']);
});

it('accepts a static tooltip too', function (): void {
    expect(TextColumn::make('name')->tooltip('Fixed')->toCellMeta($this->record))
        ->toBe(['tooltip' => 'Fixed']);
});

it('sends a header tooltip on the definition, not on every row', function (): void {
    $column = TextColumn::make('name')->headerTooltip('About this column');

    expect($column->toArray()['headerTooltip'])->toBe('About this column')
        // A header has no record to vary by, so it is sent once.
        ->and($column->toCellMeta($this->record))->toBeNull();
});

it('turns a cell into a server-produced link', function (): void {
    $column = TextColumn::make('name')
        ->url(static fn (Model $record): string => '/projects/'.$record->getKey());

    expect($column->toCellMeta($this->record))
        ->toBe(['url' => '/projects/'.$this->record->getKey()]);
});

it('costs nothing for a column that uses none of it', function (): void {
    // Null rather than an empty array, so a table of plain columns ships no
    // per-row metadata at all.
    expect(TextColumn::make('name')->toCellMeta($this->record))->toBeNull();
});

it('refuses an event handler in extra attributes', function (): void {
    $column = TextColumn::make('name')->extraAttributes([
        'data-id' => 7,
        'onclick' => 'alert(1)',
        'onClick' => 'alert(2)',
    ]);

    // These are spread onto an element, so an `on*` attribute would be a way
    // to put executable content on a page from a schema.
    expect($column->toCellMeta($this->record))->toBe([
        'attributes' => ['data-id' => 7],
    ]);
});

it('refuses a non-scalar extra attribute', function (): void {
    $column = TextColumn::make('name')->extraAttributes([
        'data-ok' => 'yes',
        'data-bad' => ['nested'],
    ]);

    expect($column->toCellMeta($this->record))->toBe([
        'attributes' => ['data-ok' => 'yes'],
    ]);
});

/*
 * Alignment, width, header wrapping
 */

it('uses logical alignment and follows it in the header by default', function (): void {
    $definition = TextColumn::make('name')->alignment(Alignment::End)->toArray();

    expect($definition['alignment'])->toBe('end')
        ->and($definition['headerAlignment'])->toBe('end');
});

it('accepts the physical names and means what they always meant', function (): void {
    expect(TextColumn::make('name')->alignment('right')->toArray()['alignment'])->toBe('end')
        ->and(TextColumn::make('name')->alignment('left')->toArray()['alignment'])->toBe('start');
});

it('lets a header align independently of its cells', function (): void {
    $definition = NumberColumn::make('total')
        ->headerAlignment(Alignment::Start)
        ->toArray();

    expect($definition['alignment'])->toBe('end')
        ->and($definition['headerAlignment'])->toBe('start');
});

it('sends width as a CSS length rather than a class', function (): void {
    // A Tailwind class would have to be interpolated, and an interpolated
    // class does not exist in the bundle.
    expect(TextColumn::make('name')->width('12rem')->toArray()['width'])->toBe('12rem');
});

it('carries header wrapping on the definition', function (): void {
    expect(TextColumn::make('name')->wrapHeader()->toArray()['wrapHeader'])->toBeTrue()
        ->and(TextColumn::make('name')->toArray()['wrapHeader'])->toBeFalse();
});

/*
 * New column types
 */

it('maps a value to an icon registry key and a palette colour', function (): void {
    $column = IconColumn::make('name')
        ->icons(['Apollo' => 'check'])
        ->colors(['Apollo' => BadgeColor::Success]);

    expect($column->toCell($this->record))
        ->toBe(['icon' => 'check', 'color' => 'success', 'label' => 'Apollo']);
});

it('renders a boolean icon column as a tick or a cross', function (): void {
    $column = IconColumn::make('name')->boolean();

    expect($column->toCell($this->record))
        ->toBe(['icon' => 'check', 'color' => 'success', 'label' => 'Yes']);
});

it('renders nothing for a value the icon column does not map', function (): void {
    expect(IconColumn::make('name')->icons(['Other' => 'check'])->toCell($this->record))
        ->toBeNull();
});

it('accepts only colours it can safely put in an inline style', function (): void {
    $colorOf = function (string $value): ?array {
        $record = Project::query()->create(['name' => $value]);

        return ColorColumn::make('name')->toCell($record);
    };

    expect($colorOf('#ff0000'))->toBe(['color' => '#ff0000', 'label' => '#ff0000'])
        ->and($colorOf('rgb(255, 0, 0)'))->not->toBeNull()
        // The value ends up in a `background-color`, so anything outside the
        // known-safe syntaxes renders nothing rather than being repaired.
        ->and($colorOf('red; background: url(javascript:alert(1))'))->toBeNull()
        ->and($colorOf('expression(alert(1))'))->toBeNull();
});

it('names a custom column\'s component without resolving it', function (): void {
    $column = CustomColumn::make('name')
        ->component('Panels/Admin/Columns/Sparkline')
        ->state(static fn (Model $record): array => ['points' => [1, 2, 3]]);

    expect($column->toArray()['component'])->toBe('Panels/Admin/Columns/Sparkline')
        ->and($column->toCell($this->record))->toBe(['points' => [1, 2, 3]]);
});

/*
 * Serialization
 */

it('serializes a row with its cell metadata beside the cells', function (): void {
    $schema = TableSchema::make()->columns([
        TextColumn::make('name')->tooltip('Hi'),
        TextColumn::make('id'),
    ]);

    $row = $schema->toRow($this->record);

    expect($row['cells'])->toHaveKeys(['name', 'id'])
        // Only the column that has any, so a plain table ships an empty map.
        ->and($row['cellMeta'])->toBe(['name' => ['tooltip' => 'Hi']]);
});

it('serializes a column definition free of closures', function (): void {
    $schema = TableSchema::make()->columns([
        TextColumn::make('name')
            ->tooltip(static fn (): string => 'x')
            ->url(static fn (): string => '/x')
            ->extraAttributes(static fn (): array => ['data-x' => '1']),
    ]);

    expect(json_encode($schema->toArray()))->not->toContain('Closure');
});
