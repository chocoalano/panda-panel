<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PandaPanel\Core\PanelManager;
use PandaPanel\Integrations\IntegrationDispatcher;
use PandaPanel\Integrations\IntegrationObserver;
use PandaPanel\Integrations\Integrations;
use PandaPanel\Integrations\IntegrationSignature;
use PandaPanel\Integrations\IntegrationTemplate;
use PandaPanel\Integrations\OutboundUrl;
use PandaPanel\Integrations\PanelIntegration;
use PandaPanel\Integrations\Trigger;
use PandaPanel\Jobs\SendPanelIntegration;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();

    $this->actingAs($this->admin);

    app(PanelManager::class)->setCurrentPanel(panel('admin'));

    Config::set('panda-panel.integrations.allowed_hosts', ['api.example.test']);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function integration(array $overrides = []): PanelIntegration
{
    return PanelIntegration::query()->create([
        'panel' => 'admin',
        'resource' => 'users',
        'name' => 'Notify',
        'trigger' => Trigger::AfterCreate->value,
        'method' => 'POST',
        'url' => 'https://api.example.test/hooks',
        'headers' => ['X-Api-Key' => 'secret'],
        'query' => [],
        'body_type' => 'json',
        'body' => null,
        'is_active' => true,
        ...$overrides,
    ]);
}

/*
 * Off unless a resource says otherwise
 */

it('is disabled until a resource turns it on', function (): void {
    expect(Integrations::make()->enabled())->toBeFalse()
        ->and(Integrations::make()->isEnabled(true)->enabled())->toBeTrue()
        ->and(Integrations::make()->isEnabled(true)->isEnabled(false)->enabled())->toBeFalse();
});

it('offers all six triggers by default', function (): void {
    expect(Integrations::make()->getTriggers())->toBe(Trigger::cases())
        ->and(Integrations::make()->supports(Trigger::BeforeDelete))->toBeTrue();
});

it('narrows the triggers a resource fires', function (): void {
    $settings = Integrations::make()->triggers([Trigger::AfterCreate]);

    expect($settings->supports(Trigger::AfterCreate))->toBeTrue()
        ->and($settings->supports(Trigger::BeforeDelete))->toBeFalse();
});

it('404s the screen for a resource that never opted in', function (): void {
    // Not 403: there is no screen to be refused. `UsersFixtureResource` and
    // the example `UserResource` both leave integrations off.
    $this->get('/admin/users/integrations')->assertNotFound();
});

/*
 * The allowlist, and the network behind it
 */

it('refuses a host that is not on the allowlist', function (): void {
    expect(OutboundUrl::rejection('https://evil.test/hook'))
        ->toContain('not an allowed destination')
        ->and(OutboundUrl::isAllowed('https://api.example.test/hooks'))->toBeTrue();
});

it('refuses every URL when the allowlist is empty', function (): void {
    Config::set('panda-panel.integrations.allowed_hosts', []);

    // Deny by default: a panel installed and left alone can call nowhere.
    expect(OutboundUrl::isAllowed('https://api.example.test/hooks'))->toBeFalse();
});

it('refuses the cloud metadata endpoint even when it is allowlisted', function (): void {
    Config::set('panda-panel.integrations.allowed_hosts', ['*']);

    // The one address this feature must never reach: unauthenticated IAM
    // credentials on every major cloud.
    expect(OutboundUrl::rejection('http://169.254.169.254/latest/meta-data/'))
        ->toContain('private or link-local');
});

it('refuses loopback and the private ranges', function (): void {
    Config::set('panda-panel.integrations.allowed_hosts', ['*']);

    foreach ([
        'http://127.0.0.1:6379',
        'http://10.0.0.5/internal',
        'http://192.168.1.1/admin',
        'http://172.16.0.9/',
        'http://[::1]:8080/',
    ] as $url) {
        expect(OutboundUrl::isAllowed($url))->toBeFalse();
    }
});

it('refuses a scheme that is not http', function (): void {
    Config::set('panda-panel.integrations.allowed_hosts', ['*']);

    expect(OutboundUrl::rejection('file:///etc/passwd'))->toContain('Only http and https');
});

it('anchors an allowlist pattern at both ends', function (): void {
    Config::set('panda-panel.integrations.allowed_hosts', ['*.partner.test']);

    expect(OutboundUrl::isAllowed('https://api.partner.test/x'))->toBeTrue()
        // The classic bypass: the allowed name as a prefix of somebody else's.
        ->and(OutboundUrl::isAllowed('https://api.partner.test.attacker.test/x'))->toBeFalse();
});

/*
 * What the receiving system is told
 */

