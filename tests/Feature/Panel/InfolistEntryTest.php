<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use PandaPanel\Actions\Action;
use PandaPanel\Forms\Enums\CodeLanguage;
use PandaPanel\Infolists\Components\CodeEntry;
use PandaPanel\Infolists\Components\ColorEntry;
use PandaPanel\Infolists\Components\CustomEntry;
use PandaPanel\Infolists\Components\IconEntry;
use PandaPanel\Infolists\Components\ImageEntry;
use PandaPanel\Infolists\Components\RepeatableEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\Enums\EntryType;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Grid;
use PandaPanel\Infolists\Layouts\Section;
use PandaPanel\Infolists\Layouts\Tab;
use PandaPanel\Infolists\Layouts\Tabs;
use PandaPanel\Tables\Enums\BadgeColor;
use Tests\Fixtures\Panel\Relations\Project;
use Tests\Fixtures\Panel\Relations\RelationSchema;

beforeEach(function (): void {
    RelationSchema::create();
});

/*
 * Entries
 */

it('resolves an icon from the value it stands for', function (): void {
    $entry = IconEntry::make('status')
        ->icons(['live' => 'check', 'draft' => 'pencil'])
        ->colors(['live' => BadgeColor::Success]);

    $record = new Project(['status' => 'live']);

    expect($entry->toValue($record))->toBe([
        'icon' => 'check',
        'color' => 'success',
        // An icon alone reads as empty to a screen reader, so the value
        // travels with it.
        'label' => 'live',
    ]);
});

it('renders no icon for a value it has none for', function (): void {
    $entry = IconEntry::make('status')->icons(['live' => 'check']);

    expect($entry->toValue(new Project(['status' => 'archived'])))->toBeNull();
});

it('builds an image URL on the server, from the disk it declares', function (): void {
    Storage::fake('public');

    $entry = ImageEntry::make('avatar')->disk('public')->circular();

    $value = $entry->toValue(new Project(['avatar' => 'avatars/one.png']));

    expect($value)->toContain('avatars/one.png')
        ->and($entry->toArray(new Project(['avatar' => 'avatars/one.png']))['circular'])
        ->toBeTrue();
});

it('passes an absolute URL through rather than resolving it', function (): void {
    $entry = ImageEntry::make('avatar')->disk('public');

    expect($entry->toValue(new Project(['avatar' => 'https://example.test/a.png'])))
        ->toBe('https://example.test/a.png');
});

it('refuses a stored colour that is a stylesheet', function (): void {
    $entry = ColorEntry::make('brand');

    expect($entry->toValue(new Project(['brand' => '#ff0000'])))->toBe('#ff0000')
        // This value ends up inside a `style` attribute.
        ->and($entry->toValue(new Project(['brand' => 'red; background: url(x)'])))
        ->toBeNull();
});

it('pretty-prints a structure into a code entry', function (): void {
    $entry = CodeEntry::make('meta')->language(CodeLanguage::Json);

    $value = $entry->toValue(new Project(['meta' => ['a' => 1]]));

    expect($value)->toContain('"a": 1')
        ->and($entry->toArray(new Project)['language'])->toBe('json');
});

it('renders a repeatable once per item, records or rows alike', function (): void {
    $entry = RepeatableEntry::make('lines')
        ->itemLabel('Line')
        ->schema([TextEntry::make('title')]);

    $value = $entry->toValue(new Project([
        'lines' => [['title' => 'First'], ['title' => 'Second']],
    ]));

    expect($value)->toHaveCount(2)
        ->and($value[0]['label'])->toBe('Line 1')
        ->and($value[0]['schema'][0]['value'])->toBe('First')
        ->and($value[1]['schema'][0]['value'])->toBe('Second');
});

it('drops a repeatable item that is neither a record nor a row', function (): void {
    $entry = RepeatableEntry::make('lines')->schema([TextEntry::make('title')]);

    expect($entry->toValue(new Project(['lines' => ['just a string', 42]])))->toBe([]);
});

