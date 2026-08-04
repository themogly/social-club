@php($club = \App\Support\OrganisationIdentity::current()['name'])
{{ __('Hola :name,', ['name' => $memberName]) }}

{{ __('Este es el comprobante de tu aportación en la asociación (contribución a coste compartido, nunca una venta).') }}

{{ __('Fecha') }}: {{ $dispensedOn }}
{{ __('Cantidad') }}: {{ $grams }}
{{ __('Aportación total') }}: {{ $total }}

{{ __('Un saludo,') }}
{{ $club }}

—
{{ __('Este mensaje te lo envía :club, tu asociación.', ['club' => $club]) }}
