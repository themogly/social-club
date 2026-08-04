@php($club = \App\Support\OrganisationIdentity::current()['name'])
{{ __('Hola :name,', ['name' => $applicantName]) }}

{{ __('Gracias por tu interés en la asociación. Lamentamos comunicarte que, por ahora, no podemos aceptar tu solicitud.') }}
@if ($reason)

{{ __('Motivo:') }} {{ $reason }}
@endif

{{ __('Si crees que se trata de un error, ponte en contacto con la asociación.') }}

{{ __('Un saludo,') }}
{{ $club }}

—
{{ __('Este mensaje te lo envía :club, tu asociación.', ['club' => $club]) }}
