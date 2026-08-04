<x-mail.shell :message="$message" :title="__('Tu comprobante de aportación')">
    <p style="margin:0 0 16px;">{{ __('Hola :name,', ['name' => $memberName]) }}</p>
    <p style="margin:0 0 16px;color:#475569;">
        {{ __('Este es el comprobante de tu aportación en la asociación (contribución a coste compartido, nunca una venta).') }}
    </p>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;margin:8px 0 0;">
        <tr>
            <td style="padding:12px 16px;color:#475569;">{{ __('Fecha') }}</td>
            <td style="padding:12px 16px;text-align:right;font-weight:600;">{{ $dispensedOn }}</td>
        </tr>
        <tr>
            <td style="padding:12px 16px;color:#475569;border-top:1px solid #e2e8f0;">{{ __('Cantidad') }}</td>
            <td style="padding:12px 16px;text-align:right;font-weight:600;border-top:1px solid #e2e8f0;">{{ $grams }}</td>
        </tr>
        <tr>
            <td style="padding:12px 16px;color:#0f172a;font-weight:700;border-top:1px solid #e2e8f0;">{{ __('Aportación total') }}</td>
            <td style="padding:12px 16px;text-align:right;font-weight:700;border-top:1px solid #e2e8f0;">{{ $total }}</td>
        </tr>
    </table>
</x-mail.shell>
