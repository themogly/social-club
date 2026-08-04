@php($club = \App\Support\OrganisationIdentity::current()['name'])
{{ __('Te damos la bienvenida.') }}

{{ __('Has recibido una invitación para asociarte. Pulsa el botón para completar tu solicitud de alta. El enlace caduca el :date.', ['date' => $expiresOn]) }}

{{ $url }}

{{ __('Si no esperabas esta invitación, puedes ignorar este correo.') }}

{{ __('Un saludo,') }}
{{ $club }}

—
{{ __('Este mensaje te lo envía :club, tu asociación.', ['club' => $club]) }}
