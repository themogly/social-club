@php($club = \App\Support\OrganisationIdentity::current()['name'])
{{ __('Convocatoria de asamblea :type', ['type' => $typeLabel]) }}
{{ $title }}

{{ __('Hola :name,', ['name' => $memberName]) }}

{{ __('Por la presente se le convoca a la asamblea general de la asociación, que se celebrará:') }}

{{ __('Fecha y hora') }}: {{ $heldAt }}
@if ($secondCallAt){{ __('Segunda convocatoria') }}: {{ $secondCallAt }}
@endif
@if ($venue){{ __('Lugar') }}: {{ $venue }}
@endif
@if ($quorumRequired !== null){{ __('Quórum requerido (primera convocatoria)') }}: {{ $quorumRequired }}
@endif
@if (!empty($agenda))

{{ __('Orden del día') }}:
@foreach ($agenda as $i => $punto){{ $i + 1 }}. {{ $punto }}
@endforeach
@endif
@if ($body)

{{ $body }}
@endif

{{ __('Un saludo,') }}
{{ $club }}

—
{{ __('Comunicación interna de la asociación dirigida a sus socios. No constituye asesoramiento legal.') }}
