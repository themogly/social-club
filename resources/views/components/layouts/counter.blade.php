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
    <body class="min-h-full bg-surface-alt text-ink antialiased dark:bg-slate-950 dark:text-slate-100">
        <div class="mx-auto flex min-h-screen w-full max-w-6xl flex-col">
            {{-- One shared header for every counter terminal (brand + title + a
                 permission-filtered Panel link + Log out). See x-counter.top-bar. --}}
            <x-counter.top-bar :title="$title ?? null" />

            <main class="flex-1 px-4 py-5 sm:px-6">
                {{ $slot }}
            </main>
        </div>

        @livewireScripts
    </body>
</html>
