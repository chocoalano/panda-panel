<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use PandaPanel\Core\PanelManager;
use PandaPanel\Exceptions\PanelRegistrationException;
use PandaPanel\Support\PanelContext;

it('has no current panel before a request resolves one', function (): void {
    expect(app(PanelManager::class)->currentPanel())->toBeNull()
        ->and(panel())->toBeNull();
});

it('exposes the current panel to backend code during a panel request', function (): void {
    $this->actingAs(User::factory()->admin()->create())->get('/admin');

    expect(app(PanelManager::class)->currentPanel()?->getId())->toBe('admin')
        ->and(panel()?->getId())->toBe('admin');
});

it('does not set a current panel for non-panel routes', function (): void {
    $this->actingAs(User::factory()->create())->get('/dashboard');

    expect(app(PanelManager::class)->currentPanel())->toBeNull();
});

it('resolves an explicit panel through the helper', function (): void {
    expect(panel('admin')?->getId())->toBe('admin')
        ->and(panel('app')?->getPath())->toBe('app');
});

it('throws when the helper is given an unknown panel', function (): void {
    expect(fn () => panel('nope'))
        ->toThrow(PanelRegistrationException::class);
});

it('resolves a panel from a request path', function (): void {
    $manager = app(PanelManager::class);

    expect($manager->resolveFromRequest(Request::create('/admin'))?->getId())->toBe('admin')
        ->and($manager->resolveFromRequest(Request::create('/admin/users/3/edit'))?->getId())->toBe('admin')
        ->and($manager->resolveFromRequest(Request::create('/app'))?->getId())->toBe('app')
        ->and($manager->resolveFromRequest(Request::create('/dashboard')))->toBeNull()
        ->and($manager->resolveFromRequest(Request::create('/administrators')))->toBeNull();
});

it('keeps context out of static state so it cannot leak between requests', function (): void {
    $context = app(PanelContext::class);
    $context->setPanel(app(PanelManager::class)->get('admin'));

    expect($context->hasPanel())->toBeTrue();

    $context->forget();

    expect($context->hasPanel())->toBeFalse()
        ->and($context->panel())->toBeNull();
});

it('carries arbitrary request scoped context for future tenancy', function (): void {
    $context = app(PanelContext::class);

    expect($context->get('tenant'))->toBeNull();

    $context->set('tenant', 'acme');

    expect($context->get('tenant'))->toBe('acme');
});
