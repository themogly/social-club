<?php

namespace App\Http\Controllers\Socio;

use App\Actions\MemberAuth\ConsumeMemberLoginToken;
use App\Actions\MemberAuth\IssueMemberLoginLink;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Passwordless auth for the member PWA — a SEPARATE guard from staff. A magic link
 * is emailed (single-use, short-lived), the response never reveals whether an email
 * is a member, and the request endpoint is rate-limited (route throttle).
 */
class AuthController extends Controller
{
    public function show(): View
    {
        return view('socio.login');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        (new IssueMemberLoginLink)->handle($data['email'], $request->ip());

        // Deliberately the same response whether or not the email is a member.
        return back()->with('status', __('Si tu correo está registrado, te hemos enviado un enlace de acceso.'));
    }

    public function verify(Request $request, string $token): RedirectResponse
    {
        $member = (new ConsumeMemberLoginToken)->handle($token);

        if ($member === null) {
            return redirect()->route('socio.login')
                ->withErrors(['email' => __('El enlace no es válido o ha caducado. Solicita uno nuevo.')]);
        }

        Auth::guard('member')->login($member, remember: true);
        $request->session()->regenerate();

        return redirect()->route('socio.home');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('member')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('socio.login');
    }
}
