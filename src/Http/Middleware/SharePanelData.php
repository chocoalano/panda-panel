<?php

declare(strict_types=1);

namespace PandaPanel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use PandaPanel\Core\Panel;
use PandaPanel\Core\PanelManager;
use PandaPanel\Resources\Resource as PanelResource;
use PandaPanel\Support\BroadcastSupport;
use PandaPanel\Support\NavigationBuilder;
use PandaPanel\Tenancy\Tenancy;
use Symfony\Component\HttpFoundation\Response;

/**
 * The props every panel screen is built from.
 *
 * The panel's frontend cannot render without these: the shell reads `panel`,
 * the sidebar reads `navigation`, the header reads `panels`, `search`, and
 * `notifications`, and the toast listener reads `broadcasting`. They belong to
 * the package for the same reason the components do — an application that had
 * to hand-copy them would be maintaining the panel's internals, and a version
 * that added a prop would break every application that forgot to.
 *
 * The application's own `HandleInertiaRequests` is untouched. This shares
 * through `Inertia::share()`, which merges, so `auth`, `errors`, and whatever
 * else the application shares all still arrive.
 *
 * Every value is a closure. A request that never reaches a panel pays for none
 * of them, and nothing here is ever cached: visibility, badge counts, active
 * state, and the unread count are all per-user and per-URL.
 */
final class SharePanelData
{
    public function __construct(
        private readonly PanelManager $manager,
        private readonly NavigationBuilder $navigation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share([
            'panel' => fn (): ?array => $this->manager->currentPanel()?->toSharedArray(),
            'navigation' => fn (): array => $this->navigationFor($request),
            'panels' => fn (): array => $this->switchablePanels($request),
            'broadcasting' => fn (): array => $this->broadcasting($request),
            'search' => fn (): array => $this->search(),
            'notifications' => fn (): array => $this->notifications($request),
            'tenancy' => fn (): ?array => $this->tenancy($request),
        ]);

        return $next($request);
    }

    /**
     * Which tenant this request is in, and which others are reachable.
     *
     * Null — rather than an empty shape — for a panel with no tenancy, so the
     * frontend's check is `tenancy === null` and a switcher never renders for
     * an application that has no tenants to switch between.
     *
     * The list is filtered by the user model's own answer, which is what
     * stops the switcher offering a destination that responds 403. It is a
     * closure like every other prop here, so a panel screen that never draws
     * a switcher never runs the query behind it.
     *
     * Each entry carries the URL the panel built for it, and whether it is
     * the one this request is in. Without a URL the switcher has nowhere to
     * send anybody, which is why `Panel::tenantUrlUsing()` is what turns the
     * switcher on rather than tenancy itself.
     *
     * @return array{current: array{key: int|string, name: string, url: string|null, current: bool}|null, available: list<array{key: int|string, name: string, url: string|null, current: bool}>}|null
     */
    private function tenancy(Request $request): ?array
    {
        $panel = $this->manager->currentPanel();

        if ($panel === null || ! $panel->hasTenancy()) {
            return null;
        }

        $current = Tenancy::current();
        $currentKey = $current === null ? null : Tenancy::keyOf($current);

        $describe = static fn (Model $tenant): array => [
            ...Tenancy::describe($tenant),
            'url' => $panel->getTenantUrl($tenant),
            'current' => $currentKey !== null && Tenancy::keyOf($tenant) === $currentKey,
        ];

        return [
            'current' => $current === null ? null : $describe($current),
            'available' => array_map(
                $describe,
                Tenancy::availableTo($request->user(), $panel),
            ),
        ];
    }

    /**
     * Sidebar navigation for the current panel, empty outside one.
     *
     * @return list<array<string, mixed>>
     */
    private function navigationFor(Request $request): array
    {
        $panel = $this->manager->currentPanel();

        if ($panel === null) {
            return [];
        }

        return $this->navigation->for($panel, $request->path());
    }