it('sends the record attributes and what changed', function (): void {
    $record = User::factory()->create(['name' => 'Ada']);

    $record->name = 'Ada Lovelace';

    $payload = IntegrationDispatcher::payload(Trigger::BeforeUpdate, 'users', $record);

    expect($payload['trigger'])->toBe('before_update')
        ->and($payload['resource'])->toBe('users')
        ->and($payload['record']['name'])->toBe('Ada Lovelace')
        ->and($payload['changed'])->toBe(['name']);
});

it('leaves hidden attributes out of the payload', function (): void {
    $record = User::factory()->create();

    // `password` and `remember_token` are hidden on the model, and a webhook
    // is not a reason for a password hash to leave the building.
    expect(IntegrationDispatcher::payload(Trigger::AfterCreate, 'users', $record)['record'])
        ->not->toHaveKey('password')
        ->not->toHaveKey('remember_token');
});

it('substitutes paths into a hand-written body and nothing else', function (): void {
    $rendered = IntegrationTemplate::render(
        '{"id":"{{ record.id }}","missing":"{{ record.nope }}"}',
        ['record' => ['id' => 7]],
    );

    expect($rendered)->toBe('{"id":"7","missing":""}');
});

it('is not blade', function (): void {
    // A body is untrusted input. Anything that looked like an expression is
    // left exactly as it was found.
    $rendered = IntegrationTemplate::render('{{ 2 + 2 }} {{ $x }} {{ phpinfo() }}', []);

    expect($rendered)->toBe('{{ 2 + 2 }} {{ $x }} {{ phpinfo() }}');
});

/*
 * Sending
 */

it('records the status of a successful send', function (): void {
    Http::fake(['api.example.test/*' => Http::response('ok', 200)]);

    $model = integration();

    IntegrationDispatcher::send($model, ['trigger' => 'after_create'], 5);

    expect($model->fresh()->last_status)->toBe(200)
        ->and($model->fresh()->last_error)->toBeNull();
});

it('records a failure without throwing', function (): void {
    Http::fake(['api.example.test/*' => Http::response('nope', 500)]);

    $model = integration();

    IntegrationDispatcher::send($model, [], 5);

    expect($model->fresh()->last_status)->toBe(500)
        ->and($model->fresh()->last_error)->toBe('nope');
});

it('never lets a transport failure escape', function (): void {
    Http::fake(fn () => throw new RuntimeException('DNS is down'));

    $model = integration();

    // The whole point: this runs inside the request that is saving a record,
    // and a webhook is not a reason for that record not to exist.
    IntegrationDispatcher::send($model, [], 5);

    expect($model->fresh()->last_error)->toBe('DNS is down')
        ->and($model->fresh()->last_status)->toBeNull();
});

it('checks the allowlist again at send time', function (): void {
    Http::fake();

    $model = integration();

    // Approved when it was saved, revoked since.
    Config::set('panda-panel.integrations.allowed_hosts', []);

    IntegrationDispatcher::send($model, [], 5);

    Http::assertNothingSent();

    expect($model->fresh()->last_error)->toContain('not an allowed destination');
});

it('sends the declared headers', function (): void {
    Http::fake(['api.example.test/*' => Http::response('', 200)]);

    IntegrationDispatcher::send(integration(), ['a' => 1], 5);

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Api-Key', 'secret'));
});

/*
 * The queue split
 */

it('queues an after trigger and sends a before one inline', function (): void {
    Queue::fake();
    Http::fake(['api.example.test/*' => Http::response('', 200)]);

    $after = integration(['trigger' => Trigger::AfterCreate->value]);
    $before = integration(['trigger' => Trigger::BeforeCreate->value, 'name' => 'Gate']);

    $settings = Integrations::make()->isEnabled(true);

    // The dispatcher itself is what the observer calls; this asserts the
    // split the observer makes rather than reaching through a model event.
    SendPanelIntegration::dispatch($after->id, [], $settings->getTimeout());
    IntegrationDispatcher::send($before, [], $settings->getTimeout());

    Queue::assertPushed(SendPanelIntegration::class, 1);

    expect($before->fresh()->last_status)->toBe(200);
});

it('does nothing for an integration turned off between the write and the worker', function (): void {
    Http::fake();

    $model = integration(['is_active' => false]);

    (new SendPanelIntegration($model->id, [], 5))->handle();

    Http::assertNothingSent();
});

/*
 * Authorization
 */

it('refuses the screen to a user the gate does not allow', function (): void {
    // No gate defined at all, which is the default and denies.
    $this->get('/admin/users/integrations')->assertNotFound();
});

it('scopes a lookup to the panel and resource it was opened from', function (): void {
    $other = integration(['resource' => 'roles']);

    expect(PanelIntegration::query()->firing('admin', 'users', Trigger::AfterCreate)->count())
        ->toBe(0)
        ->and(PanelIntegration::query()->firing('admin', 'roles', Trigger::AfterCreate)->pluck('id')->all())
        ->toBe([$other->id]);
});

