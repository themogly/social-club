<x-filament-panels::page>
    <div class="space-y-6">
        @if ($active)
            <div @class([
                'rounded-xl border p-4',
                // The semantic tokens, not raw red-*/amber-*: prompt 98 tuned --color-warning and
                // --color-error PER SCHEME to clear AA on both surfaces, and a raw Tailwind hue is the one
                // place that work cannot reach (design audit).
                'border-warning/40 bg-warning/10' => $active->is_drill,
                'border-error/40 bg-error/10' => ! $active->is_drill,
            ])>
                <p class="text-sm font-semibold">
                    {{ $active->is_drill ? __('Simulacro en curso') : __('Bloqueo de seguridad ACTIVO') }}
                </p>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    {{ __('Desde :when', ['when' => $active->locked_at->format('d/m/Y H:i')]) }}
                    @if ($active->lockedBy)· {{ $active->lockedBy->name }}@endif
                </p>
                @unless ($active->is_drill)
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('Se reactiva desde el enlace enviado a los propietarios, por el plazo automático o por línea de comandos. No desde aquí.') }}</p>
                @endunless
            </div>
        @else
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('El club está operativo. Usa «Activar bloqueo de seguridad» solo ante una amenaza real; «Simulacro» para ensayarlo. Consulta el guion en el Manual → «Bloqueo de seguridad».') }}</p>
            </div>
        @endif

        <div>
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('Historial') }}</h2>
            <div class="mt-2 overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2">{{ __('Activado') }}</th>
                            <th class="px-3 py-2">{{ __('Tipo') }}</th>
                            <th class="px-3 py-2">{{ __('Reactivado') }}</th>
                            <th class="px-3 py-2">{{ __('Vía') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @forelse ($history as $row)
                            <tr>
                                <td class="px-3 py-2">{{ $row->locked_at->format('d/m/Y H:i') }}</td>
                                <td class="px-3 py-2">{{ $row->is_drill ? __('Simulacro') : __('Real') }}</td>
                                <td class="px-3 py-2">{{ $row->reactivated_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $row->reactivation_method ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-4 text-center text-gray-500 dark:text-gray-400">{{ __('Sin bloqueos registrados.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-filament-panels::page>
