@props(['title', 'message'])
{{-- Shared mail shell (prompt 150). Every member-facing email renders through here, so the branding is the
     CLUB's, not the product's: the header is the club's uploaded logo (CID-embedded) or, until a club can
     upload one, the club's NAME as a text wordmark — NEVER the product `mail/logo.png`, which reads
     "CSC platform" and would re-brand the club's email as ours. The club name resolves through
     OrganisationIdentity (org trading name → product name only for a truly unconfigured org). --}}
@php
    $identity = \App\Support\OrganisationIdentity::current();
    $clubName = $identity['name'];
    $logo = \App\Support\OrganisationIdentity::mailLogo();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#f8fafc;font-family:Inter,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                    <tr>
                        <td style="padding:24px;border-bottom:1px solid #e2e8f0;">
                            @if ($logo)
                                {{-- The club's own logo, CID-embedded (never a data: URI or hot-linked asset()). --}}
                                <img src="{{ $message->embedData($logo['data'], 'club-logo', $logo['mime']) }}"
                                     alt="{{ $clubName }}" style="display:block;border:0;outline:none;max-height:64px;max-width:280px;">
                            @else
                                {{-- No club logo yet (see the flagged gap in DECISIONS): the club NAME as a wordmark. --}}
                                <span style="display:block;font-size:22px;font-weight:700;line-height:1.2;color:#2563eb;">{{ $clubName }}</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px;">
                            {{ $slot }}
                            <p style="margin:24px 0 0;color:#475569;font-size:13px;">{{ __('Un saludo,') }}<br>{{ $clubName }}</p>
                        </td>
                    </tr>
                </table>
                <p style="max-width:600px;width:100%;margin:12px auto 0;color:#94a3b8;font-size:11px;text-align:center;">
                    @if (isset($footer) && ! $footer->isEmpty())
                        {{ $footer }}
                    @else
                        {{ __('Este mensaje te lo envía :club, tu asociación.', ['club' => $clubName]) }}
                    @endif
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