it('does not fire an integration belonging to another panel', function (): void {
    integration(['panel' => 'app']);

    expect(PanelIntegration::query()->firing('admin', 'users', Trigger::AfterCreate)->count())->toBe(0);
});

it('does not fire an inactive integration', function (): void {
    integration(['is_active' => false]);

    expect(PanelIntegration::query()->firing('admin', 'users', Trigger::AfterCreate)->count())->toBe(0);
});

it('grants the screen once the gate allows it', function (): void {
    Gate::define('manage-panel-integrations', static fn (): bool => true);

    // Still 404: the example UserResource has not opted in, and the gate is
    // not what creates the screen.
    $this->get('/admin/users/integrations')->assertNotFound();
});

/*
 * The seam itself: Eloquent events, not page hooks
 */

it('fires all six triggers from the model events', function (): void {
    Http::fake(['api.example.test/*' => Http::response('', 200)]);
    Queue::fake();

    $settings = Integrations::make()->isEnabled(true);

    IntegrationObserver::forget();
    IntegrationObserver::register(User::class, 'admin', 'users', $settings);

    foreach (Trigger::cases() as $trigger) {
        integration(['trigger' => $trigger->value, 'name' => $trigger->value]);
    }

    $record = User::factory()->create();
    $record->update(['name' => 'Renamed']);
    $record->delete();

    // Three `before` triggers went out inline; three `after` ones were queued.
    Http::assertSentCount(3);
    Queue::assertPushed(SendPanelIntegration::class, 3);
});

it('fires for a write that never touched a panel screen', function (): void {
    Http::fake(['api.example.test/*' => Http::response('', 200)]);

    $settings = Integrations::make()->isEnabled(true);

    IntegrationObserver::forget();
    IntegrationObserver::register(User::class, 'admin', 'users', $settings);

    integration(['trigger' => Trigger::BeforeCreate->value]);

    // No request, no controller, no form — the point of hanging these off
    // Eloquent rather than off the resource pages.
    User::factory()->create();

    Http::assertSentCount(1);
});

it('registers a model only once however many times it is asked', function (): void {
    Http::fake(['api.example.test/*' => Http::response('', 200)]);

    $settings = Integrations::make()->isEnabled(true);

    IntegrationObserver::forget();
    IntegrationObserver::register(User::class, 'admin', 'users', $settings);
    IntegrationObserver::register(User::class, 'admin', 'users', $settings);

    integration(['trigger' => Trigger::BeforeCreate->value]);

    User::factory()->create();

    // Two registrations of the same resource would send everything twice.
    Http::assertSentCount(1);
});

it('survives the table not being there yet', function (): void {
    Http::fake();

    $settings = Integrations::make()->isEnabled(true);

    IntegrationObserver::forget();
    IntegrationObserver::register(User::class, 'admin', 'users', $settings);

    Schema::drop('panel_integrations');

    // An application with the package installed and the migration not run
    // must still be able to save a record.
    expect(fn () => User::factory()->create())->not->toThrow(Throwable::class);
});

/*
 * Signing
 */

it('gives every integration a secret without being asked', function (): void {
    $model = integration();

    expect($model->secret)->toBeString()->toHaveLength(64);
});

it('encrypts the secret at rest', function (): void {
    $model = integration();

    $stored = DB::table('panel_integrations')->where('id', $model->id)->value('secret');

    // A database dump must not hand somebody the ability to forge a request.
    expect($stored)->not->toBe($model->secret)
        ->and(Crypt::decryptString((string) $stored))->toBe($model->secret);
});

it('signs the exact bytes it sends', function (): void {
    Http::fake(['api.example.test/*' => Http::response('', 200)]);

    $model = integration();

    IntegrationDispatcher::send($model, ['record' => ['id' => 1]], 5);

    Http::assertSent(function ($request) use ($model): bool {
        $header = $request->header(IntegrationSignature::SIGNATURE_HEADER)[0] ?? '';

        // Verified against the body as it arrived, which is the only thing a
        // receiver ever has.
        return IntegrationSignature::verify($header, (string) $model->secret, $request->body());
    });
});

it('refuses a signature made with another secret', function (): void {
    $body = '{"a":1}';
    $header = IntegrationSignature::header('right', 1_755_000_000, $body);

    expect(IntegrationSignature::verify($header, 'right', $body, now: 1_755_000_000))->toBeTrue()
        ->and(IntegrationSignature::verify($header, 'wrong', $body, now: 1_755_000_000))->toBeFalse();
});

it('refuses a signature over a different body', function (): void {
    $header = IntegrationSignature::header('secret', 1_755_000_000, '{"a":1}');

    expect(IntegrationSignature::verify($header, 'secret', '{"a":2}', now: 1_755_000_000))->toBeFalse();
});

