<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Wayfinder symbol collisions
|--------------------------------------------------------------------------
|
| Wayfinder generates one TypeScript module per route-name namespace, and
| names each helper after the *last* segment of the route name, camel-cased
| when it is hyphenated. With `formVariants` enabled it emits a second
| declaration per route, the same name with `Form` appended.
|
| So a namespace holding both `action` and `action-form` declares
| `const actionForm` twice in one module — the form variant of the first and
| the route helper of the second — and TypeScript rejects the file with
| TS2451. No per-route filter, rename or transform exists in Wayfinder
| (v0.1.21), so the collision can only be avoided by not registering two
| route names that reduce to the same symbol.
|
| These tests own that constraint on Wayfinder's behalf: they reproduce its
| naming rule against the routes this package actually registers, so a future
| route name that would break a consumer's `tsc` fails here first.
|
*/

/**
 * Wayfinder's `TypeScript::safeMethod()`: non-word characters become `_`, and
 * a hyphenated name is camel-cased.
 */
function wayfinderSymbol(string $routeName): string
{
    $method = Str::of(Str::afterLast($routeName, '.'))
        ->replaceMatches('/[^\p{L}\p{Nd}_$-]/u', '_');

    if ($method->contains('-')) {
        $method = $method->camel();
    }

    return $method->toString();
}

/**
 * Every declaration Wayfinder writes for one route with `formVariants: true`:
 * the route helper and its form variant.
 *
 * @return array<int, string>
 */
function wayfinderDeclarations(string $routeName): array
{
    $symbol = wayfinderSymbol($routeName);

    return [$symbol, $symbol.'Form'];
}

/**
 * The panel routes this package registers, grouped the way Wayfinder groups
 * them into modules — by everything before the last name segment.
 *
 * @return array<string, array<int, string>>
 */
function panelRouteNamespaces(): array
{
    $namespaces = [];

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();

        if ($name === null || ! str_starts_with($name, 'panel.')) {
            continue;
        }

        // A namespace needs at least one segment in front of the symbol.
        if (! str_contains($name, '.')) {
            continue;
        }

        $namespaces[Str::beforeLast($name, '.')][] = $name;
    }

    return $namespaces;
}

it('never registers two panel routes whose Wayfinder declarations collide', function (): void {
    $collisions = [];

    foreach (panelRouteNamespaces() as $namespace => $names) {
        $declaredBy = [];

        foreach (array_unique($names) as $name) {
            foreach (wayfinderDeclarations($name) as $declaration) {
                $declaredBy[$declaration][] = $name;
            }
        }

        foreach ($declaredBy as $declaration => $owners) {
            if (count(array_unique($owners)) > 1) {
                $collisions[] = sprintf(
                    '%s declares `%s` from %s',
                    $namespace,
                    $declaration,
                    implode(' and ', array_unique($owners)),
                );
            }
        }
    }

    expect($collisions)->toBe([]);
});

it('gives the relation action route and the relation action form route distinct Wayfinder symbols', function (): void {
    $action = wayfinderSymbol('panel.admin.relations.action');
    $form = wayfinderSymbol('panel.admin.relations.'.relationActionFormRouteSuffix());

    expect($form)->not->toBe($action.'Form')
        ->and($form)->not->toBe($action);
});

/**
 * The last segment of the route name the relation action-form endpoint is
 * registered under, read back off the router rather than restated, so this
 * test follows a rename instead of outliving one.
 */
function relationActionFormRouteSuffix(): string
{
    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();

        if ($name === null || ! str_starts_with($name, 'panel.admin.relations.')) {
            continue;
        }

        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        if ($route->uri() !== trim(panelAdminPrefix().'/relations/action-form', '/')) {
            continue;
        }

        return Str::afterLast($name, '.');
    }

    throw new RuntimeException('The relation action-form route is no longer registered.');
}

function panelAdminPrefix(): string
{
    return 'admin';
}
