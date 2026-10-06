@php($club = \App\Support\OrganisationIdentity::current()['name'])
{{ $text }}

{{ __('Te llega por correo porque Telegram no está entregando los avisos ahora mismo. El club ya lo sabe.') }}

—
{{ $club }}
