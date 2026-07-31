<?php

namespace App\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Local, self-contained avatar provider (prompt 61). Renders a member/staff's initials into an inline
 * SVG returned as a data: URI — NO network request, so no personal data (staff names) ever leaves the
 * server to a third party (Filament's default UiAvatarsProvider called https://ui-avatars.com/api/?name=…
 * on every admin page load, an undeclared outbound of personal data and a broken image on any locked-down
 * network). Handles non-ASCII names (accents) via multibyte string functions.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = trim((string) Filament::getNameForDefaultAvatar($record));

        $initials = collect(preg_split('/\s+/', $name) ?: [])
            ->filter()
            ->take(2)
            ->map(fn (string $segment): string => mb_strtoupper(mb_substr($segment, 0, 1)))
            ->implode('');

        $initials = $initials !== '' ? $initials : '?';

        // Brand blue #2563eb background, white initials — on-palette, self-hosted Inter with fallbacks.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 96 96">'
            .'<rect width="96" height="96" fill="#2563eb"/>'
            .'<text x="48" y="48" dy=".05em" fill="#ffffff" font-family="Inter, ui-sans-serif, system-ui, sans-serif"'
            .' font-size="40" font-weight="600" text-anchor="middle" dominant-baseline="central">'
            .htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8')
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
