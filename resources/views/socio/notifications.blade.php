<x-layouts.socio :title="__('Avisos y notificaciones')">
    @php($labels = [
        'low_balance' => __('Saldo bajo en el monedero'),
        'membership_expiring' => __('Vencimiento de la membresía'),
        'new_announcement' => __('Nuevos avisos del club'),
        'event_reminder' => __('Recordatorios de eventos'),
    ])

    <header class="mb-4">
        <h1 class="text-xl font-semibold">{{ __('Avisos y notificaciones') }}</h1>
        <p class="mt-0.5 text-sm text-ink-muted dark:text-slate-400">{{ __('Decide qué avisos quieres recibir y en qué dispositivo.') }}</p>
    </header>

    @if (session('status'))
        <p class="mb-3 rounded-lg bg-brand-tint p-3 text-sm text-brand dark:bg-slate-800 dark:text-slate-200">{{ session('status') }}</p>
    @endif

    {{-- Push activation on THIS device (Web Push). --}}
    <section class="rounded-2xl border border-line bg-surface p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-sm font-semibold">{{ __('Notificaciones push') }}</h2>
        @if ($vapidPublicKey === '')
            <p class="mt-2 text-sm text-ink-muted dark:text-slate-400">{{ __('Las notificaciones push no están configuradas en este club todavía.') }}</p>
        @else
            <p class="mt-1 text-sm text-ink-muted dark:text-slate-400">{{ __('Actívalas para recibir avisos aunque no tengas la app abierta.') }}</p>
            <div id="push-controls" class="mt-3 flex flex-wrap gap-2" data-vapid="{{ $vapidPublicKey }}"
                 data-subscribe="{{ route('socio.push.subscribe') }}" data-unsubscribe="{{ route('socio.push.unsubscribe') }}">
                <button type="button" id="push-enable"
                        class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark">
                    {{ __('Activar en este dispositivo') }}
                </button>
                <button type="button" id="push-disable" hidden
                        class="rounded-lg border border-line px-4 py-2 text-sm font-semibold text-ink-muted dark:border-slate-700 dark:text-slate-300">
                    {{ __('Desactivar') }}
                </button>
            </div>
            <p id="push-status" class="mt-2 text-xs text-ink-muted dark:text-slate-400" role="status" aria-live="polite"></p>
        @endif
    </section>

    {{-- Per-channel opt-outs (checked = subscribed). --}}
    <form method="POST" action="{{ route('socio.notifications.prefs') }}"
          class="mt-4 rounded-2xl border border-line bg-surface p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        @csrf
        <h2 class="text-sm font-semibold">{{ __('¿Qué avisos quieres recibir?') }}</h2>
        <div class="mt-3 space-y-1">
            @foreach ($channels as $channel)
                <label class="flex items-center justify-between gap-3 rounded-lg px-1 py-2.5">
                    <span class="text-sm">{{ $labels[$channel] ?? $channel }}</span>
                    <input type="checkbox" name="channels[]" value="{{ $channel }}"
                           @checked(! in_array($channel, $optOuts, true))
                           class="h-5 w-5 rounded border-line text-brand focus:ring-brand/40 dark:border-slate-600 dark:bg-slate-950">
                </label>
            @endforeach
        </div>
        <button type="submit"
                class="mt-4 w-full rounded-lg bg-brand px-4 py-2.5 font-semibold text-white transition hover:bg-brand-dark">
            {{ __('Guardar preferencias') }}
        </button>
    </form>

    <x-slot:footer>
        <script>
            (function () {
                var root = document.getElementById('push-controls');
                if (!root || !('serviceWorker' in navigator) || !('PushManager' in window)) {
                    if (root) { document.getElementById('push-status').textContent = @json(__('Este navegador no admite notificaciones push.')); }
                    return;
                }

                var enableBtn = document.getElementById('push-enable');
                var disableBtn = document.getElementById('push-disable');
                var statusEl = document.getElementById('push-status');
                var vapid = root.dataset.vapid;
                var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

                function urlBase64ToUint8Array(base64String) {
                    var padding = '='.repeat((4 - base64String.length % 4) % 4);
                    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
                    var raw = atob(base64);
                    var out = new Uint8Array(raw.length);
                    for (var i = 0; i < raw.length; ++i) { out[i] = raw.charCodeAt(i); }
                    return out;
                }

                function post(url, body) {
                    return fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                        body: JSON.stringify(body),
                    });
                }

                function reflect(subscribed) {
                    enableBtn.hidden = subscribed;
                    disableBtn.hidden = !subscribed;
                    statusEl.textContent = subscribed
                        ? @json(__('Activadas en este dispositivo.'))
                        : @json(__('No activadas en este dispositivo.'));
                }

                navigator.serviceWorker.ready.then(function (reg) {
                    reg.pushManager.getSubscription().then(function (sub) { reflect(!!sub); });
                });

                enableBtn.addEventListener('click', function () {
                    statusEl.textContent = @json(__('Activando…'));
                    Notification.requestPermission().then(function (perm) {
                        if (perm !== 'granted') { statusEl.textContent = @json(__('Permiso denegado por el navegador.')); return; }
                        navigator.serviceWorker.ready.then(function (reg) {
                            return reg.pushManager.subscribe({
                                userVisibleOnly: true,
                                applicationServerKey: urlBase64ToUint8Array(vapid),
                            });
                        }).then(function (sub) {
                            return post(root.dataset.subscribe, sub.toJSON());
                        }).then(function () { reflect(true); })
                          .catch(function () { statusEl.textContent = @json(__('No se pudieron activar las notificaciones.')); });
                    });
                });

                disableBtn.addEventListener('click', function () {
                    navigator.serviceWorker.ready.then(function (reg) {
                        return reg.pushManager.getSubscription();
                    }).then(function (sub) {
                        if (!sub) { reflect(false); return; }
                        var endpoint = sub.endpoint;
                        return sub.unsubscribe().then(function () {
                            return post(root.dataset.unsubscribe, { endpoint: endpoint });
                        }).then(function () { reflect(false); });
                    }).catch(function () { statusEl.textContent = @json(__('No se pudo desactivar.')); });
                });
            })();
        </script>
    </x-slot:footer>
</x-layouts.socio>
