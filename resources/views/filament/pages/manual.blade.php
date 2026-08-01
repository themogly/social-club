<x-filament-panels::page>
    {{-- The club manual (prompt 99). Content only, static — no queries, nothing lazy. Everything here is
         already filtered by the page to what the reader's role can do, so there is no gating in the view. --}}
    <div class="mx-auto w-full max-w-3xl space-y-10">

        {{-- Task guides — the jobs people actually do. A short contents list, then each guide in full so the
             page prints and any step is reachable by anchor from the screen where the task starts. --}}
        @if (count($guides) > 0)
            <section>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Guías de tareas') }}</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('Paso a paso para las tareas del día a día. Solo aparecen las que puedes empezar con tu rol.') }}</p>

                <nav class="mt-3 flex flex-wrap gap-2" aria-label="{{ __('Guías de tareas') }}">
                    @foreach ($guides as $key => $guide)
                        <a href="#guia-{{ $key }}" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 transition hover:border-primary-300 hover:text-primary-700 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200 dark:hover:text-primary-300">
                            {{ __($guide['title']) }}
                        </a>
                    @endforeach
                </nav>

                <div class="mt-4 space-y-4">
                    @foreach ($guides as $key => $guide)
                        <article id="guia-{{ $key }}" class="scroll-mt-24 rounded-xl border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-gray-900">
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __($guide['title']) }}</h3>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __($guide['intro']) }}</p>

                            <ol class="mt-4 space-y-3">
                                @foreach ($guide['steps'] as $i => $step)
                                    <li class="flex gap-3">
                                        <span aria-hidden="true" class="mt-0.5 flex h-6 w-6 flex-none items-center justify-center rounded-full bg-primary-50 text-xs font-semibold text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">{{ $i + 1 }}</span>
                                        <div>
                                            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ __($step['title']) }}</p>
                                            @foreach ($step['body'] as $paragraph)
                                                <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-300">{{ __($paragraph) }}</p>
                                            @endforeach
                                        </div>
                                    </li>
                                @endforeach
                            </ol>

                            {{-- The eighth-pricing worked example: every figure computed live through ResolvePrice
                                 (Help::eighthExample) so it is exactly what the till charges — never hand-typed. --}}
                            @if (($guide['example'] ?? null) === 'eighth')
                                @php($ex = $eighthExample)
                                <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                                    <table class="w-full text-sm">
                                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                            <tr>
                                                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ __('Precio por gramo') }}</td>
                                                <td class="px-3 py-2 text-right tabular-nums text-gray-950 dark:text-white">{{ \App\Support\Money::fromCents($ex['base_per_gram_cents'])->formatted() }}</td>
                                            </tr>
                                            <tr>
                                                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ __('Precio del octavo (3,5 g)') }}</td>
                                                <td class="px-3 py-2 text-right tabular-nums text-gray-950 dark:text-white">{{ \App\Support\Money::fromCents($ex['base_eighth_cents'])->formatted() }}</td>
                                            </tr>
                                            <tr>
                                                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ __('Descuento del socio') }}</td>
                                                <td class="px-3 py-2 text-right tabular-nums text-gray-950 dark:text-white">{{ $ex['discount_bp'] / 100 }} %</td>
                                            </tr>
                                            <tr>
                                                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ __('Por gramo, ya con descuento') }}</td>
                                                <td class="px-3 py-2 text-right tabular-nums text-gray-950 dark:text-white">{{ \App\Support\Money::fromCents($ex['eff_per_gram_cents'])->formatted() }} / g</td>
                                            </tr>
                                            <tr>
                                                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ __('El octavo, ya con descuento') }}</td>
                                                <td class="px-3 py-2 text-right tabular-nums text-gray-950 dark:text-white">{{ \App\Support\Money::fromCents($ex['eff_eighth_cents'])->formatted() }}</td>
                                            </tr>
                                            <tr>
                                                <td class="px-3 py-2 text-gray-600 dark:text-gray-300">{{ __('3,5 g sumando por gramo') }}</td>
                                                <td class="px-3 py-2 text-right tabular-nums text-gray-500 line-through dark:text-gray-400">{{ \App\Support\Money::fromCents($ex['per_gram_total_cents'])->formatted() }}</td>
                                            </tr>
                                            <tr class="bg-primary-50/60 dark:bg-primary-500/5">
                                                <td class="px-3 py-2 font-semibold text-gray-950 dark:text-white">{{ __('Lo que cobra el mostrador') }}</td>
                                                <td class="px-3 py-2 text-right font-semibold tabular-nums text-primary-700 dark:text-primary-300">{{ \App\Support\Money::fromCents($ex['charged_cents'])->formatted() }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('El socio ahorra :amount frente a pagar por gramo. Si el precio del octavo fuera mayor que la suma por gramo, se ignoraría.', ['amount' => \App\Support\Money::fromCents($ex['saving_cents'])->formatted()]) }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Per-screen topics — what each screen is for, filtered to the ones this reader can open. --}}
        @if (count($topics) > 0)
            <section>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Las pantallas, una por una') }}</h2>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('Para qué sirve cada pantalla y qué consecuencia tiene lo que haces en ella.') }}</p>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @foreach ($topics as $topic)
                        <section class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __($topic['title']) }}</h3>
                            @foreach ($topic['body'] as $paragraph)
                                <p class="mt-1.5 text-sm text-gray-600 dark:text-gray-300">{{ __($paragraph) }}</p>
                            @endforeach
                        </section>
                    @endforeach
                </div>
            </section>
        @endif

        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('¿Buscas el significado de un término?') }}
            <a href="{{ \App\Filament\Pages\Glosario::getUrl() }}" class="font-medium text-primary-700 hover:underline dark:text-primary-300">{{ __('Consulta el glosario') }}</a>.
        </p>
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Esta ayuda explica cómo funciona la plataforma; no constituye asesoramiento legal.') }}</p>
    </div>
</x-filament-panels::page>
