@php($club = \App\Support\OrganisationIdentity::current()['name'])
{{ __('Hola :name,', ['name' => $memberName]) }}

{{ __('Esta es una plantilla de referencia. Las comunicaciones reales del club (bienvenida, renovación, avisos) reutilizan este diseño.') }}

{{ __('Un saludo,') }}
{{ $club }}

—
{{ __('Este mensaje te lo envía :club, tu asociación.', ['club' => $club]) }}
