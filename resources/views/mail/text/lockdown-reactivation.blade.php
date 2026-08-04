@php($club = \App\Support\OrganisationIdentity::current()['name'])
@if ($isDrill){{ __('Esto es un simulacro.') }}

@endif
{{ __('Hola :name,', ['name' => $ownerName]) }}

{{ __('Se ha activado el bloqueo de seguridad del club. Cuando sea seguro hacerlo, pulsa el botón para reactivar el acceso. El enlace caduca en :h horas y solo puede usarse una vez.', ['h' => $ttlHours]) }}

{{ $url }}

{{ __('Si no reconoces este bloqueo, no reactives y contacta con el resto del equipo. El acceso se restablecerá solo pasado el plazo de seguridad.') }}

{{ __('Un saludo,') }}
{{ $club }}

—
{{ __('Este mensaje te lo envía :club, tu asociación.', ['club' => $club]) }}
