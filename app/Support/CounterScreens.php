<?php

namespace App\Support;

use App\Models\User;

/**
 * The counter's five destinations and the gate on each — ONE list, in one place (prompt 172).
 *
 * It lived inline in `components/counter/top-bar.blade.php`, which was fine while the tab strip was its
 * only consumer. The panel's sidebar then needed the same question answered — "can this user reach the
 * counter at all, and where should a single link land them?" — and the alternative to extracting it was a
 * second copy of the rule in `AdminPanelProvider`. This codebase has just had to delete one duplicated
 * PIN pad (prompt 173); it does not need a duplicated permission map to go with it.
 *
 * The tab strip's behaviour, destinations, gates and layout are unchanged — prompts 116, 130 and 132 are
 * untouched. It consumes this instead of declaring it.
 *
 * `granted` mirrors each Livewire component's own `mount()` gate, so a link to a 403 is never rendered.
 * The real gate remains server-side in each component; this only decides what is shown.
 */
class CounterScreens
{
    /**
     * Every counter screen, in tab-strip order, with its gate resolved for this user.
     *
     * @return list<array{route: string, label: string, granted: bool, icon: string}>
     */
    public static function forUser(?User $user): array
    {
        return [
            [
                'route' => 'counter.checkin',
                'label' => __('Recepción'),
                'granted' => (bool) $user?->can('checkin.manage'),
                'icon' => 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
            ],
            [
                'route' => 'counter.members',
                'label' => __('Socios'),
                'granted' => (bool) $user?->can('membership.fee.collect'),
                'icon' => 'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z',
            ],
            [
                'route' => 'counter.pos',
                'label' => __('Dispensario'),
                'granted' => (bool) $user?->can('pos.use'),
                'icon' => 'M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0 0 12 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 0 1-2.031.352 5.988 5.988 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.971Zm-16.5.52c.99-.203 1.99-.377 3-.52m0 0 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.989 5.989 0 0 1-2.031.352 5.989 5.989 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L5.25 4.971Z',
            ],
            [
                'route' => 'counter.bar',
                'label' => __('Barra'),
                'granted' => (bool) $user?->can('pos.bar') && (bool) Settings::get('bar_enabled', true),
                'icon' => 'M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z',
            ],
            [
                'route' => 'counter.till',
                'label' => __('Caja'),
                'granted' => (bool) ($user?->can('till.open') || $user?->can('till.close')),
                'icon' => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z',
            ],
        ];
    }

    /**
     * Only the screens this user may actually open.
     *
     * @return list<array{route: string, label: string, granted: bool, icon: string}>
     */
    public static function reachableFor(?User $user): array
    {
        return array_values(array_filter(self::forUser($user), fn (array $screen): bool => $screen['granted']));
    }

    /** Can this user reach the counter at all? One link renders only when the answer is yes. */
    public static function reachableByAny(?User $user): bool
    {
        return self::reachableFor($user) !== [];
    }

    /**
     * The label of the screen currently being rendered, or null off the counter.
     *
     * The counter layout names the page from this, so the browser tab, the top bar heading and the tab strip
     * can never disagree. Before the accessibility audit all six screens fell through to the same
     * *"Mostrador"*: six identical `<title>`s and six identical `<h1>`s, which is a WCAG 2.4.2 failure and,
     * more practically, an operator with three counter tabs open who cannot tell them apart.
     *
     * Route name → label rather than a per-component title, because the labels already live here for the tab
     * strip and a second copy would drift the first time one is renamed.
     */
    public static function currentLabel(): ?string
    {
        $route = request()->route()?->getName();

        if ($route === null) {
            return null;
        }

        foreach (self::forUser(null) as $screen) {
            if ($screen['route'] === $route) {
                return $screen['label'];
            }
        }

        return null;
    }

    /**
     * Where one link into the counter should land this user.
     *
     * **Recepción, because that is where a shift starts** — but only if they can actually be there. The
     * trap this closes: a user with `till.open` and not `checkin.manage` had a direct link to the till and
     * nothing else; sending them to Recepción instead would 403 on arrival, turning one tidy link into a
     * broken one. So the landing screen is resolved PER USER — the front door when they have it, otherwise
     * the first counter screen they are allowed to open. No new screen, no new route.
     */
    public static function landingRouteFor(?User $user): ?string
    {
        $reachable = self::reachableFor($user);

        if ($reachable === []) {
            return null;
        }

        // Prompt 189 — the landing screen is a SETTING, not a law. The counter home is the default because
        // the owner asked for it twice and it is the safer front door: a chooser cannot strand anybody,
        // whereas landing straight on a working screen assumes we know which work they came to do. A club
        // that would rather open on Recepción sets `counter_landing` to 'screen' and gets prompt 172's
        // behaviour back unchanged. Either way the resolution below stays PER USER.
        if (Settings::get('counter_landing', 'home') === 'home') {
            return 'counter.home';
        }

        foreach ($reachable as $screen) {
            if ($screen['route'] === 'counter.checkin') {
                return $screen['route'];
            }
        }

        return $reachable[0]['route'];
    }
}
