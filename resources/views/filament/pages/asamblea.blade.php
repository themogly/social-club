<x-filament-panels::page>
    <div class="space-y-6" data-assembly>
        {{-- Which assembly are we running --}}
        <x-filament::section>
            <x-slot name="heading">{{ __('Asamblea') }}</x-slot>
            <x-slot name="description">{{ __('Elige una convocatoria emitida para registrar la asistencia y los acuerdos.') }}</x-slot>

            @php $open = $this->openAssemblies(); @endphp
            @if ($open->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('No hay asambleas abiertas. Emite una convocatoria para empezar.') }}
                </p>
            @else
                <select
                    wire:model.live="convocatoriaId"
                    data-assembly-picker
                    class="block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                >
                    @foreach ($open as $c)
                        <option value="{{ $c->id }}">{{ $c->title }} — {{ $c->held_at->format('d/m/Y H:i') }}</option>
                    @endforeach
                </select>
            @endif
        </x-filament::section>

        @php
            $convocatoria = $this->convocatoria;
            $quorum = $this->quorum();
        @endphp

        @if ($convocatoria && $quorum)
            {{-- Live quorum --}}
            <x-filament::section data-assembly-quorum>
                <x-slot name="heading">{{ __('Quórum') }}</x-slot>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Asistentes') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white" data-quorum-present>{{ $quorum->present }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">{{ __('de :roll convocados', ['roll' => $quorum->roll]) }}</div>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Quórum 1ª convocatoria') }}</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $quorum->firstCallRequired }}</div>
                        <div class="mt-1">
                            @if ($quorum->firstCallMet())
                                <x-filament::badge color="success">{{ __('Alcanzado') }}</x-filament::badge>
                            @else
                                <x-filament::badge color="warning">{{ __('No alcanzado') }}</x-filament::badge>
                            @endif
                        </div>
                    </div>
                    <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Constitución') }}</div>
                        <div class="mt-2">
                            @if ($quorum->isConstituted())
                                <x-filament::badge color="success" data-quorum-constituted>{{ __('Válidamente constituida') }}</x-filament::badge>
                            @else
                                <x-filament::badge color="danger">{{ __('Sin quórum') }}</x-filament::badge>
                            @endif
                        </div>
                        @if ($quorum->secondCallRequired > 0)
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('2ª convocatoria: :n', ['n' => $quorum->secondCallRequired]) }}</div>
                        @else
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('2ª convocatoria: sin quórum mínimo') }}</div>
                        @endif
                    </div>
                </div>
            </x-filament::section>

            {{-- Attendance against the frozen roll --}}
            <x-filament::section data-assembly-attendance>
                <x-slot name="heading">{{ __('Asistencia') }}</x-slot>
                <x-slot name="description">{{ __('Marca quién asiste, en persona o representado. Cuentan ambos para el quórum.') }}</x-slot>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                <th class="py-2 pr-3 font-medium">{{ __('Nº') }}</th>
                                <th class="py-2 pr-3 font-medium">{{ __('Socio') }}</th>
                                <th class="py-2 pr-3 font-medium">{{ __('Estado') }}</th>
                                <th class="py-2 font-medium">{{ __('Acciones') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($this->roll() as $row)
                                <tr wire:key="roll-{{ $row['member_id'] }}" data-roll-row>
                                    <td class="py-2 pr-3 text-gray-500 dark:text-gray-400">{{ $row['member_no'] }}</td>
                                    <td class="py-2 pr-3 text-gray-900 dark:text-white">{{ $row['name'] }}</td>
                                    <td class="py-2 pr-3">
                                        @if ($row['mode'] === \App\Enums\AttendanceMode::PRESENT)
                                            <x-filament::badge color="success">{{ __('Presente') }}</x-filament::badge>
                                        @elseif ($row['mode'] === \App\Enums\AttendanceMode::PROXY)
                                            <x-filament::badge color="info">{{ __('Representado') }}</x-filament::badge>
                                            @if ($row['proxy_holder'])
                                                <span class="ml-1 text-xs text-gray-500 dark:text-gray-400">{{ $row['proxy_holder'] }}</span>
                                            @endif
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="py-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-filament::button size="xs" color="success" wire:click="markPresent('{{ $row['member_id'] }}')" data-mark-present>
                                                {{ __('Presente') }}
                                            </x-filament::button>
                                            <input
                                                type="text"
                                                wire:model="proxyHolder.{{ $row['member_id'] }}"
                                                placeholder="{{ __('Representante') }}"
                                                class="w-32 rounded-lg border-gray-300 bg-white text-xs text-gray-900 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                                            />
                                            <x-filament::button size="xs" color="info" wire:click="markProxy('{{ $row['member_id'] }}')" data-mark-proxy>
                                                {{ __('Representado') }}
                                            </x-filament::button>
                                            @if ($row['mode'])
                                                <x-filament::button size="xs" color="gray" wire:click="clearAttendance('{{ $row['member_id'] }}')" data-clear-attendance>
                                                    {{ __('Quitar') }}
                                                </x-filament::button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            {{-- Agenda → resolutions --}}
            <x-filament::section data-assembly-resolutions>
                <x-slot name="heading">{{ __('Orden del día y acuerdos') }}</x-slot>
                <x-slot name="description">{{ __('Registra el resultado de cada punto (a mano alzada) y los votos.') }}</x-slot>

                @php $items = $this->agendaItems(); @endphp
                @if ($items === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Esta convocatoria no tiene orden del día.') }}</p>
                @else
                    <div class="space-y-4">
                        @foreach ($items as $item)
                            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10" wire:key="agenda-{{ $item['position'] }}" data-agenda-item>
                                <div class="flex items-start justify-between gap-3">
                                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ $item['position'] }}. {{ $item['title'] }}
                                    </div>
                                    @if ($item['saved'])
                                        <x-filament::badge color="success">{{ $item['saved']->result->label() }}</x-filament::badge>
                                    @endif
                                </div>
                                <div class="mt-3 grid gap-3 sm:grid-cols-5">
                                    <label class="text-xs text-gray-500 dark:text-gray-400 sm:col-span-2">
                                        {{ __('Resultado') }}
                                        <select wire:model="resResult.{{ $item['position'] }}" class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white">
                                            @foreach (\App\Enums\ResolutionResult::cases() as $result)
                                                <option value="{{ $result->value }}">{{ $result->label() }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('A favor') }}
                                        <input type="number" min="0" wire:model="resFor.{{ $item['position'] }}" class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white" />
                                    </label>
                                    <label class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('En contra') }}
                                        <input type="number" min="0" wire:model="resAgainst.{{ $item['position'] }}" class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white" />
                                    </label>
                                    <label class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ __('Abstención') }}
                                        <input type="number" min="0" wire:model="resAbstain.{{ $item['position'] }}" class="mt-1 block w-full rounded-lg border-gray-300 bg-white text-sm text-gray-900 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white" />
                                    </label>
                                </div>
                                <div class="mt-3 flex justify-end">
                                    <x-filament::button size="sm" wire:click="saveResolution({{ $item['position'] }}, @js($item['title']))" data-save-resolution>
                                        {{ __('Guardar acuerdo') }}
                                    </x-filament::button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>

            {{-- Draft the acta from what was recorded --}}
            <div class="flex justify-end">
                <x-filament::button
                    size="lg"
                    wire:click="draftActa"
                    wire:confirm="{{ __('¿Redactar el acta con la asistencia y los acuerdos registrados? Podrás revisarla y firmarla después.') }}"
                    data-draft-acta
                >
                    {{ __('Redactar acta') }}
                </x-filament::button>
            </div>
        @endif
    </div>
</x-filament-panels::page>
