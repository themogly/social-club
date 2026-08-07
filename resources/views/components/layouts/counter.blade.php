{{-- Reusable counter shell (check-in / dispensary / bar POS). Tablet-first, dark-mode
     aware, large touch targets — legible one-handed at 390px, comfortable at 1024px+.
     Pure presentation: no queries live here. Uses the app's own Tailwind via @vite.

     ============================================================================================
     THE RULE THIS FILE OBEYS (prompt 209)

     **Nothing this layout branches on may be changeable by a Livewire component within the same
     page life.** A Livewire response replaces the component's markup and nothing else — this file
     is rendered once, on the full page load, and never again. So a branch here is a SNAPSHOT: it
     freezes whatever the server said at page load, and no later action can correct it.

     Concretely, the line that must never come back is `@unless (CounterHandover::active())` around
     the top bar. Ending a handover restored the counter and left it with no chrome, no sede, no
     lock and no way to another screen — a stranded terminal, because 205 made the bar the only
     navigation. This is prompt 188's failure one level out: 188 was Alpine snapshotting server
     state into `x-data`, this was the layout snapshotting it into the DOM.

     The line is drawn at SESSION-BACKED state, because that is exactly what a counter component
     can write mid-page-life. Route-derived facts (the screen title), deploy-time facts (whether
     the build manifest exists) and per-sede config (the idle-lock window, changed in the admin
     panel, i.e. a different page life) are fixed for the life of the page and are fine here.

     `tests/Feature/Counter/LayoutBranchesOnFixedFactsTest` derives that rule rather than listing
     offenders: it fails on any `App\Support` class this layout reads whose own source writes to
     the session. --}}
