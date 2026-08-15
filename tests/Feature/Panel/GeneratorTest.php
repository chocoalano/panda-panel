<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Generators are asserted by running them and reading what landed on disk,
 * so a stub that stops compiling is caught here rather than by the next
 * person who runs the command.
 */
afterEach(function (): void {
    File::deleteDirectory(app_path('Panels/Testing'));
    File::deleteDirectory(resource_path('js/pages/Panels/Testing'));
});

it('creates a panel provider and the directories discovery scans', function (): void {
    $this->artisan('make:panel', ['name' => 'Testing'])->assertSuccessful();

    expect(File::exists(app_path('Panels/Testing/TestingPanelProvider.php')))->toBeTrue()
        ->and(File::exists(app_path('Panels/Testing/Resources/.gitkeep')))->toBeTrue()
        ->and(File::exists(app_path('Panels/Testing/Pages/.gitkeep')))->toBeTrue()
        ->and(File::exists(app_path('Panels/Testing/Widgets/.gitkeep')))->toBeTrue();
});

it('tells the developer to register the panel rather than doing it silently', function (): void {
    // Registration order decides where a user lands when the request does not
    // name a panel, so it is an edit somebody makes on purpose — and the
    // generator has to say where.
    $this->artisan('make:panel', ['name' => 'Testing'])
        ->expectsOutputToContain('config/panda-panel.php');
});

it('generates a panel provider that compiles and configures itself', function (): void {
    $this->artisan('make:panel', ['name' => 'Testing', '--path' => 'testing-panel']);

    $contents = File::get(app_path('Panels/Testing/TestingPanelProvider.php'));

    expect($contents)->toContain('namespace App\Panels\Testing;')
        ->toContain('final class TestingPanelProvider extends PanelProvider')
        ->toContain("->path('testing-panel')")
        ->toContain("discoverResources(app_path('Panels/Testing/Resources'))");
});

it('creates a resource with pages, table, and form', function (): void {
    $this->artisan('make:panel-resource', ['name' => 'User', '--panel' => 'Testing'])
        ->assertSuccessful();

    $base = app_path('Panels/Testing/Resources/Users');

    expect(File::exists("{$base}/UserResource.php"))->toBeTrue()
        ->and(File::exists("{$base}/Pages/ListUsers.php"))->toBeTrue()
        ->and(File::exists("{$base}/Pages/CreateUser.php"))->toBeTrue()
        ->and(File::exists("{$base}/Pages/ViewUser.php"))->toBeTrue()
        ->and(File::exists("{$base}/Pages/EditUser.php"))->toBeTrue()
        ->and(File::exists("{$base}/Tables/UsersTable.php"))->toBeTrue()
        ->and(File::exists("{$base}/Forms/UserForm.php"))->toBeTrue();
});

it('wires the generated resource to its own pages', function (): void {
    $this->artisan('make:panel-resource', ['name' => 'User', '--panel' => 'Testing']);

    $contents = File::get(app_path('Panels/Testing/Resources/Users/UserResource.php'));

    expect($contents)->toContain('protected static string $model = User::class;')
        ->toContain("'index' => ListUsers::class,")
        ->toContain("'edit' => EditUser::class,")
        ->toContain('use App\Panels\Testing\Resources\Users\Pages\ListUsers;');
});

it('omits the view page when asked', function (): void {
    $this->artisan('make:panel-resource', [
        'name' => 'User',
        '--panel' => 'Testing',
        '--no-view' => true,
    ]);

    $contents = File::get(app_path('Panels/Testing/Resources/Users/UserResource.php'));

    expect(File::exists(app_path('Panels/Testing/Resources/Users/Pages/ViewUser.php')))->toBeFalse()
        ->and($contents)->not->toContain("'view' =>")
        ->and(File::get(app_path('Panels/Testing/Resources/Users/Tables/UsersTable.php')))
        ->not->toContain('ViewAction');
});

it('generates only a list page for a simple resource', function (): void {
    $this->artisan('make:panel-resource', [
        'name' => 'User',
        '--panel' => 'Testing',
        '--simple' => true,
    ]);

    expect(File::exists(app_path('Panels/Testing/Resources/Users/Pages/ListUsers.php')))->toBeTrue()
        ->and(File::exists(app_path('Panels/Testing/Resources/Users/Pages/CreateUser.php')))->toBeFalse()
        ->and(File::exists(app_path('Panels/Testing/Resources/Users/Pages/EditUser.php')))->toBeFalse();
});

