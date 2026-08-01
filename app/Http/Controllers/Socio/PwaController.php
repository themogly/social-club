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
use App\Support\Wallet;
use Illuminate\Http\JsonResponse;
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
