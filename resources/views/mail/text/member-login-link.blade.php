@php($club = \App\Support\OrganisationIdentity::current()['name'])
{{ __('Hola :name,', ['name' => $memberName]) }}

{{ __('Pulsa el botón para acceder a tu área de socio/a. El enlace caduca en :min minutos y solo puede usarse una vez.', ['min' => $ttl]) }}

{{ $url }}

{{ __('Si no has solicitado este enlace, puedes ignorar este correo.') }}

{{ __('Un saludo,') }}
{{ $club }}

—
{{ __('Este mensaje te lo envía :club, tu asociación.', ['club' => $club]) }}
