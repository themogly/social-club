<x-mail.shell :message="$message" :title="__('Avisos de hoy')">
    <p style="margin:0 0 16px;color:#475569;">{{ __('Estos avisos siguen activos esta mañana.') }}</p>
    @foreach ($bySede as $sede => $sections)
        <h2 style="margin:24px 0 8px;font-size:16px;color:#0f172a;">{{ $sede }}</h2>
        @foreach ($sections as $section)
            <p style="margin:0 0 4px;font-weight:600;color:#0f172a;">{{ $section['heading'] }}</p>
            <ul style="margin:0 0 12px;padding-left:18px;color:#475569;">
                @foreach ($section['lines'] as $line)
                    <li style="margin:2px 0;">{{ ltrim($line, '• ') }}</li>
                @endforeach
            </ul>
        @endforeach
    @endforeach
</x-mail.shell>
