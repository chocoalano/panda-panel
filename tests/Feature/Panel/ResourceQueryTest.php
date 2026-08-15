<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'created_at' => now()->subDays(30),
    ]);

    $this->actingAs($this->admin);
});

/**
 * The name column is editable, so its cell is `{value, disabled}` rather than
 * a bare string — the disabled state is answered per record and has to travel
 * with the value.
 *
 * @return list<string>
 */
function namesOn(string $url): array
{
    return collect(test()->get($url)->viewData('page')['props']['rows'])
        ->pluck('cells.name.value')
        ->all();
}

it('lists records through the resource query', function (): void {
    User::factory()->create(['name' => 'Grace Hopper']);

    expect(namesOn('/admin/users'))->toContain('Ada Lovelace', 'Grace Hopper');
});

it('searches only the columns declared searchable', function (): void {
    User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);

    expect(namesOn('/admin/users?search=grace'))->toBe(['Grace Hopper'])
        ->and(namesOn('/admin/users?search=grace@example.com'))->toBe(['Grace Hopper'])
        // is_admin is not searchable, so its value must not match anything.
        ->and(namesOn('/admin/users?search=1'))->toBe([]);
});

it('treats LIKE wildcards in a search term literally', function (): void {
    User::factory()->create(['name' => 'Grace Hopper']);

    expect(namesOn('/admin/users?search=%'))->toBe([])
        ->and(namesOn('/admin/users?search=_'))->toBe([]);
});

it('sorts by a whitelisted column', function (): void {
    User::factory()->create(['name' => 'Zoe Zephyr']);

    expect(namesOn('/admin/users?sort=name&direction=asc'))->toBe(['Ada Lovelace', 'Zoe Zephyr'])
        ->and(namesOn('/admin/users?sort=name&direction=desc'))->toBe(['Zoe Zephyr', 'Ada Lovelace']);
});

it('ignores a sort column that is not declared sortable', function (): void {
    $response = $this->get('/admin/users?sort=password&direction=asc');

    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state.sort', null));
});

it('ignores a sort column that does not exist at all', function (): void {
    $this->get('/admin/users?sort='.urlencode('name); drop table users --'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state.sort', null));

    expect(DB::table('users')->count())->toBeGreaterThan(0);
});

it('falls back to ascending for an unrecognised direction', function (): void {
    $this->get('/admin/users?sort=name&direction=sideways')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state.direction', 'asc'));
});

it('applies a boolean filter over a nullable timestamp', function (): void {
    User::factory()->unverified()->create(['name' => 'Unverified Person']);

    expect(namesOn('/admin/users?filters[verified]=1'))->toBe(['Ada Lovelace'])
        ->and(namesOn('/admin/users?filters[verified]=0'))->toBe(['Unverified Person']);
});

it('applies a boolean filter over a real boolean column', function (): void {
    User::factory()->create(['name' => 'Plain Member']);

    expect(namesOn('/admin/users?filters[is_admin]=1'))->toBe(['Ada Lovelace'])
        ->and(namesOn('/admin/users?filters[is_admin]=0'))->toBe(['Plain Member']);
});

it('applies a date range filter', function (): void {
    User::factory()->create(['name' => 'Recent Person', 'created_at' => now()]);

    $from = now()->subDay()->toDateString();

    expect(namesOn("/admin/users?filters[registered][from]={$from}"))->toBe(['Recent Person']);
});

it('swaps a reversed date range instead of returning nothing', function (): void {
    User::factory()->create(['name' => 'Recent Person', 'created_at' => now()]);

    $from = now()->addDay()->toDateString();
    $to = now()->subDay()->toDateString();

    expect(namesOn("/admin/users?filters[registered][from]={$from}&filters[registered][to]={$to}"))
        ->toContain('Recent Person');
});

it('ignores an unknown filter name', function (): void {
    $this->get('/admin/users?filters[nope]=1')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state.filters', []));
});

it('ignores a filter value outside the declared options', function (): void {
    $this->get('/admin/users?filters[verified]=maybe')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state.filters', []));

    expect(namesOn('/admin/users?filters[verified]=maybe'))->toContain('Ada Lovelace');
});

it('reports pagination metadata for the applied query', function (): void {
    User::factory()->count(12)->create();

    $this->get('/admin/users?perPage=10')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.page', 1)
            ->where('pagination.perPage', 10)
            ->where('pagination.total', 13)
            ->where('pagination.lastPage', 2)
            ->where('pagination.from', 1)
            ->where('pagination.to', 10)
            ->has('rows', 10)
        );
});

it('clamps perPage to the declared options', function (): void {
    $this->get('/admin/users?perPage=100000')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state.perPage', 25));

    $this->get('/admin/users?perPage=abc')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state.perPage', 25));
});

it('clamps a negative page to the first page', function (): void {
    $this->get('/admin/users?page=-5')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('pagination.page', 1));
});

it('combines search, filter, sort, and pagination in one query', function (): void {
    User::factory()->count(3)->create(['name' => 'Team Member']);
    User::factory()->unverified()->create(['name' => 'Team Ghost']);

    $names = namesOn('/admin/users?search=team&filters[verified]=1&sort=name&direction=asc&perPage=10');

    expect($names)->toBe(['Team Member', 'Team Member', 'Team Member']);
});

it('serializes each column into the cell shape its type declares', function (): void {
    $this->get('/admin/users')
        ->assertInertia(function (AssertableInertia $page): void {
            $cells = $page->toArray()['props']['rows'][0]['cells'];

            expect($cells['name'])->toHaveKeys(['value', 'disabled'])
                ->and($cells['name']['value'])->toBeString()
                ->and($cells['email_verified_at'])->toHaveKeys(['value', 'label', 'color'])
                ->and($cells['email_verified_at']['color'])->toBe('success')
                // A privilege flag, so it is a toggle rather than a badge —
                // and disabled on the acting administrator's own row.
                ->and($cells['is_admin'])->toHaveKeys(['value', 'disabled'])
                ->and($cells['is_admin']['value'])->toBeTrue()
                ->and($cells['is_admin']['disabled'])->toBeTrue()
                // An icon column always renders something: "no two-factor"
                // is an answer, not an empty cell.
                ->and($cells['two_factor_confirmed_at'])->toHaveKeys(['icon', 'color', 'label'])
                ->and($cells['two_factor_confirmed_at']['icon'])->toBe('shield-off')
                ->and($cells['passkeys_count'])->toHaveKeys(['display', 'raw'])
                ->and($cells['created_at'])->toHaveKeys(['display', 'iso'])
                ->and($cells['avatar'])->toHaveKeys(['url', 'fallback', 'alt'])
                ->and($cells['avatar']['fallback'])->toBe('AL');
        });
});

it('does not issue a query per row while serializing', function (): void {
    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        test()->get('/admin/users?perPage=50')->assertOk();

        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $queries;
    };

    User::factory()->count(4)->create();
    $few = $countQueries();

    User::factory()->count(30)->create();
    $many = $countQueries();

    // The count is the same for five rows and thirty-five: a fixed number of
    // queries whatever the page holds. Asserting that rather than a budget
    // means adding an eager load does not silently become a per-row read.
    expect($many)->toBe($few);
});