    /**
     * How the palette should behave, and where to ask.
     *
     * `enabled` is false when the panel turned it off *or* when no resource in
     * it opted into searching — a palette that can only ever answer nothing is
     * worse than no palette.
     *
     * @return array{enabled: bool, url: string|null, debounce: int, keyBindings: list<string>}
     */
    private function search(): array
    {
        $panel = $this->manager->currentPanel();

        if ($panel === null) {
            return ['enabled' => false, 'url' => null, 'debounce' => 300, 'keyBindings' => []];
        }

        $searchable = collect($this->manager->resources($panel)->all())
            ->contains(static fn (string $resource): bool => is_subclass_of($resource, PanelResource::class)
                && $resource::isGloballySearchable());

        $enabled = $panel->hasGlobalSearch() && $searchable;

        return [
            'enabled' => $enabled,
            'url' => $enabled ? route($panel->routeName('search'), absolute: false) : null,
            'debounce' => $panel->getGlobalSearchDebounce(),
            'keyBindings' => $panel->getGlobalSearchKeyBindings(),
        ];
    }

    /**
     * The notification centre's endpoints and the unread count.
     *
     * The count is read on every panel request rather than polled, so the bell
     * is right after any navigation without a second round trip. It is one
     * indexed count on a table scoped to one user — the cheapest question this
     * table can be asked.
     *
     * @return array{enabled: bool, indexUrl: string|null, readUrl: string|null, clearUrl: string|null, unread: int}
     */
    private function notifications(Request $request): array
    {
        $panel = $this->manager->currentPanel();
        $user = $request->user();

        if ($panel === null || ! $panel->hasNotifications() || $user === null) {
            return [
                'enabled' => false,
                'indexUrl' => null,
                'readUrl' => null,
                'clearUrl' => null,
                'unread' => 0,
            ];
        }

        return [
            'enabled' => true,
            'indexUrl' => route($panel->routeName('notifications.index'), absolute: false),
            'readUrl' => route($panel->routeName('notifications.read'), absolute: false),
            'clearUrl' => route($panel->routeName('notifications.clear'), absolute: false),
            'unread' => $this->unreadCount($user),
        ];
    }

    /**
     * How many notifications this user has not read.
     *
     * Zero when the application has no `notifications` table, rather than a
     * 500 on every panel page. The table arrives with the package's own
     * migration; until `migrate` has run, an empty bell is the honest answer
     * and the panel still works.
     */
    private function unreadCount(Authenticatable $user): int
    {
        if (! method_exists($user, 'unreadNotifications')) {
            return 0;
        }

        try {
            return $user->unreadNotifications()->count();
        } catch (QueryException) {
            return 0;
        }
    }

    /**
     * What the current panel subscribes to, if anything.
     *
     * The channel is built from the signed-in user, so a panel can only ever
     * be told to listen to that user's own — and the channel authorization
     * callback refuses anything else regardless of what the frontend asks for.
     *
     * @return array{enabled: bool, channel: string|null}
     */
    private function broadcasting(Request $request): array
    {
        $panel = $this->manager->currentPanel();

        // Two questions, and both have to answer yes. The panel says whether
        // it wants realtime notifications; `BroadcastSupport` says whether
        // this application has anything to deliver them with. Sending a
        // channel on the strength of the first alone is what made a panel
        // installed into a starter kit with no broadcaster throw from inside
        // `onMounted` and take the whole layout down with it.
        if ($panel === null || ! $panel->hasBroadcasting() || ! BroadcastSupport::isConfigured()) {
            return ['enabled' => false, 'channel' => null];
        }

        return [
            'enabled' => true,
            'channel' => $panel->getBroadcastChannel($request->user()),
        ];
    }

    /**
     * The panels this user may enter, for the header's panel switcher.
     *
     * Filtered by the same predicate the routes enforce, so a panel the user
     * would be refused never appears as somewhere to go. Empty outside a
     * panel, and the URL comes from here rather than being assembled in Vue.
     *
     * @return list<array<string, mixed>>
     */
    private function switchablePanels(Request $request): array
    {
        $current = $this->manager->currentPanel();

        if ($current === null) {
            return [];
        }

        $accessible = array_filter(
            $this->manager->all(),
            static fn (Panel $panel): bool => $panel->isAccessibleTo($request->user()),
        );

        return array_values(array_map(
            static fn (Panel $panel): array => [
                'id' => $panel->getId(),
                'name' => $panel->getName(),
                'brandName' => $panel->getBrandName(),
                'path' => '/'.$panel->getPath(),
                'icon' => $panel->getIcon(),
                'url' => route($panel->routeName('dashboard'), absolute: false),
                'current' => $panel->getId() === $current->getId(),
            ],
            $accessible,
        ));
    }
}
