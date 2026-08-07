@props(['alerts' => []])

<section class="csc-section">
    <div class="csc-section-head">
        <div class="csc-section-heading">
            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedBell" class="csc-section-ico" />
            <h2 class="csc-section-title">{{ __('Alertas') }}</h2>
            @if (count($alerts))
                <span class="csc-count">{{ count($alerts) }}</span>
            @endif
        </div>
    </div>

    <div class="csc-section-body">
        @if (count($alerts))
            <div class="csc-alerts">
                @foreach ($alerts as $alert)
                    <a href="{{ $alert['href'] }}" @class(['csc-alert', 'csc-alert-'.$alert['severity']])>
                        <x-filament::icon :icon="$alert['icon']" class="csc-alert-ico" />
                        <span class="csc-alert-msg">{{ $alert['message'] }}</span>
                    </a>
                @endforeach
            </div>
        @else
            <x-dashboard.empty
                :message="__('Todo en orden. No hay alertas pendientes.')"
                :icon="\Filament\Support\Icons\Heroicon::OutlinedCheckCircle"
            />
        @endif
    </div>
</section>