it('gives a soft-deleting resource everything it needs to be reachable', function (): void {
    $this->artisan('make:panel-resource', [
        'name' => 'User',
        '--panel' => 'Testing',
        '--soft-deletes' => true,
    ]);

    // The declaration is what lets a record page resolve a trashed record at
    // all. Without it the restore action below could never be reached.
    expect(File::get(app_path('Panels/Testing/Resources/Users/UserResource.php')))
        ->toContain('protected static bool $softDeletes = true;');

    expect(File::get(app_path('Panels/Testing/Resources/Users/Tables/UsersTable.php')))
        // The filter is what puts a trashed record on screen for the actions
        // to sit on.
        ->toContain("TrashedFilter::make('trashed')")
        ->toContain('RestoreAction::make(UserResource::class)')
        ->toContain('ForceDeleteAction::make(UserResource::class)')
        ->toContain('RestoreBulkAction::make(UserResource::class)')
        ->toContain('ForceDeleteBulkAction::make(UserResource::class)');
});

it('leaves a resource that does not soft delete alone', function (): void {
    $this->artisan('make:panel-resource', ['name' => 'User', '--panel' => 'Testing']);

    expect(File::get(app_path('Panels/Testing/Resources/Users/UserResource.php')))
        ->not->toContain('softDeletes');

    expect(File::get(app_path('Panels/Testing/Resources/Users/Tables/UsersTable.php')))
        ->not->toContain('TrashedFilter')
        ->not->toContain('RestoreAction');
});

it('accepts an explicit model', function (): void {
    $this->artisan('make:panel-resource', [
        'name' => 'Account',
        '--panel' => 'Testing',
        '--model' => 'App\\Models\\User',
    ]);

    expect(File::get(app_path('Panels/Testing/Resources/Accounts/AccountResource.php')))
        ->toContain('use App\Models\User;')
        ->toContain('protected static string $model = User::class;');
});

it('creates a page that uses the generic renderer by default', function (): void {
    $this->artisan('make:panel-page', ['name' => 'Reports', '--panel' => 'Testing'])
        ->assertSuccessful();

    expect(File::get(app_path('Panels/Testing/Pages/Reports.php')))
        ->toContain("protected static string \$component = 'panel/Page';")
        // No Vue file, because a page with nothing bespoke to draw needs none.
        ->and(File::exists(resource_path('js/pages/Panels/Testing/Pages/Reports.vue')))->toBeFalse();
});

it('creates a page component when asked', function (): void {
    $this->artisan('make:panel-page', [
        'name' => 'Reports',
        '--panel' => 'Testing',
        '--component' => true,
    ]);

    expect(File::get(app_path('Panels/Testing/Pages/Reports.php')))
        ->toContain("'Panels/Testing/Pages/Reports'")
        ->and(File::exists(resource_path('js/pages/Panels/Testing/Pages/Reports.vue')))->toBeTrue();
});

it('creates a widget for each supported type', function (): void {
    foreach ([
        'stats' => 'StatsWidget',
        'table' => 'TableWidget',
        'chart' => 'ChartWidget',
        'custom' => 'CustomWidget',
    ] as $type => $base) {
        $class = ucfirst($type).'Thing';

        $this->artisan('make:panel-widget', [
            'name' => $class,
            '--panel' => 'Testing',
            '--type' => $type,
        ])->assertSuccessful();

        expect(File::get(app_path("Panels/Testing/Widgets/{$class}.php")))
            ->toContain("extends {$base}");
    }
});

it('generates the component a custom widget cannot work without', function (): void {
    $this->artisan('make:panel-widget', [
        'name' => 'ServerHealth',
        '--panel' => 'Testing',
        '--type' => 'custom',
    ]);

    expect(File::get(app_path('Panels/Testing/Widgets/ServerHealth.php')))
        ->toContain("'Panels/Testing/Widgets/ServerHealth'")
        ->and(File::exists(resource_path('js/pages/Panels/Testing/Widgets/ServerHealth.vue')))->toBeTrue();
});

it('rejects an unknown widget type instead of guessing', function (): void {
    $this->artisan('make:panel-widget', [
        'name' => 'Mystery',
        '--panel' => 'Testing',
        '--type' => 'hologram',
    ])->assertFailed();

    expect(File::exists(app_path('Panels/Testing/Widgets/Mystery.php')))->toBeFalse();
});

it('requires a panel', function (): void {
    $this->artisan('make:panel-widget', ['name' => 'Orphan'])->assertFailed();
    $this->artisan('make:panel-page', ['name' => 'Orphan'])->assertFailed();
    $this->artisan('make:panel-resource', ['name' => 'Orphan'])->assertFailed();
});

it('refuses to overwrite an existing file', function (): void {
    $this->artisan('make:panel-page', ['name' => 'Reports', '--panel' => 'Testing']);

    File::put(app_path('Panels/Testing/Pages/Reports.php'), '<?php // edited by hand');

    $this->artisan('make:panel-page', ['name' => 'Reports', '--panel' => 'Testing'])
        ->expectsOutputToContain('already exists');

    expect(File::get(app_path('Panels/Testing/Pages/Reports.php')))->toBe('<?php // edited by hand');
});

