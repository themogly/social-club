{{-- Reusable counter shell (check-in / dispensary / bar POS). Tablet-first, dark-mode
     aware, large touch targets — legible one-handed at 390px, comfortable at 1024px+.
     Pure presentation: no queries live here. Uses the app's own Tailwind via @vite. --}}
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

        <title>{{ $title ?? __('Mostrador') }} · {{ config('app.name') }}</title>

        {{-- Assets only when built (or the Vite dev server is hot); guarded so a
             full-page GET never 500s before `npm run build`, and tests stay quiet. --}}
        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        {{-- Shared counter store. `dirty` = unsaved work (POS/till screens set it; the header's Panel/Log out
             controls confirm before leaving). `locked` = the idle-lock overlay (prompt 120): after
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
            {{-- One shared header for every counter terminal (brand + title + a
                 permission-filtered Panel link + Log out). See x-counter.top-bar. --}}
            {{-- Handed over (prompt 173): the counter's chrome is ABSENT from the DOM, not hidden by CSS.
                 The tab strip, the overflow menu with its Panel link and Log out, the sede switcher and the
                 panic button are all inside this component — while an applicant holds the tablet there is no
                 element to find, no link to follow and nothing for a keyboard to reach. --}}
            @unless (\App\Support\CounterHandover::active())
                <x-counter.top-bar :title="$title ?? null" />
            @endunless

            <main @class([
                'flex-1 px-4 py-5 sm:px-6',
                'md:min-h-0 md:overflow-hidden' => $fills,
            ])>
                {{ $slot }}
            </main>
        </div>

        @livewireScripts
    </body>
</html>
