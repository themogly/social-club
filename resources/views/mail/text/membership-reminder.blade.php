@php($club = \App\Support\OrganisationIdentity::current()['name'])
{{ __('Hola :name,', ['name' => $memberName]) }}

{{ __('Tu membresía vence el :date. Renuévala en tu próxima visita para seguir disfrutando de la asociación.', ['date' => $expiresOn]) }}

{{ __('Un saludo,') }}
{{ $club }}

—
{{ __('Este mensaje te lo envía :club, tu asociación.', ['club' => $club]) }}
