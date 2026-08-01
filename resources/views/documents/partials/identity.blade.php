{{-- Statutory identity header (prompt 115): logo + legal name + CIF/NIF + registered address. Fed by
     App\Support\OrganisationIdentity so every document names the responsible asociación the same way.
     Inline-styled so it renders identically inside any dompdf document view. --}}
@php($identity = $identity ?? \App\Support\OrganisationIdentity::current())
<table style="width:100%; border-collapse:collapse; margin:0;">
    <tr>
        @if (! empty($identity['logo']))
            <td style="width:60px; vertical-align:middle; padding:0 10px 0 0;">
                <img src="{{ $identity['logo'] }}" alt="" style="max-height:44px; max-width:120px;">
            </td>
        @endif
        <td style="vertical-align:middle;">
            <div style="font-size:11px; color:#0f172a; font-weight:bold; letter-spacing:.04em; text-transform:uppercase;">{{ $identity['display_name'] }}</div>
            @if (! empty($identity['tax_id']) || ! empty($identity['address']))
                <div style="font-size:9px; color:#475569; margin-top:1px;">
                    @if (! empty($identity['tax_id'])){{ __('CIF/NIF') }}: {{ $identity['tax_id'] }}@endif
                    @if (! empty($identity['tax_id']) && ! empty($identity['address'])) · @endif
                    @if (! empty($identity['address'])){{ $identity['address'] }}@endif
                </div>
            @endif
        </td>
    </tr>
</table>
