<x-mail.shell :message="$message" :title="__('Convocatoria de asamblea')">
    <p style="margin:0 0 4px;text-transform:uppercase;letter-spacing:.04em;font-size:12px;color:#475569;">{{ __('Convocatoria de asamblea :type', ['type' => $typeLabel]) }}</p>
    <h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;">{{ $title }}</h1>

    <p style="margin:0 0 16px;">{{ __('Hola :name,', ['name' => $memberName]) }}</p>
    <p style="margin:0 0 20px;color:#475569;">
        {{ __('Por la presente se le convoca a la asamblea general de la asociación, que se celebrará:') }}
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;border:1px solid #e2e8f0;border-radius:8px;">
        <tr>
            <td style="padding:10px 14px;color:#475569;width:40%;border-bottom:1px solid #f1f5f9;">{{ __('Fecha y hora') }}</td>
            <td style="padding:10px 14px;font-weight:600;border-bottom:1px solid #f1f5f9;">{{ $heldAt }}</td>
        </tr>
        @if ($secondCallAt)
            <tr>
                <td style="padding:10px 14px;color:#475569;border-bottom:1px solid #f1f5f9;">{{ __('Segunda convocatoria') }}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;">{{ $secondCallAt }}</td>
            </tr>
        @endif
        @if ($venue)
            <tr>
                <td style="padding:10px 14px;color:#475569;border-bottom:1px solid #f1f5f9;">{{ __('Lugar') }}</td>
                <td style="padding:10px 14px;border-bottom:1px solid #f1f5f9;">{{ $venue }}</td>
            </tr>
        @endif
        @if ($quorumRequired !== null)
            <tr>
                <td style="padding:10px 14px;color:#475569;">{{ __('Quórum requerido (primera convocatoria)') }}</td>
                <td style="padding:10px 14px;">{{ $quorumRequired }}</td>
            </tr>
        @endif
    </table>

    @if (!empty($agenda))
        <h2 style="margin:0 0 8px;font-size:15px;">{{ __('Orden del día') }}</h2>
        <ol style="margin:0 0 20px;padding-left:20px;color:#475569;">
            @foreach ($agenda as $punto)
                <li style="margin:0 0 4px;">{{ $punto }}</li>
            @endforeach
        </ol>
    @endif

    @if ($body)
        <p style="margin:0 0 20px;color:#475569;white-space:pre-line;">{{ $body }}</p>
    @endif

    <x-slot:footer>
        {{ __('Comunicación interna de la asociación dirigida a sus socios. No constituye asesoramiento legal.') }}
    </x-slot:footer>
</x-mail.shell>
