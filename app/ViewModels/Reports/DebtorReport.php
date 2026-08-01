<?php

namespace App\ViewModels\Reports;

use App\Enums\MembershipStatus;
use App\Models\Member;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Deudores — who owes the club money, so someone can chase it (prompt 82). The two obligations are kept
 * DISTINCT because they are different problems: WALLET DEBT (spent past the balance, bounded by a limit,
 * blocks at the counter past a threshold) and UNPAID CUOTA (the membership fee, wholly/partly unpaid, a
 * membership-standing issue that blocks dispensing entirely). A member can be in one, the other or both;
 * they appear ONCE, with both amounts, and the two totals never merge into a misleading "owes" figure.
 *
 * Every figure is the SAME source of truth the counter enforces: wallet debt is SUM(wallet_transactions),
 * the over-threshold flag is `balance < -wallet_debt_limit_cents` (i.e. the negation of
 * ResolveMemberEligibility::debtWithinThreshold), and unpaid cuota is fee_cents − SUM(fee payments) (the
 * negation of feesPaid). Those quantities are AGGREGATED IN SQL (grouped, not per-member) so the query
 * count does not scale with the membership — a per-member resolver loop is exactly the N+1 the dashboard
 * audit flagged. A test pins the aggregate against the resolver so the definition cannot drift.
 */
class DebtorReport extends AbstractReport
{
    private int $totalWalletDebtCents = 0;

    private int $totalCuotaCents = 0;

    private int $debtorCount = 0;

    public function key(): string
    {
        return 'deudores';
    }

    public function title(): string
    {
        return __('Deudores');
    }

    protected function build(): array
    {
        return [$this->debtors()];
    }

    public function summary(): array
    {
        $this->tables();

        return [
            ['label' => __('Socios con deuda'), 'value' => (string) $this->debtorCount, 'tone' => $this->debtorCount > 0 ? 'warning' : 'success'],
            ['label' => __('Deuda de monedero'), 'value' => Money::fromCents($this->totalWalletDebtCents)->formatted(), 'tone' => $this->totalWalletDebtCents > 0 ? 'warning' : 'success'],
            ['label' => __('Cuotas pendientes'), 'value' => Money::fromCents($this->totalCuotaCents)->formatted(), 'tone' => $this->totalCuotaCents > 0 ? 'warning' : 'success'],
            // The one figure the treasurer takes to the asamblea — the two obligations added ONLY at the very top.
            ['label' => __('Total adeudado'), 'value' => Money::fromCents($this->totalWalletDebtCents + $this->totalCuotaCents)->formatted(), 'tone' => 'warning'],
        ];
    }

    private function debtors(): ReportTable
    {
        $locationIds = $this->resolvedLocationIds();
        $threshold = (int) Settings::get('wallet_debt_limit_cents', 0); // the counter block threshold

        // 1) Wallet debt, grouped — one row per member with a negative balance across the scoped sedes.
        $wallet = DB::table('wallet_transactions')
            ->whereIn('location_id', $locationIds)
            ->groupBy('member_id')
            ->havingRaw('SUM(amount_cents) < 0')
            ->get([DB::raw('member_id'), DB::raw('SUM(amount_cents) as balance'), DB::raw('MIN(created_at) as first_move')])
            ->keyBy('member_id');

        // 2) Unpaid cuota, grouped per active membership; folded to one outstanding sum per member.
        $cuotaRows = DB::table('memberships')
            ->leftJoin('membership_fee_payments', 'membership_fee_payments.membership_id', '=', 'memberships.id')
            ->whereIn('memberships.location_id', $locationIds)
            ->where('memberships.status', MembershipStatus::ACTIVE->value)
            ->whereNull('memberships.deleted_at')
            ->groupBy('memberships.id', 'memberships.member_id', 'memberships.fee_cents', 'memberships.starts_at')
            ->havingRaw('memberships.fee_cents - COALESCE(SUM(membership_fee_payments.amount_cents), 0) > 0')
            ->get([
                'memberships.member_id as member_id',
                'memberships.fee_cents as fee_cents',
                'memberships.starts_at as starts_at',
                DB::raw('COALESCE(SUM(membership_fee_payments.amount_cents), 0) as paid'),
            ]);

        $cuota = [];
        foreach ($cuotaRows as $r) {
            $outstanding = (int) $r->fee_cents - (int) $r->paid;
            $cuota[$r->member_id]['outstanding'] = ($cuota[$r->member_id]['outstanding'] ?? 0) + $outstanding;
            $cuota[$r->member_id]['since'] = min($cuota[$r->member_id]['since'] ?? $r->starts_at, $r->starts_at);
        }

        // 3) The union of debtor ids, resolved to member details in ONE query.
        $memberIds = array_values(array_unique(array_merge($wallet->keys()->all(), array_keys($cuota))));
        $members = Member::query()->withoutGlobalScopes()
            ->whereIn('id', $memberIds)
            ->get(['id', 'member_no', 'first_name', 'last_name', 'email', 'phone'])
            ->keyBy('id');

        $rows = [];
        foreach ($memberIds as $id) {
            $member = $members->get($id);
            if ($member === null) {
                continue;
            }

            $walletDebt = $wallet->has($id) ? -(int) $wallet->get($id)->balance : 0; // owed = positive
            $cuotaDebt = (int) ($cuota[$id]['outstanding'] ?? 0);
            $overThreshold = $walletDebt > $threshold; // matches !debtWithinThreshold at the counter

            $this->totalWalletDebtCents += $walletDebt;
            $this->totalCuotaCents += $cuotaDebt;

            $rows[] = [
                'member_no' => (string) $member->member_no,
                'socio' => $member->fullName(),
                'contacto' => trim(($member->phone ?? '').' '.($member->email ?? '')) ?: '—',
                'monedero' => $walletDebt,
                'cuota' => $cuotaDebt,
                'bloqueado' => $overThreshold ? __('Sí') : __('No'),
                'desde' => $this->since($wallet->has($id) ? $wallet->get($id)->first_move : null, $cuota[$id]['since'] ?? null),
            ];
        }

        $this->debtorCount = count($rows);

        return new ReportTable(
            key: 'debtors',
            title: __('Deudores'),
            columns: [
                ReportColumn::text('member_no', __('Nº')),
                ReportColumn::text('socio', __('Socio')),
                ReportColumn::text('contacto', __('Contacto'), sortable: false),
                ReportColumn::money('monedero', __('Deuda de monedero')),
                ReportColumn::money('cuota', __('Cuota pendiente')),
                ReportColumn::text('bloqueado', __('Bloqueado en mostrador')),
                ReportColumn::date('desde', __('Desde')),
            ],
            rows: $rows,
            totals: [
                'monedero' => $this->totalWalletDebtCents,
                'cuota' => $this->totalCuotaCents,
            ],
            empty: __('Ningún socio debe dinero al club'),
            emptyHint: __('La deuda de monedero y las cuotas pendientes aparecerán aquí.'),
            defaultSort: 'monedero',
            defaultSortDir: 'desc',
            sortable: true,
            note: __(':n socios con deuda', ['n' => $this->debtorCount]),
        );
    }

    /** The earliest obligation date on the books for this member (approximation — see class docblock). */
    private function since(?string $walletFirstMove, ?string $cuotaSince): ?string
    {
        $dates = array_filter([$walletFirstMove, $cuotaSince]);

        return $dates === [] ? null : min($dates);
    }
}
