<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use PandaPanel\Broadcasting\PanelNotification;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
});

/*
 * The panel toggle
 */

it('broadcasts by default', function (): void {
    expect(app(PanelManager::class)->get('admin')->hasBroadcasting())->toBeTrue();
});

it('tells the frontend which channel to listen on', function (): void {
    // Stated rather than assumed: a channel is only sent to an application
    // that can actually broadcast, and Testbench's skeleton cannot.
    Config::set('broadcasting.default', 'reverb');
    Config::set('broadcasting.connections.reverb.driver', 'reverb');

    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('broadcasting.enabled', true)
            ->where('broadcasting.channel', 'App.Models.User.'.$this->admin->getKey()));
});

it('sends no channel when the panel turned broadcasting off', function (): void {
    app(PanelManager::class)->get('admin')->broadcasting(false);

    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('broadcasting.enabled', false)
            // Null, so the frontend has nothing to subscribe to rather than
            // a connection it opens and then ignores.
            ->where('broadcasting.channel', null));
});

it('sends no channel for a guest', function (): void {
    expect(app(PanelManager::class)->get('admin')->getBroadcastChannel(null))->toBeNull();
});

it('sends nothing at all outside a panel', function (): void {
    $this->actingAs($this->admin)
        ->get('/')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('broadcasting.enabled', false)
            ->where('broadcasting.channel', null));
});

it('keeps the toggle off the panel definition the client caches', function (): void {
    // It depends on who is asking, so it belongs beside the request rather
    // than in the panel's static configuration.
    expect(app(PanelManager::class)->get('admin')->toSharedArray())
        ->not->toHaveKey('broadcasting');
});

/*
 * The notification itself
 */

it('broadcasts on the recipient private channel and nobody else', function (): void {
    $event = new PanelNotification($this->admin, 'Export finished', 'success');

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe('private-App.Models.User.'.$this->admin->getKey());
});

it('carries the shape the frontend already shows for a flash message', function (): void {
    $event = new PanelNotification($this->admin, 'Export finished', 'success');

    expect($event->broadcastWith())->toBe([
        'type' => 'success',
        'message' => 'Export finished',
        // Absent unless the notification is about something to open, which
        // most are not.
        'url' => null,
        'urlLabel' => null,
    ])
        ->and($event->broadcastAs())->toBe('panel.notification');
});

it('carries a link for a notification whose point is a file', function (): void {
    $event = new PanelNotification(
        $this->admin,
        'Your export is ready.',
        'success',
        '/admin/exports/users.csv',
        'Download',
    );

    expect($event->broadcastWith()['url'])->toBe('/admin/exports/users.csv')
        ->and($event->broadcastWith()['urlLabel'])->toBe('Download');
});

it('defaults to an informational notification', function (): void {
    expect((new PanelNotification($this->admin, 'Heads up'))->broadcastWith()['type'])
        ->toBe('info');
});

it('dispatches as a broadcast event', function (): void {
    Event::fake();

    PanelNotification::dispatch($this->admin, 'Export finished');

    Event::assertDispatched(PanelNotification::class, function (PanelNotification $event): bool {
        return $event->message === 'Export finished'
            && $event->user->is($this->admin);
    });
});

/*
 * The channel authorization the whole thing rests on
 */

it('lets a user onto their own channel only', function (): void {
    $other = User::factory()->create();

    // The callback `routes/channels.php` registered, read back from the
    // broadcaster rather than restated here.
    $callback = Broadcast::connection()->getChannels()->get('App.Models.User.{id}');

    expect($callback)->not->toBeNull()
        ->and($callback($this->admin, $this->admin->getKey()))->toBeTrue()
        ->and($callback($this->admin, $other->getKey()))->toBeFalse();
});

it('lets a panel with nothing to receive skip the connection', function (): void {
    $panel = Panel::make('quiet')->broadcasting(false);

    expect($panel->hasBroadcasting())->toBeFalse()
        ->and($panel->getBroadcastChannel($this->admin))->toBeNull();
});

/*
 * The application has to be able to broadcast, not merely want to
 */

it('sends no channel when the application has no broadcaster', function (): void {
    // A fresh Laravel with no config/broadcasting.php: the panel still wants
    // realtime notifications, and there is nothing to deliver them with.
    Config::set('broadcasting.default', null);

    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('broadcasting.enabled', false)
            ->where('broadcasting.channel', null));
});

it('sends no channel for a driver no browser can subscribe to', function (): void {
    foreach (['null', 'log'] as $driver) {
        Config::set('broadcasting.default', 'probe');
        Config::set('broadcasting.connections.probe.driver', $driver);

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('broadcasting.enabled', false)
                ->where('broadcasting.channel', null));
    }
});

it('sends no channel for a default connection that was never defined', function (): void {
    Config::set('broadcasting.default', 'typo');
    Config::set('broadcasting.connections', []);

    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('broadcasting.enabled', false));
});

it('sends the channel once a real broadcaster is configured', function (): void {
    Config::set('broadcasting.default', 'reverb');
    Config::set('broadcasting.connections.reverb.driver', 'reverb');

    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('broadcasting.enabled', true)
            ->where('broadcasting.channel', 'App.Models.User.'.$this->admin->id));
});