it('counts a repeatable as one entry rather than its children', function (): void {
    $entry = RepeatableEntry::make('lines')->schema([TextEntry::make('title')]);

    // Its children belong to an item, not to the record — saying otherwise
    // would claim a value exists at the top level that does not.
    expect($entry->entries())->toBe([$entry]);
});

it('sends a custom entry as a registry key and its state', function (): void {
    $entry = CustomEntry::make('score')
        ->component('Panels/Admin/Entries/Gauge')
        ->config(['max' => 10])
        ->state(static fn (Model $record): int => 7);

    $definition = $entry->toArray(new Project);

    expect($definition['type'])->toBe('custom')
        ->and($definition['componentName'])->toBe('Panels/Admin/Entries/Gauge')
        ->and($definition['config'])->toBe(['max' => 10])
        ->and($definition['value'])->toBe(7);
});

/*
 * Layouts
 */

it('drops a layout whose entries are all hidden', function (): void {
    $schema = InfolistSchema::make()->schema([
        Section::make('Hidden')->schema([
            TextEntry::make('name')->visible(static fn (): bool => false),
        ]),
        Grid::make(2)->schema([
            TextEntry::make('name')->visible(static fn (): bool => false),
        ]),
    ]);

    expect($schema->toArray(new Project(['name' => 'Apollo']))['schema'])->toBe([]);
});

it('gives every tab a key an error or a URL can name', function (): void {
    $definition = Tabs::make([
        Tab::make('Account Details')->schema([TextEntry::make('name')]),
    ])->toArray(new Project(['name' => 'Apollo']));

    expect($definition['tabs'][0]['key'])->toBe('account-details');
});

it('drops a tab set whose every tab is empty', function (): void {
    $tabs = Tabs::make([
        Tab::make('Empty')->schema([
            TextEntry::make('name')->visible(static fn (): bool => false),
        ]),
    ]);

    expect($tabs->toArray(new Project))->toBeNull();
});

/*
 * Actions
 */

it('finds an action wherever the infolist declared it', function (): void {
    $schema = InfolistSchema::make()
        ->actions([Action::make('approve')])
        ->schema([
            Section::make('Details')
                ->headerActions([Action::make('resend')])
                ->schema([
                    TextEntry::make('name')->action(Action::make('rename')),
                ]),
        ]);

    // The whitelist the endpoint resolves against: header, section, and entry
    // actions are all reachable because all three were declared here.
    expect(array_keys($schema->allActions()))
        ->toBe(['approve', 'resend', 'rename']);
});

it('reaches an action registered on another action\'s dialog', function (): void {
    $schema = InfolistSchema::make()->actions([
        Action::make('approve')->registerModalActions([Action::make('explain')]),
    ]);

    expect($schema->getAction('explain'))->not->toBeNull();
});

it('does not offer an action the user may not run', function (): void {
    $entry = TextEntry::make('name')->action(
        Action::make('rename')->authorize(static fn (): bool => false),
    );

    expect($entry->toArray(new Project(['name' => 'Apollo']))['action'])->toBeNull();
});

it('offers no action on a repeatable row, which is not a record', function (): void {
    $entry = RepeatableEntry::make('lines')->schema([
        TextEntry::make('title')->action(Action::make('rename')),
    ]);

    $value = $entry->toValue(new Project(['lines' => [['title' => 'First']]]));

    // A wrapped row has no key, and an action pointing at it would name a
    // record the endpoint could never find.
    expect($value[0]['schema'][0]['action'])->toBeNull();
});

/*
 * The two halves of the union
 */

it('describes every entry type on the frontend as well', function (): void {
    $definitions = file_get_contents(
        base_path('resources/js/panel/types/infolist.ts'),
    );

    preg_match_all("/^\\s+type: '([a-z-]+)';$/m", (string) $definitions, $matches);

    $declared = array_map(
        static fn (EntryType $type): string => $type->value,
        EntryType::cases(),
    );

    // The renderer's switch is exhaustive over the TypeScript union, so a
    // union member with no branch is a compile error. What the compiler
    // cannot see is a PHP case that never reached the union at all.
    expect(array_diff($declared, $matches[1]))->toBe([]);
});
