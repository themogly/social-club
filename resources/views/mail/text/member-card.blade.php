@php($club = \App\Support\OrganisationIdentity::current()['name'])
{{ __('Hola :name,', ['name' => $memberName]) }}
{{ __('Nº de socio/a: :no', ['no' => $memberNo]) }}

{{ __('Muestra este código en la entrada y en el mostrador.') }}

{{ __('Un saludo,') }}
{{ $club }}

—
{{ __('Este mensaje te lo envía :club, tu asociación.', ['club' => $club]) }}