<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="h-full"
>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        {{-- No public/indexable surface anywhere in this app (NOTES §A / §B). --}}
        <meta name="robots" content="noindex, nofollow">

        {{-- The screen's own name, from the ONE list the tab strip uses (App\Support\CounterScreens), so the
             tab, the heading and the strip can never disagree. All six screens used to fall through to
             "Mostrador" — six identical titles and six identical h1s (a11y audit, WCAG 2.4.2). --}}
        @php($screenTitle = $title ?? \App\Support\CounterScreens::currentLabel())
        <title>{{ $screenTitle ?? __('Mostrador') }} · {{ config('app.name') }}</title>

        {{-- Assets only when built (or the Vite dev server is hot); guarded so a
             full-page GET never 500s before `npm run build`, and tests stay quiet. --}}
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        {{-- Shared counter store.

             TWO unsaved-work flags, because there are two different losses (prompt 206):

             · `dirty`    = there is work in progress at this terminal. Guards the controls that LEAVE the
                            counter — Administración, Log out, and switching sede — because all three take
                            the basket with them (log out ends the session; a sede switch re-keys it).
             · `volatile` = work that no navigation preserves. Guards HOME, which is now every trip between
                            screens. The POS baskets are session-backed (App\Support\CounterBasket), so going
                            home cannot lose one — only typed-but-unsubmitted input dies, which today is the
                            till's count/movement/expense fields and nothing else. Guarding Home on `dirty`
                            meant the operator met a warning about a loss that could not happen, on the most
                            common action in the product, and learned to dismiss it — which then costs them
                            the one on Administración and Log out, where it is still true.

             Volatile work is also dirty (the till sets both); the reverse is not so.

             `locked` = the idle-lock overlay (prompt 120): after
             `idleMinutes` of NO real operator input the screen locks, obscuring member data and signing the
             operator out server-side (a commit then fails requireOperator, not just the overlay). Only genuine
             input (pointer/key/touch) resets the timer — Livewire polling and re-renders never do. The window is
             the per-location `counter_idle_lock_minutes` setting; 0 disables it. --}}
        <script>
            document.addEventListener('alpine:init', () => {
                if (window.Alpine.store('counter')) {
                    return;
                }

                window.Alpine.store('counter', {
                    dirty: false,
                    volatile: false,
                    locked: false,
                    idleMinutes: {{ (int) \App\Support\Settings::get('counter_idle_lock_minutes', 5) }},
                    _timer: null,

                    startIdleWatch() {
                        if (this.idleMinutes <= 0) {
                            return; // idle lock disabled for this sede
                        }
                        const reset = () => this.armIdle();
                        ['pointerdown', 'keydown', 'touchstart'].forEach(
                            (evt) => document.addEventListener(evt, reset, { passive: true, capture: true })
                        );
                        this.armIdle();
                    },

                    armIdle() {
                        if (this.locked || this.idleMinutes <= 0) {
                            return;
                        }
                        clearTimeout(this._timer);
                        this._timer = setTimeout(() => this.lockNow(), this.idleMinutes * 60000);
                    },

                    lockNow() {
                        if (this.locked) {
                            return;
                        }
                        this.locked = true;
                        clearTimeout(this._timer);
                        // Sign the operator out server-side so commits are refused, not merely hidden.
                        window.Livewire && window.Livewire.dispatch('counter-lock');
                    },

                    unlocked() {
                        this.locked = false;
                        this.armIdle();
                    },
                });

                window.Alpine.store('counter').startIdleWatch();
            });
        </script>

        @livewireStyles
    </head>
    {{-- Prompt 176: the two SELLING screens opt into a full-height shell, so the PAGE does not scroll and the
         cart column can hold the commit action at its foot where it is always reachable. Guarded at `md:`
         — below that the two-pane layout collapses to a stack, which must scroll normally or it would be
         clipped with no way to reach the rest. Every other counter screen is unaffected. --}}
    @php($fills = $fullHeight ?? false)
    <body @class([
        'min-h-full bg-surface-alt text-ink antialiased dark:bg-slate-950 dark:text-slate-100',
        'md:overflow-hidden' => $fills,
    ])>
        {{-- Prompt 196 — THE COUNTER SHELL IS AN ALPINE SCOPE, and that is the whole fix.

             Alpine 3 does not walk the document on start: it queries its root selectors and calls initTree
             only on those subtrees. An element carrying `@click` with no `x-data` ancestor is never
             initialised — with no console warning, no exception, nothing. The shared header had no scope, so
             five handlers on every counter screen were dead: prompt 120's MANUAL lock (the idle timer was
             fine — it registers on `alpine:init` and never needed a DOM binding, so the automatic control
             worked and the deliberate one did not) and prompt 23's unsaved-work guard on the tab strip. The
             nav items are real <a href>s, so `@click.prevent` not running meant the browser simply followed
             the link: the guard was not absent, it was bypassed silently with a basket open. The overflow
             menu's copy of the same guard worked, because that menu has its own x-data island.

             Scoped HERE rather than on the header, deliberately: this div wraps the header AND <main>, so
             every counter screen's content is covered too — the same bug had already reached prompt 189's
             home screen, whose lock button and back-to-home guard were dead for exactly this reason. One
             attribute, and the class cannot recur inside the counter. Nested x-data islands (the sede
             switcher, the overflow menu, the 173 surface) are unaffected; Alpine nests scopes. --}}
        <div
            x-data="{}"
            @class([
            'mx-auto flex min-h-screen w-full max-w-6xl flex-col',
            'md:h-screen md:min-h-0' => $fills,
        ])>
            {{-- THE CHROME — the skip link and the shared top bar, and it is a COMPONENT (prompt 209).

                 It used to be `@unless (CounterHandover::active())` right here, wrapping the bar directly.
                 The rule was right — 173 requires the chrome absent from the DOM while an applicant holds the
                 tablet, not merely hidden — and the place was wrong. `unlockOperator()` ends a handover inside
                 a Livewire action, and a Livewire response replaces the COMPONENT's markup and nothing else:
                 this file is never re-rendered. So recovering from a handover restored the counter and not its
                 chrome, and since 205 made the bar the only navigation, that stranded the terminal on whatever
                 screen it was on until somebody reloaded.

                 Deciding it inside a component puts the branch somewhere a Livewire response can reach. See
                 the rule this layout now obeys — and the test that enforces it — at the top of the file. --}}
            <livewire:counter.counter-chrome :title="$screenTitle ?? null" />

            <main id="counter-main" @class([
                'flex-1 px-4 py-5 sm:px-6',
                'md:min-h-0 md:overflow-hidden' => $fills,
            ])>
                {{ $slot }}
            </main>
        </div>

        @livewireScripts
    </body>
</html>
