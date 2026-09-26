{{-- Prompt 262 — Sistema ▸ Roles y permisos. The owner decides what Personal and Encargado may do; Propietario is always
     everything (ticked, locked). Each row shows the code's default beside the club's choice, so a change is visible
     against it. Sensitive grants are marked — warned, not blocked: it is the owner's club. --}}
<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-400">
        {{ __('Lo que marques aquí se guarda como decisión del club y sobrevive a cada actualización. «Por defecto» es lo que trae la aplicación de fábrica. Cada cambio queda en la auditoría.') }}
    </p>

    <div class="flex flex-wrap gap-2">
        @foreach ($roles as $role)
            <x-filament::button color="gray" size="sm" wire:click="restoreDefaults('{{ $role->value }}')" wire:confirm="{{ __('¿Restaurar los valores por defecto de :role?', ['role' => $role->label()]) }}" data-restore-defaults="{{ $role->value }}">
                {{ __('Restaurar valores por defecto: :role', ['role' => $role->label()]) }}
            </x-filament::button>
        @endforeach
    </div>

    @foreach ($groups as $group => $permissions)
        <x-filament::section :heading="$group">
            <div class="overflow-x-auto">
                <table class="w-full table-fixed text-sm" data-roles-group>
                    <thead>
                        <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                            <th class="py-2 pe-4 font-medium">{{ __('Permiso') }}</th>
                            <th class="w-28 px-3 py-2 text-center font-medium">{{ \App\Enums\Role::OWNER->label() }}</th>
                            @foreach ($roles as $role)
                                <th class="w-28 px-3 py-2 text-center font-medium">{{ $role->label() }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($permissions as $permission)
                            <tr data-permission-row="{{ $permission }}">
                                <td class="py-2 pe-4">
                                    {{ \App\Support\Permissions::label($permission) }}
                                    @if (in_array($permission, $sensitive, true))
                                        <x-filament::badge color="warning" size="sm" class="ms-1 inline-flex">{{ __('Sensible') }}</x-filament::badge>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <input type="checkbox" checked disabled aria-label="{{ __('Propietario: siempre') }}" class="rounded border-gray-300 opacity-60">
                                </td>
                                @foreach ($roles as $role)
                                    @php
                                        $isHeld = in_array($permission, $held[$role->value], true);
                                        $isDefault = in_array($permission, $defaults[$role->value], true);
                                    @endphp
                                    <td class="px-3 py-2 text-center">
                                        <label class="inline-flex flex-col items-center gap-0.5">
                                            <input type="checkbox" @checked($isHeld)
                                                   wire:click="toggle('{{ $role->value }}', '{{ $permission }}')"
                                                   @if (! $isHeld && in_array($permission, $sensitive, true)) wire:confirm="{{ __('Este permiso llega al núcleo de cumplimiento y privacidad. ¿Concederlo a :role?', ['role' => $role->label()]) }}" @endif
                                                   data-toggle="{{ $role->value }}:{{ $permission }}"
                                                   aria-label="{{ $role->label() }}: {{ \App\Support\Permissions::label($permission) }}"
                                                   class="rounded border-gray-300 text-primary-600">
                                            <span @class(['text-[10px]', 'text-gray-400' => $isHeld === $isDefault, 'font-semibold text-warning-600' => $isHeld !== $isDefault])>
                                                {{ $isHeld === $isDefault ? __('por defecto') : ($isDefault ? __('por defecto: sí') : __('por defecto: no')) }}
                                            </span>
                                        </label>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
