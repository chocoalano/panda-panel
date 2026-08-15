<?php

declare(strict_types=1);

use App\Models\User;
use App\Panels\Admin\Resources\Users\UserResource;
use PandaPanel\Infolists\Components\BadgeEntry;
use PandaPanel\Infolists\Components\BooleanEntry;
use PandaPanel\Infolists\Components\DateTimeEntry;
use PandaPanel\Infolists\Components\KeyValueEntry;
use PandaPanel\Infolists\Components\TextEntry;
use PandaPanel\Infolists\InfolistSchema;
use PandaPanel\Infolists\Layouts\Section;
use PandaPanel\Tables\Enums\BadgeColor;

beforeEach(function (): void {
    $this->record = User::factory()->create([
        'name' => 'Grace Hopper',
        'email' => 'grace@example.com',
        'is_admin' => true,
    ]);
});

it('is empty until a resource declares one', function (): void {
    expect(InfolistSchema::make()->isEmpty())->toBeTrue()
        ->and(UserResource::infolist(InfolistSchema::make())->isEmpty())->toBeFalse();
});

it('derives a label from the entry name', function (): void {
    expect(TextEntry::make('email_address')->getLabel())->toBe('Email Address')
        // Dot notation reads through a relation and still reads as a label.
        ->and(TextEntry::make('author.name')->getLabel())->toBe('Author Name')
        ->and(TextEntry::make('name')->label('Full name')->getLabel())->toBe('Full name');
});

it('reads a value through dot notation', function (): void {
    $entry = TextEntry::make('name');

    expect($entry->toValue($this->record))->toBe('Grace Hopper');
});

it('truncates on the server rather than sending the whole value', function (): void {
    $entry = TextEntry::make('name')->limit(5);

    expect($entry->toValue($this->record))->toBe('Grace...');
});

it('returns null for an empty value so the placeholder shows', function (): void {
    $this->record->forceFill(['email_verified_at' => null])->save();

    $entry = TextEntry::make('email_verified_at');

    expect($entry->toValue($this->record))->toBeNull()
        ->and($entry->placeholder('Never')->toArray($this->record)['placeholder'])->toBe('Never');
});

it('resolves a badge colour on the server', function (): void {
    $entry = BadgeEntry::make('name')->colors(['Grace Hopper' => BadgeColor::Success]);

    expect($entry->toValue($this->record))
        ->toBe(['label' => 'Grace Hopper', 'color' => 'success']);
});

it('falls back to the default badge colour', function (): void {
    $entry = BadgeEntry::make('name')->colors([]);

    expect($entry->toValue($this->record)['color'])->toBe('neutral');
});

it('renders a boolean with its own labels', function (): void {
    $entry = BooleanEntry::make('is_admin')->labels('Administrator', 'Member');

    expect($entry->toValue($this->record))->toBeTrue()
        ->and($entry->toArray($this->record)['trueLabel'])->toBe('Administrator');
});

it('formats a date on the server', function (): void {
    $entry = DateTimeEntry::make('created_at')->format('Y-m-d');

    expect($entry->toValue($this->record))->toBe($this->record->created_at->format('Y-m-d'));
});

it('flattens a key-value entry to strings', function (): void {
    $entry = KeyValueEntry::make('meta')
        ->formatUsing(static fn (): array => ['plan' => 'pro', 'seats' => 4, 'flags' => ['beta']]);

    expect($entry->toValue($this->record))->toBe([
        ['key' => 'plan', 'value' => 'pro'],
        ['key' => 'seats', 'value' => '4'],
        // A nested value is flattened here rather than arriving as an object
        // the renderer has no branch for.
        ['key' => 'flags', 'value' => '["beta"]'],
    ]);
});

it('hides an entry the record does not warrant', function (): void {
    $entry = TextEntry::make('email')->visible(static fn (): bool => false);

    expect($entry->toArray($this->record))->toBeNull();
});

it('drops a section left empty by hidden entries', function (): void {
    $section = Section::make('Nothing')->schema([
        TextEntry::make('email')->visible(static fn (): bool => false),
    ]);

    // An empty heading reads as a rendering fault, so the section goes too.
    expect($section->toArray($this->record))->toBeNull();
});

it('serializes a section with its entries', function (): void {
    $schema = InfolistSchema::make()
        ->columns(2)
        ->schema([
            Section::make('Account')
                ->description('Who they are')
                ->columns(2)
                ->schema([TextEntry::make('name'), TextEntry::make('email')]),
        ]);

    $array = $schema->toArray($this->record);

    expect($array['columns'])->toBe(2)
        ->and($array['schema'][0]['component'])->toBe('section')
        ->and($array['schema'][0]['heading'])->toBe('Account')
        ->and($array['schema'][0]['description'])->toBe('Who they are')
        ->and(array_column($array['schema'][0]['schema'], 'name'))->toBe(['name', 'email']);
});

it('collects every entry however deeply nested', function (): void {
    $schema = InfolistSchema::make()->schema([
        TextEntry::make('name'),
        Section::make('Nested')->schema([
            Section::make('Deeper')->schema([TextEntry::make('email')]),
        ]),
    ]);

    expect(array_map(
        static fn ($entry): string => $entry->getName(),
        $schema->entries(),
    ))->toBe(['name', 'email']);
});

it('never serializes a closure', function (): void {
    $schema = InfolistSchema::make()->schema([
        TextEntry::make('name')->formatUsing(static fn (mixed $value): string => (string) $value),
        TextEntry::make('email')->visible(static fn (): bool => true),
    ]);

    $encoded = json_encode($schema->toArray($this->record));

    expect($encoded)->toBeString()
        ->and($encoded)->not->toContain('Closure');
});
