@php($club = \App\Support\OrganisationIdentity::current()['name'])
{{ __('Hola :name,', ['name' => $name]) }}

{{ __('Telegram ya no entrega los avisos a tu cuenta (el bot se ha bloqueado o eliminado). A partir de ahora te llegarán en el correo de la mañana. Puedes volver a conectar Telegram desde tu perfil, en Avisos.') }}

—
{{ $club }}
