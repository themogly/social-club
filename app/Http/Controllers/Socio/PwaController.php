<?php

namespace App\Http\Controllers\Socio;

use App\Actions\Dispensing\ResolveMemberLimits;
use App\Actions\Members\IssueMemberToken;
use App\Actions\Pricing\ResolvePrice;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\Scopes\LocationScope;
use App\Models\Scopes\OrganisationScope;
use App\Support\Qr;
use App\Support\Settings;
use App\Support\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The member PWA. EVERYTHING is scoped to the authenticated member (the `member`
 * guard) — there is no member id in any route, so one member can never reach
 * another's card, allowance, wallet, history or export. Limits, prices and balances
 * come from the SAME resolvers the counter uses — no second arithmetic.
 */
class PwaController extends Controller
{
    private function member(): Member
    {
        /** @var Member $member */
        $member = Auth::guard('member')->user();

        return $member;
    }

    /** The member's home location — their most recent active membership's sede. */
    private function location(Member $member): ?Location
    {
        // Escape the org + location scopes (a member's memberships span sedes and the member guard sets no
        // active scope) but NOT the soft-delete scope — a deleted membership must not resolve as their home
        // (prompt 95 sweep).
        return $member->memberships()
            ->withoutGlobalScope(OrganisationScope::class)
            ->withoutGlobalScope(LocationScope::class)
            ->where('status', MembershipStatus::ACTIVE->value)
            ->latest('id')->first()?->location;
    }

    public function home(): View
    {
        $member = $this->member();
        $location = $this->location($member);

        // A fresh, single-active QR-card token (revokes the previous card). The page is
        // service-worker cached so the last-issued QR renders offline.
        $token = (new IssueMemberToken)->handle($member);

        $snapshot = $location !== null
            ? (new ResolveMemberLimits)->handle($member, $location)
            : null;

        return view('socio.home', [
            'member' => $member,
            'location' => $location,
            'qrPng' => Qr::png($token),
            'snapshot' => $snapshot,
            'walletCents' => $location !== null ? Wallet::balance($member->id, $location->id) : 0,
        ]);
    }

    public function menu(): View
    {
        $member = $this->member();
        $location = $this->location($member);

        $genetics = collect();
        if ($location !== null) {
            $resolver = new ResolvePrice;
            // Only genetics SELLABLE at this member's sede (prompt 95) — the same definition the dispensary
            // POS uses, so the two agree. An unpriced strain is filtered out, never a thrown error that 500s
            // the menu. Escape ONLY the organisation scope (the menu spans the member's org, which the
            // member guard does not set as the active scope) — NOT the soft-delete scope, so a deleted
            // strain never reaches a member. forGenetic() is now only ever called where a price exists.
            $genetics = Genetic::query()->withoutGlobalScope(OrganisationScope::class)
                ->where('organisation_id', $member->organisation_id)
                ->sellableAt($location->id)
                ->orderBy('name')
                ->get()
                ->map(fn (Genetic $g): array => [
                    'genetic' => $g,
                    'price' => $resolver->forGenetic($g, $location, $member),
                    // Prompt 185 — a STATE, never a quantity. Resolved per SEDE (the member's own), because a
                    // genetic in stock at Sede Norte and empty at Sede Centro must not read as available to
                    // someone walking into Centro. No gram figure is passed to the view at all, so none can
                    // leak into the markup, an attribute or a payload.
                    'availability' => $g->availabilityAt($location->id),
                ]);
        }

        return view('socio.menu', ['member' => $member, 'location' => $location, 'genetics' => $genetics]);
    }

    public function history(): View
    {
        $member = $this->member();

        return view('socio.history', [
            'member' => $member,
            'dispensations' => $member->dispensations()->withoutGlobalScopes()->with('lines')->latest('dispensed_at')->limit(50)->get(),
            'orders' => $member->orders()->withoutGlobalScopes()->latest()->limit(50)->get(),
            'wallet' => $member->walletTransactions()->withoutGlobalScopes()->latest()->limit(50)->get(),
            'visits' => $member->checkIns()->withoutGlobalScopes()->latest('checked_in_at')->limit(50)->get(),
        ]);
    }

    /**
     * The member's own language switch (prompt 96). Reuses the admin switcher's pattern: persist the choice
     * to the member's row (follows them across devices) AND drop a session override so SetLocale applies it
     * on the very next request — no re-login. Only an enabled locale is honoured; anything else is ignored.
     */
    public function switchLocale(Request $request): RedirectResponse
    {
        $locale = (string) $request->input('locale');
        $enabled = Settings::get('enabled_locales', ['es', 'en']);

        if (is_array($enabled) && in_array($locale, $enabled, true)) {
            // A member's choice follows them across devices; an APPLICANT has no row to persist to
            // (prompt 167), so for them it is a session choice — which is all they need for the one
            // form they are filling in. The session override is written either way, so the change
            // applies on the very next request.
            $member = Auth::guard('member')->user();
            if ($member instanceof Member) {
                $member->forceFill(['locale' => $locale])->save();
            }

            $request->session()->put('locale', $locale);
        }

        // An applicant arrives from an emailed link, where a referer is often absent — `back()` alone
        // would drop them on the PWA home and straight into the member login. Only a same-origin
        // in-app URL is honoured, never an arbitrary redirect target from the request.
        $returnTo = (string) $request->input('return_to');
        if ($returnTo !== '' && str_starts_with($returnTo, url('/'))) {
            return redirect()->to($returnTo);
        }

        return back();
    }

    /** The member's own RGPD data export (their data only). */
    public function export(): JsonResponse
    {
        $member = $this->member();

        $payload = [
            'socio' => [
                'member_no' => $member->member_no,
                'nombre' => $member->fullName(),
                'email' => $member->email,
                'alta' => $member->joined_at,
                'estado' => $member->status->value,
                'declaracion_asociacion_unica' => $member->sole_association_declared_at,
            ],
            'memberships' => $member->memberships()->withoutGlobalScopes()->get(['location_id', 'tier_id', 'status', 'starts_at', 'expires_at']),
            'dispensaciones' => $member->dispensations()->withoutGlobalScopes()->with('lines:id,dispensation_id,grams_cg,line_total_cents,genetic_name_snapshot')->get(['id', 'location_id', 'total_cents', 'dispensed_at']),
            'pedidos' => $member->orders()->withoutGlobalScopes()->get(['id', 'location_id', 'total_cents', 'status', 'created_at']),
            'monedero' => $member->walletTransactions()->withoutGlobalScopes()->get(['type', 'amount_cents', 'balance_after_cents', 'created_at']),
            'visitas' => $member->checkIns()->withoutGlobalScopes()->get(['location_id', 'checked_in_at', 'checked_out_at', 'method']),
        ];

        return response()->json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="mis-datos.json"',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
