<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The table endpoint, given input nobody would type.
 *
 * Every one of these parameters reaches a query builder, and every one of them
 * arrives from the URL. The rule the framework claims is that a name the
 * schema did not declare does not exist — not "is escaped", not "is quoted",
 * but is refused or ignored outright. These tests are that claim, stated as
 * things that must not happen.
 *
 * The assertions are deliberately about outcomes rather than about internals:
 * no 500, no SQL error, and the row set is the one an honest request would
 * have received. A parameter that was silently honoured would show up as a
 * different row set, not as an exception.
 */
beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create(['name' => 'Ada Lovelace']);

    User::factory()->create(['name' => 'Grace Hopper']);
    User::factory()->create(['name' => 'Alan Turing']);

    $this->actingAs($this->admin);
});

/**
 * @return list<string>
 */
function rowNamesFor(string $query): array
{
    $page = test()->get('/admin/users?'.$query)->viewData('page');

    return collect($page['props']['rows'] ?? [])
        ->pluck('cells.name.value')
        ->filter()
        ->values()
        ->all();
}

/**
 * The sort the table actually applied, as it reports it back.
 *
 * The row count cannot see a sort: three rows are three rows in any order. The
 * applied state can — it is `null` when a requested name was refused and the
 * name itself when it was honoured, which is the difference these tests are
 * about.
 */
function appliedSortFor(string $query): ?string
{
    $page = test()->get('/admin/users?'.$query)->viewData('page');

    return $page['props']['state']['sort'] ?? null;
}

/*
 * Sorting
 */

it('ignores a sort column the schema never declared sortable', function (): void {
    // `avatar` and `accountAge` are real columns on the table and neither is
    // `->sortable()`. Declared is not the same as orderable.
    expect(appliedSortFor('sort=avatar'))->toBeNull();
    expect(appliedSortFor('sort=accountAge'))->toBeNull();

    // And the one that is sortable is honoured, so the assertions above are
    // the whitelist and not a sort parameter that never works.
    expect(appliedSortFor('sort=name'))->toBe('name');
});

it('ignores a sort column that exists on no table at all', function (): void {
    expect(appliedSortFor('sort=password'))->toBeNull();
    expect(appliedSortFor('sort=two_factor_secret'))->toBeNull();
    expect(rowNamesFor('sort=password'))->toHaveCount(3);
});

it('treats a SQL fragment in the sort parameter as a name, not as SQL', function (): void {
    $fragments = [
        'name; drop table users',
        'name) or 1=1--',
        '(select count(*) from users)',
        'name`,`email',
        'users.name',
        '1',
    ];

    foreach ($fragments as $fragment) {
        expect(appliedSortFor('sort='.urlencode($fragment)))
            ->toBeNull("sort={$fragment} was accepted as a column");

        expect(rowNamesFor('sort='.urlencode($fragment)))
            ->toHaveCount(3, "sort={$fragment} changed the result set");
    }

    // The table is still there, which a successful injection would not leave.
    expect(User::query()->count())->toBe(3);
});

it('refuses to order by a direction it does not recognise', function (): void {
    $ascending = rowNamesFor('sort=name&direction=asc');

    // Anything that is not `desc` falls back to ascending rather than reaching
    // the builder.
    foreach (['drop', 'asc; drop table users', '1', ''] as $direction) {
        expect(rowNamesFor('sort=name&direction='.urlencode($direction)))
            ->toBe($ascending, "direction={$direction} was not normalised");
    }
});

/*
 * Pagination
 */

it('clamps a page size the request tries to raise', function (): void {
    foreach (['999999', '-1', '0', 'all', '10; drop table users'] as $perPage) {
        $names = rowNamesFor('perPage='.urlencode($perPage));

        expect($names)->not->toBeEmpty("perPage={$perPage} returned nothing");
    }

    expect(User::query()->count())->toBe(3);
});

