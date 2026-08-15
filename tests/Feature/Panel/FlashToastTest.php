<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;

/**
 * Inertia puts flash data beside `props` on the page object, not inside it,
 * which is why these assertions read `viewData('page')` rather than using
 * the Inertia prop assertions.
 *
 * @return array<string, mixed>|null
 */
function flashedToast(TestResponse $response): ?array
{
    $page = $response->viewData('page');

    $toast = is_array($page) ? ($page['flash']['toast'] ?? null) : null;

    return is_array($toast) ? $toast : null;
}

beforeEach(function (): void {
    Route::middleware('web')->group(function (): void {
        Route::get('/__test/flash-success', fn () => redirect('/')->with('success', 'Saved.'));
        Route::get('/__test/flash-error', fn () => redirect('/')->with('error', 'Went wrong.'));
        Route::get('/__test/flash-both', fn () => redirect('/')
            ->with('success', 'Saved.')
            ->with('error', 'Went wrong.'));
        Route::get('/__test/flash-explicit', function () {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Explicit.']);

            return redirect('/')->with('success', 'Ignored.');
        });
        Route::get('/__test/flash-none', fn () => redirect('/'));
    });

    $this->actingAs(User::factory()->create());
});

it('maps a conventional success flash onto the toast channel', function (): void {
    $this->get('/__test/flash-success')->assertRedirect('/');

    expect(flashedToast($this->get('/')))
        ->toBe(['type' => 'success', 'message' => 'Saved.']);
});

it('maps an error flash onto the toast channel', function (): void {
    $this->get('/__test/flash-error');

    expect(flashedToast($this->get('/')))
        ->toBe(['type' => 'error', 'message' => 'Went wrong.']);
});

it('surfaces the more severe message when a request flashes two', function (): void {
    $this->get('/__test/flash-both');

    expect(flashedToast($this->get('/')))
        ->toBe(['type' => 'error', 'message' => 'Went wrong.']);
});

it('never overrides an explicitly flashed toast', function (): void {
    $this->get('/__test/flash-explicit');

    expect(flashedToast($this->get('/')))
        ->toBe(['type' => 'info', 'message' => 'Explicit.']);
});

it('adds no toast when nothing was flashed', function (): void {
    $this->get('/__test/flash-none');

    expect(flashedToast($this->get('/')))->toBeNull();
});

it('uses a payload shape the existing frontend bridge already understands', function (): void {
    $this->get('/__test/flash-success');

    $toast = flashedToast($this->get('/'));

    expect($toast)->toHaveKeys(['type', 'message'])
        ->and($toast['type'])->toBeIn(['success', 'info', 'warning', 'error']);
});