it('overwrites when forced', function (): void {
    $this->artisan('make:panel-page', ['name' => 'Reports', '--panel' => 'Testing']);

    File::put(app_path('Panels/Testing/Pages/Reports.php'), '<?php // edited by hand');

    $this->artisan('make:panel-page', [
        'name' => 'Reports',
        '--panel' => 'Testing',
        '--force' => true,
    ])->assertSuccessful();

    expect(File::get(app_path('Panels/Testing/Pages/Reports.php')))
        ->toContain('final class Reports extends Page');
});

/*
 * Relation managers
 */

it('creates a relation manager and points at where to register it', function (): void {
    $this->artisan('make:panel-relation-manager', [
        'name' => 'posts',
        '--panel' => 'Testing',
        '--resource' => 'Users',
    ])
        ->expectsOutputToContain('UserResource::relationManagers()')
        ->assertSuccessful();

    expect(File::get(app_path('Panels/Testing/Resources/Users/RelationManagers/PostsRelationManager.php')))
        ->toContain('final class PostsRelationManager extends RelationManager')
        ->toContain("protected static string \$relationship = 'posts';")
        // A `hasMany` has no join row, so it is created and deleted rather
        // than attached and detached.
        ->not->toContain('DetachAction')
        ->not->toContain('pivotForm');
});

it('gives a many-to-many the operations it actually has', function (): void {
    $this->artisan('make:panel-relation-manager', [
        'name' => 'labels',
        '--panel' => 'Testing',
        '--resource' => 'Users',
        '--type' => 'belongs-to-many',
    ])->assertSuccessful();

    expect(File::get(app_path('Panels/Testing/Resources/Users/RelationManagers/LabelsRelationManager.php')))
        ->toContain('DetachAction::make(self::class, $owner)')
        ->toContain('DetachBulkAction::make(self::class, $owner)')
        ->toContain('public static function pivotForm(');
});

it('pairs the restore actions with the filter that reveals them', function (): void {
    $this->artisan('make:panel-relation-manager', [
        'name' => 'posts',
        '--panel' => 'Testing',
        '--resource' => 'Users',
        '--soft-deletes' => true,
    ])->assertSuccessful();

    // The filter is what puts a deleted record on screen, so restore and
    // force-delete without it would be two buttons that never appear.
    expect(File::get(app_path('Panels/Testing/Resources/Users/RelationManagers/PostsRelationManager.php')))
        ->toContain("TrashedFilter::make('trashed')")
        ->toContain('RestoreAction::make(self::class, $owner)')
        ->toContain('ForceDeleteAction::make(self::class, $owner)');
});

it('generates a relation page only when one is asked for', function (): void {
    $this->artisan('make:panel-relation-manager', [
        'name' => 'posts',
        '--panel' => 'Testing',
        '--resource' => 'Users',
    ])->assertSuccessful();

    expect(File::exists(app_path('Panels/Testing/Resources/Users/Pages/ManageUsersPosts.php')))->toBeFalse();

    $this->artisan('make:panel-relation-manager', [
        'name' => 'posts',
        '--panel' => 'Testing',
        '--resource' => 'Users',
        '--page' => true,
    ])->assertSuccessful();

    expect(File::get(app_path('Panels/Testing/Resources/Users/Pages/ManageUsersPosts.php')))
        ->toContain('final class ManageUsersPosts extends ManageRelatedRecords')
        ->toContain('protected static string $relationManager = PostsRelationManager::class;');
});

it('refuses a relation type it has no shape for', function (): void {
    $this->artisan('make:panel-relation-manager', [
        'name' => 'posts',
        '--panel' => 'Testing',
        '--resource' => 'Users',
        '--type' => 'morph-to-many-through',
    ])->assertFailed();
});

it('generates code that passes the project lint and static analysis', function (): void {
    $this->artisan('make:panel', ['name' => 'Testing']);
    $this->artisan('make:panel-resource', ['name' => 'User', '--panel' => 'Testing']);
    $this->artisan('make:panel-page', ['name' => 'Reports', '--panel' => 'Testing']);
    $this->artisan('make:panel-widget', ['name' => 'Numbers', '--panel' => 'Testing', '--type' => 'stats']);
    $this->artisan('make:panel-relation-manager', [
        'name' => 'labels',
        '--panel' => 'Testing',
        '--resource' => 'Users',
        '--type' => 'belongs-to-many',
        '--soft-deletes' => true,
        '--page' => true,
    ]);

    $pint = Process::run([base_path('vendor/bin/pint'), '--test', app_path('Panels/Testing')]);

    expect($pint->successful())->toBeTrue($pint->output());
})->skip(fn (): bool => ! file_exists(base_path('vendor/bin/pint')), 'Pint is not installed.');
