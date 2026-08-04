@php($club = \App\Support\OrganisationIdentity::current()['name'])
{{ __('Hola :name,', ['name' => $memberName]) }}

{{ __('¡Bienvenido/a a la asociación! Tu solicitud ha sido aprobada y ya eres socio/a.') }}

{{ __('Tu número de socio/a es :no. Recibirás tu carné con el código QR en un correo aparte.', ['no' => $memberNo]) }}

{{ $loginUrl }}

{{ __('Un saludo,') }}
{{ $club }}

—
{{ __('Este mensaje te lo envía :club, tu asociación.', ['club' => $club]) }}