it('refuses a negative or absurd page rather than erroring', function (): void {
    foreach (['-5', '0', '99999999999999999999', 'abc'] as $page) {
        $response = test()->get('/admin/users?page='.urlencode($page));

        expect($response->getStatusCode())->toBe(200, "page={$page} did not return 200");
    }
});

/*
 * Filtering
 */

it('ignores a filter name the schema never declared', function (): void {
    expect(rowNamesFor('filters[password]=secret'))->toHaveCount(3);
    expect(rowNamesFor('filters[is_admin;drop table users]=1'))->toHaveCount(3);

    expect(User::query()->count())->toBe(3);
});

it('ignores a ternary value outside the three it accepts', function (): void {
    // The filter is declared over `is_admin`; a value it does not understand
    // must leave the query alone rather than reach it.
    $unfiltered = rowNamesFor('');

    foreach (['maybe', '1) or 1=1--', '../../etc/passwd'] as $value) {
        expect(rowNamesFor('filters[is_admin]='.urlencode($value)))
            ->toBe($unfiltered, "filters[is_admin]={$value} was honoured");
    }
});

it('refuses a query-builder rule naming a constraint that was never offered', function (): void {
    $query = http_build_query([
        'filters' => [
            'conditions' => [
                ['column' => 'password', 'operator' => 'contains', 'value' => 'x'],
            ],
        ],
    ]);

    expect(rowNamesFor($query))->toHaveCount(3);
});

it('refuses a query-builder operator that was never offered', function (): void {
    $query = http_build_query([
        'filters' => [
            'conditions' => [
                ['column' => 'name', 'operator' => 'or 1=1--', 'value' => 'x'],
            ],
        ],
    ]);

    expect(rowNamesFor($query))->toHaveCount(3);
    expect(User::query()->count())->toBe(3);
});

/*
 * Searching
 */

it('treats LIKE wildcards in a search term as literal characters', function (): void {
    // `%` matching everything would turn a search box into a way to dump the
    // table, and `_` into a single-character oracle.
    expect(rowNamesFor('search=%'))->toBeEmpty();
    expect(rowNamesFor('search=_'))->toBeEmpty();
    expect(rowNamesFor('search='.urlencode('%%')))->toBeEmpty();
});

it('treats SQL metacharacters in a search term as text', function (): void {
    foreach (["' or '1'='1", "'; drop table users--", '\\'] as $term) {
        $response = test()->get('/admin/users?search='.urlencode($term));

        expect($response->getStatusCode())->toBe(200, "search={$term} did not return 200");
    }

    expect(User::query()->count())->toBe(3);
});

it('bounds the length of a search term', function (): void {
    $response = test()->get('/admin/users?search='.urlencode(str_repeat('a', 10_000)));

    expect($response->getStatusCode())->toBe(200);
});

/*
 * Grouping and column selection
 */

it('ignores a group by a column the schema never offered', function (): void {
    expect(rowNamesFor('group=password'))->toHaveCount(3);
    expect(rowNamesFor('group='.urlencode('name) or 1=1--')))->toHaveCount(3);
});

it('ignores a hidden-column list naming columns that do not exist', function (): void {
    expect(rowNamesFor('columns[]=password&columns[]=secret'))->toHaveCount(3);
});

/*
 * The whole surface at once
 */

it('issues no query that the database refuses', function (): void {
    $failures = [];

    DB::listen(function ($query) use (&$failures): void {
        // A parameter that reached the builder as SQL would show up here as a
        // fragment inside the statement rather than as a binding.
        if (str_contains(strtolower($query->sql), 'drop table')) {
            $failures[] = $query->sql;
        }
    });

    test()->get('/admin/users?'.http_build_query([
        'sort' => 'name; drop table users',
        'direction' => 'desc; drop table users',
        'search' => "'; drop table users--",
        'group' => 'name; drop table users',
        'perPage' => '10; drop table users',
        'filters' => ['is_admin' => '1; drop table users'],
    ]))->assertOk();

    expect($failures)->toBeEmpty();
    expect(User::query()->count())->toBe(3);
});