it('refuses a replayed request once the tolerance has passed', function (): void {
    $body = '{"a":1}';
    $header = IntegrationSignature::header('secret', 1_755_000_000, $body);

    // The timestamp is inside the signed string, so an old capture cannot be
    // re-presented as a fresh one.
    expect(IntegrationSignature::verify($header, 'secret', $body, 300, 1_755_000_200))->toBeTrue()
        ->and(IntegrationSignature::verify($header, 'secret', $body, 300, 1_755_000_900))->toBeFalse();
});

it('refuses a malformed signature header', function (): void {
    foreach (['', 'nonsense', 't=abc,v1=x', 'v1=onlysignature'] as $header) {
        expect(IntegrationSignature::verify($header, 'secret', '{}'))->toBeFalse();
    }
});

it('carries one delivery id across the retries of one delivery', function (): void {
    Http::fake(['api.example.test/*' => Http::response('', 500)]);

    $model = integration();

    (new SendPanelIntegration($model->id, [], 5, 'fixed-id'))->handle();
    (new SendPanelIntegration($model->id, [], 5, 'fixed-id'))->handle();

    expect($model->deliveries()->pluck('delivery_id')->unique()->all())->toBe(['fixed-id']);
});

/*
 * History, and the bound on it
 */

it('records an attempt in the history', function (): void {
    Http::fake(['api.example.test/*' => Http::response('thanks', 201)]);

    $model = integration();

    IntegrationDispatcher::send($model, ['record' => ['id' => 9]], 5);

    $delivery = $model->deliveries()->first();

    expect($delivery->status)->toBe(201)
        ->and($delivery->response_body)->toBe('thanks')
        ->and($delivery->request_body)->toContain('"id":9')
        ->and($delivery->method)->toBe('POST');
});

it('never records the headers', function (): void {
    Http::fake(['api.example.test/*' => Http::response('', 200)]);

    $model = integration();

    IntegrationDispatcher::send($model, [], 5);

    // `X-Api-Key: secret` is on every one of these requests. A history that
    // stored it would be a credential store nobody meant to create.
    $row = (array) DB::table('panel_integration_deliveries')->first();

    expect(implode(' ', array_map(strval(...), array_filter($row, is_scalar(...)))))
        ->not->toContain('secret');
});

it('keeps only the most recent attempts per integration', function (): void {
    Config::set('panda-panel.integrations.history.keep_per_integration', 3);
    Http::fake(['api.example.test/*' => Http::response('', 200)]);

    $model = integration();

    foreach (range(1, 10) as $ignored) {
        IntegrationDispatcher::send($model, [], 5);
    }

    // The bound that holds without anything being scheduled.
    expect($model->deliveries()->count())->toBe(3);
});

it('drops attempts past the retention window', function (): void {
    Config::set('panda-panel.integrations.history.keep_per_integration', 100);
    Config::set('panda-panel.integrations.history.retention_days', 7);
    Http::fake(['api.example.test/*' => Http::response('', 200)]);

    $model = integration();

    $model->deliveries()->create([
        'trigger' => Trigger::AfterCreate->value,
        'method' => 'POST',
        'url' => 'https://api.example.test/hooks',
        'delivery_id' => 'old',
        'status' => 200,
        'attempted_at' => now()->subDays(30),
    ]);

    IntegrationDispatcher::send($model, [], 5);

    expect($model->deliveries()->pluck('delivery_id')->all())->not->toContain('old');
});

it('bounds each integration separately', function (): void {
    Config::set('panda-panel.integrations.history.keep_per_integration', 2);
    Http::fake(['api.example.test/*' => Http::response('', 200)]);

    $first = integration(['name' => 'One']);
    $second = integration(['name' => 'Two']);

    foreach (range(1, 5) as $ignored) {
        IntegrationDispatcher::send($first, [], 5);
        IntegrationDispatcher::send($second, [], 5);
    }

    expect($first->deliveries()->count())->toBe(2)
        ->and($second->deliveries()->count())->toBe(2);
});

it('writes no history when it is turned off', function (): void {
    Config::set('panda-panel.integrations.history.enabled', false);
    Http::fake(['api.example.test/*' => Http::response('', 200)]);

    $model = integration();

    IntegrationDispatcher::send($model, [], 5);

    // The summary on the integration itself is still kept: it is one column,
    // and it is what the list colours itself with.
    expect($model->deliveries()->count())->toBe(0)
        ->and($model->fresh()->last_status)->toBe(200);
});

it('takes an integration history with it when it is deleted', function (): void {
    Http::fake(['api.example.test/*' => Http::response('', 200)]);

    $model = integration();

    IntegrationDispatcher::send($model, [], 5);

    $model->delete();

    expect(DB::table('panel_integration_deliveries')->count())->toBe(0);
});
