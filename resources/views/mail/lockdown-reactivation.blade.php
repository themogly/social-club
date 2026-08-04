<x-mail.shell :message="$message" :title="__('Reactivar el acceso del club')">
    <div style="text-align:center;">
        @if ($isDrill)
            <p style="margin:0 0 16px;font-weight:600;color:#d97706;">{{ __('Esto es un simulacro.') }}</p>
        @endif
        <p style="margin:0 0 16px;">{{ __('Hola :name,', ['name' => $ownerName]) }}</p>
        <p style="margin:0 0 20px;color:#475569;">{{ __('Se ha activado el bloqueo de seguridad del club. Cuando sea seguro hacerlo, pulsa el botón para reactivar el acceso. El enlace caduca en :h horas y solo puede usarse una vez.', ['h' => $ttlHours]) }}</p>
        <a href="{{ $url }}"
           style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;">
            {{ __('Reactivar el acceso') }}
        </a>
        <p style="margin:20px 0 0;color:#94a3b8;font-size:12px;">
            {{ __('Si no reconoces este bloqueo, no reactives y contacta con el resto del equipo. El acceso se restablecerá solo pasado el plazo de seguridad.') }}
        </p>
    </div>
</x-mail.shell>
