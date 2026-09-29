<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prompt 310 — how a panel middleware refuses a request so the person lands SOMEWHERE. A page load is redirected, as
 * always. A Livewire request (a button press on a page already open) used to get a bare 403, or a redirect Livewire
 * rendered as HTML in its error box: the owner's "403" on *Reponer*. It now gets a 403 that names its reason and where
 * to go (`X-Csc-Reason` / `X-Csc-Location`); the panel's one request hook (`filament.panel-refusal-hook`) navigates
 * there instead of Livewire's error box. The refused request never reaches the component, so nothing it asked for
 * happens.
 */
final class PanelRefusal
{
    public const REASON = 'X-Csc-Reason';

    public const LOCATION = 'X-Csc-Location';

    /** `$rememberPage`: come back here afterwards (the page the button was on, for a Livewire press). */
    public static function to(Request $request, string $reason, string $url, bool $rememberPage = false): Response
    {
        if ($request->hasHeader('X-Livewire')) {
            if ($rememberPage && ($page = self::pageOf($request)) !== null) {
                session()->put('url.intended', $page);
            }

            self::refuse($reason, $url);
        }

        if ($rememberPage) {
            session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->to($url);
    }

    /**
     * Refuse a Livewire request with its reason and where to go. THROWN, not returned: on a Livewire update Livewire runs
     * persistent middleware itself and drops any response that is not a redirect — a returned 403 would let the press
     * through. Also used by the global Livewire confinements ({@see CounterLockConfinement}).
     */
    public static function refuse(string $reason, string $url): never
    {
        abort(response('', 403, [self::REASON => $reason, self::LOCATION => $url]));
    }

    /** The page a Livewire request came from — its Referer, only when it is this app's own page. */
    private static function pageOf(Request $request): ?string
    {
        $referer = (string) $request->headers->get('referer', '');
        $root = rtrim(url('/'), '/');

        return $referer !== '' && ($referer === $root || Str::startsWith($referer, $root.'/')) ? $referer : null;
    }
}
