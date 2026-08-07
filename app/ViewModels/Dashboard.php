<?php

namespace App\ViewModels;

use App\Enums\BatchStatus;
use App\Enums\DashboardAlert;
use App\Enums\DispensationStatus;
use App\Enums\MemberKind;
use App\Enums\MembershipStatus;
use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Enums\TillSessionStatus;
use App\Models\Batch;
use App\Models\CheckIn;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\Membership;
use App\Models\Order;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\BusinessDay;
use App\Support\Period;
use App\Support\Settings;
use App\Support\StockCeiling;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's data assembly — every figure a LIVE aggregate (transactional data
 * is never cached), scoped to the organisation, the locations the actor may see and
 * the selected period. Stat cards are computed here (each one has a direct control
 * query in the tests, because a plausible-but-wrong dashboard number is the worst
 * kind of bug); the Filament widgets are a thin render over this object. Role decides
 * WHAT is visible — staff get no finance figures, only an owner sees the org rollup.
 */
class Dashboard
{
    /** @var list<string>|null memoised resolved location ids (null before first resolve) */
    private ?array $resolved = null;

    /**
     * @param  list<string>|null  $locationIds  null = every location in the org (owner rollup)
     */
    public function __construct(
        public readonly string $organisationId,
        public readonly ?array $locationIds,
        public readonly Period $period,
        public readonly bool $canSeeFinance = true,
        public readonly bool $isRollup = false,
    ) {}

    public static function for(User $user, Period $period): self
    {
        $scope = app(ActiveScope::class);
        $activeLocation = $scope->locationId();
        $rollup = $user->hasRole(Role::OWNER->value) && $activeLocation === null;

        return new self(
            organisationId: (string) $scope->organisationId(),
            locationIds: $rollup ? null : [$activeLocation ?? ''],
            period: $period,
            canSeeFinance: $user->canAny(['reports.view', 'reports.view.all']),
            isRollup: $rollup,
        );
    }

    // --- Stat-card figures (each pinned by a control query in the tests) -------------

    public function contributionsCents(?Period $period = null): int
    {
        return (int) $this->dispensations($period)->sum('total_cents');
    }

    /** @return array{cash: int, wallet: int} */
    public function contributionSplit(?Period $period = null): array
    {
        return [
            'cash' => (int) $this->dispensations($period)->sum('cash_cents'),
            'wallet' => (int) $this->dispensations($period)->sum('wallet_cents'),
        ];
    }

    public function gramsDispensedCg(?Period $period = null): int
    {
        $period ??= $this->period;

        return (int) DispensationLine::query()->withoutGlobalScopes()
            ->whereHas('dispensation', fn (Builder $q) => $this->scopeDispensations($q, $period))
            ->sum('grams_cg');
    }

    public function insideNow(): int
    {
        return (int) $this->scopeByLocation(CheckIn::query()->withoutGlobalScopes())
            ->whereNull('checked_out_at')->count();
    }

    /**
     * Sign-ins recorded today at the sedes in scope (prompt 205).
     *
     * The counter hub needed it and nothing else computed it — `insideNow()` counts people who have not
     * checked OUT, which answers a different question. Added HERE rather than on the hub, because the rule
     * is one writer per fact and the hub is a screen.
     *
     * The day is the club's business day (prompt 30's boundary), not midnight: a session that runs past
     * 00:00 is one evening's work and one number.
     */
    public function checkInsToday(): int
    {
        // Per location, because the business-day cutoff is a per-sede setting: one query per sede in scope
        // (exactly ONE on the counter hub, which always has a single active sede; a handful on the owner
        // rollup). Summing a shared window would silently mis-slice a sede that closes at a different hour.
        return $this->scopeLocations()->sum(function (Location $location): int {
            [$start, $end] = BusinessDay::window($location);

            return (int) CheckIn::query()->withoutGlobalScopes()
                ->where('location_id', $location->id)
                ->whereBetween('checked_in_at', [$start, $end])
                ->count();
        });
    }

    /**
     * The operators who have recorded something at these sedes today (prompt 205's "On shift").
     *
     * Derived from what people actually DID — the operator stamped on today's dispensations and bar orders —
     * rather than from a presence table, because there isn't one and inventing one to fill a panel is how a
     * number becomes decoration. Two queries, both indexed on (location_id, created_at).
     *
     * @return list<string>
     */
    public function operatorsOnShift(): array
    {
        $ids = $this->dispensations(Period::today())->distinct()->pluck('operator_id')
            ->merge($this->orders(Period::today())->distinct()->pluck('operator_id'))
            ->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')->all();
    }

    public function transactionCount(?Period $period = null): int
    {
        return $this->dispensations($period)->count() + $this->orders($period)->count();
    }

    public function averageContributionCents(?Period $period = null): int
    {
        $count = $this->dispensations($period)->count();

        return $count === 0 ? 0 : intdiv($this->contributionsCents($period), $count);
    }

    public function activeMembers(): int
    {
        return (int) Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->organisationId)
            ->whereHas('memberships', fn (Builder $q) => $this->scopeByLocation($q)
                ->where('status', MembershipStatus::ACTIVE->value))
            ->count();
    }

    public function newMembersThisMonth(): int
    {
        [$start, $end] = Period::thisMonth()->bounds();

        return (int) Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->organisationId)
            ->where('joined_at', '>=', $start)->where('joined_at', '<', $end)->count();
    }

    public function stockOnHandCg(): int
    {
        // Gram-equivalent across BOTH kinds: WEIGHT batches' remaining_cg + UNIT batches'
        // remaining_units × grams_per_unit_cg — one premises-wide figure.
        return (int) Batch::query()->withoutGlobalScopes()
            ->join('genetics', 'batches.genetic_id', '=', 'genetics.id')
            ->whereIn('batches.location_id', $this->resolvedLocationIds())
            ->where('batches.status', BatchStatus::OPEN->value)
            ->selectRaw("COALESCE(SUM(CASE WHEN genetics.unit_type = 'UNIT' THEN batches.remaining_units * genetics.grams_per_unit_cg ELSE batches.remaining_cg END), 0) as cg")
            ->value('cg');
    }

    public function stockValueCents(): int
    {
        // Σ gram-equivalent × cost_per_gram_cents ÷ 100 (cg → g), per batch, both kinds.
        return $this->scopeByLocation(Batch::query()->withoutGlobalScopes())
            ->where('status', BatchStatus::OPEN->value)
            ->with('genetic')
            ->get(['id', 'genetic_id', 'remaining_cg', 'remaining_units', 'cost_per_gram_cents'])
            ->reduce(fn (int $carry, Batch $b): int => $carry + intdiv($b->onHandCg() * (int) $b->cost_per_gram_cents, 100), 0);
    }

    public function daysOfInventory(): ?int
    {
        $onHand = $this->stockOnHandCg();
        if ($onHand === 0) {
            return 0;
        }

        // Average daily grams dispensed over the trailing 30 days.
        $today = Period::today();
        $trailing = new Period($today->start->subDays(30), $today->end, 'custom');
        $perDay = intdiv($this->gramsDispensedCg($trailing), 30);

        return $perDay <= 0 ? null : intdiv($onHand, $perDay);
    }

    public function walletFloatCents(): int
    {
        return $this->walletBalances()['float'];
    }

    public function walletDebtCents(): int
    {
        return $this->walletBalances()['debt'];
    }

    /** Variance on the most recent reconciled session in scope; null when there are none. */
    public function lastSessionVarianceCents(): ?int
    {
        $session = $this->scopeByLocation(TillSession::query()->withoutGlobalScopes())
            ->whereNotNull('variance_cents')->latest('closed_at')->first();

        return $session?->variance_cents?->cents;
    }

    public function hasUnreconciledTill(): bool
    {
        return $this->scopeByLocation(TillSession::query()->withoutGlobalScopes())
            ->where('status', TillSessionStatus::OPEN->value)->exists();
    }

    // --- Alerts ---------------------------------------------------------------------

    /**
     * @return list<array{severity: string, key: string, count: int}>
     */
    public function alerts(): array
    {
        $alerts = [];

        // Prompt 207: the key and the severity come from DashboardAlert, so an eighth alert cannot be added
        // here and then arrive at either dashboard as an unhandled `default` — the counter's was a silent
        // `null` href, which renders as a deliberate-looking dead <p>.
        $add = function (DashboardAlert $alert, int $count) use (&$alerts): void {
            if ($count > 0) {
                $alerts[] = ['severity' => $alert->severity(), 'key' => $alert->value, 'count' => $count];
            }
        };

        $add(DashboardAlert::MEMBERS_OVER_LIMIT, $this->membersOverLimit());
        $add(DashboardAlert::ACTIVE_MEMBER_CAP, $this->membersOverCap());
        $add(DashboardAlert::UNRECONCILED_TILL, $this->hasUnreconciledTill()
            ? $this->scopeByLocation(TillSession::query()->withoutGlobalScopes())
                ->where('status', TillSessionStatus::OPEN->value)->count()
            : 0);
        $add(DashboardAlert::BATCHES_EXPIRING, $this->expiringBatches());
        $add(DashboardAlert::STOCK_CEILING_EXCEEDED, count($this->ceilingBreaches()));
        $add(DashboardAlert::MEMBERSHIPS_EXPIRING, $this->expiringMemberships());
        $add(DashboardAlert::PENDING_APPLICATIONS, $this->pendingApplications());

        return $alerts;
    }

    /**
     * Active members in the org against the soft cap (prompt 34). Temporary members are
     * counted only when `temporary_count_toward_cap` is on. Returns the count when at/over
     * the cap (so the dashboard warns), else 0. Org-wide — the cap is a club-wide figure.
     */
    public function membersOverCap(): int
    {
        $cap = (int) Settings::get('active_member_cap', 0);
        if ($cap <= 0) {
            return 0;
        }

        $count = (int) Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->organisationId)
            ->when(! (bool) Settings::get('temporary_count_toward_cap', true),
                fn (Builder $q): Builder => $q->where('kind', '!=', MemberKind::TEMPORARY->value))
            ->whereHas('memberships', fn (Builder $q) => $q->where('status', MembershipStatus::ACTIVE->value))
            ->count();

        return $count >= $cap ? $count : 0;
    }

    public function membersOverLimit(): int
    {
        [$start, $end] = Period::thisMonth()->bounds();

        // The members carrying a per-member monthly override (the only limit this alert measures).
        $limits = Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->organisationId)
            ->whereNotNull('monthly_limit_cg')->where('monthly_limit_cg', '>', 0)
            ->pluck('monthly_limit_cg', 'id');

        if ($limits->isEmpty()) {
            return 0;
        }

        // ONE aggregate for this month's COMPLETED grams per member — not a DispensationLine query per member
        // (prompt 79: the per-member closure was a landing-page N+1, ~401 queries / ~20 s at scale).
        $used = DispensationLine::query()->withoutGlobalScopes()
            ->join('dispensations', 'dispensations.id', '=', 'dispensation_lines.dispensation_id')
            ->whereIn('dispensations.member_id', $limits->keys())
            ->where('dispensations.status', DispensationStatus::COMPLETED->value)
            ->where('dispensations.dispensed_at', '>=', $start)->where('dispensations.dispensed_at', '<', $end)
            ->groupBy('dispensations.member_id')
            ->selectRaw('dispensations.member_id as member_id, SUM(dispensation_lines.grams_cg) as used_cg')
            ->pluck('used_cg', 'member_id');

        return $limits->filter(
            fn (int $limitCg, string $memberId): bool => (int) ($used[$memberId] ?? 0) >= $limitCg
        )->count();
    }

    public function expiringBatches(): int
    {
        $days = (int) Settings::get('batch_expiry_window_days', 30);

        return $this->scopeByLocation(Batch::query()->withoutGlobalScopes())
            ->where('status', BatchStatus::OPEN->value)
            ->whereNotNull('expires_on')
            ->whereBetween('expires_on', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->count();
    }

    /** @return list<string> location ids whose on-site stock exceeds the compliance ceiling */
    public function ceilingBreaches(): array
    {
        return $this->scopeLocations()
            ->filter(fn (Location $l): bool => StockCeiling::forLocation($l)['exceeded'])
            ->map(fn (Location $l): string => $l->id)->values()->all();
    }

    /**
     * Per-sede legal stock HEADROOM (prompt 134) — how many grams the club can still bring on-site before the
     * compliance ceiling, with the three inputs that make the number auditable and actionable. This is pure
     * presentation of what StockCeiling already computes (and prompt 110 already enforces at intake); the
     * figure here and the one intake blocks on are the SAME. Per sede always — the ceiling is per premises;
     * a combined figure would be meaningless. Weight stays centigrams; grams only at the display edge.
     *
     * @return list<array{location: string, headroom_cg: int, over_cg: int, on_site_cg: int, ceiling_cg: int, active_members: int, daily_limit_cg: int, ceiling_days: int, exceeded: bool}>
     */
    public function ceilingHeadroom(): array
    {
        return $this->scopeLocations()->map(function (Location $location): array {
            $c = StockCeiling::forLocation($location);
            $headroom = $c['ceiling_cg'] - $c['on_site_cg'];

            return [
                'location' => $location->name,
                'headroom_cg' => max(0, $headroom),
                'over_cg' => max(0, -$headroom),
                'on_site_cg' => $c['on_site_cg'],
                'ceiling_cg' => $c['ceiling_cg'],
                'active_members' => $c['active_members'],
                'daily_limit_cg' => $c['daily_limit_cg'],
                'ceiling_days' => $c['ceiling_days'],
                'exceeded' => $c['exceeded'],
            ];
        })->values()->all();
    }

    /**
     * Memberships in the renewal window at this sede.
     *
     * Through `Membership::expiringSoon()` since prompt 207 — the same scope the counter's worklist resolves
     * its ROWS from, so the count in the rail and the names at the far end can never be different sets. It
     * also fixes two disagreements this method had with `SweepMembershipExpiry`: a hardcoded 30 days where
     * the sweep reads `expiring_soon_days`, and `status = ACTIVE` where the sweep flips exactly these rows to
     * `EXPIRING_SOON` — so the nightly sweep had been emptying this count.
     */
    public function expiringMemberships(): int
    {
        return $this->scopeByLocation(Membership::query()->withoutGlobalScopes())
            ->expiringSoon()
            ->count();
    }

    /**
     * Applications waiting to be reviewed at this sede.
     *
     * Through `MemberApplication::awaitingReview()` since prompt 207 — the same scope the counter's Alta panel
     * lists from. It counted every PENDING row before, including invitations nobody had filled in yet, so the
     * hub could report a pending application and land the operator on a panel with nothing in it.
     */
    public function pendingApplications(): int
    {
        return $this->scopeByLocation(MemberApplication::query()->withoutGlobalScopes())
            ->awaitingReview()->count();
    }

    // --- Payload for role/location tests --------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'is_rollup' => $this->isRollup,
            'grams_dispensed_cg' => $this->gramsDispensedCg(),
            'inside_now' => $this->insideNow(),
            'transactions' => $this->transactionCount(),
            'active_members' => $this->activeMembers(),
            'alerts' => $this->alerts(),
        ];

        // Finance figures are withheld from staff — assert on the payload, not the HTML.
        if ($this->canSeeFinance) {
            $payload['contributions_cents'] = $this->contributionsCents();
            $payload['stock_value_cents'] = $this->stockValueCents();
            $payload['wallet_float_cents'] = $this->walletFloatCents();
            $payload['wallet_debt_cents'] = $this->walletDebtCents();
            $payload['last_session_variance_cents'] = $this->lastSessionVarianceCents();
        }

        return $payload;
    }

    // --- Scoping helpers ------------------------------------------------------------

    /** @return list<string> */
    private function resolvedLocationIds(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        return $this->resolved = $this->locationIds ?? Location::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->organisationId)->pluck('id')->all();
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopeByLocation(Builder $query, string $column = 'location_id'): Builder
    {
        return $query->whereIn($column, $this->resolvedLocationIds());
    }

    /**
     * @return Builder<Dispensation>
     */
    private function dispensations(?Period $period = null): Builder
    {
        return $this->scopeDispensations(Dispensation::query()->withoutGlobalScopes(), $period ?? $this->period);
    }

    /**
     * Applies the completed-in-period-and-scope filters — usable on the Dispensation
     * query itself and on a `whereHas('dispensation', …)` subquery.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scopeDispensations(Builder $query, Period $period): Builder
    {
        [$start, $end] = $period->bounds();

        return $this->scopeByLocation($query)
            ->where('status', DispensationStatus::COMPLETED->value)
            ->where('dispensed_at', '>=', $start)->where('dispensed_at', '<', $end);
    }

    /**
     * @return Builder<Order>
     */
    private function orders(?Period $period = null): Builder
    {
        [$start, $end] = ($period ?? $this->period)->bounds();

        return $this->scopeByLocation(Order::query()->withoutGlobalScopes())
            ->where('status', OrderStatus::COMPLETED->value)
            ->where('created_at', '>=', $start)->where('created_at', '<', $end);
    }

    /**
     * @return Collection<int, Location>
     */
    private function scopeLocations(): Collection
    {
        return Location::query()->withoutGlobalScopes()
            ->whereIn('id', $this->resolvedLocationIds())->get();
    }

    /**
     * Gross float (Σ positive balances) and gross debt (Σ |negative balances|) across
     * the scoped locations, from the append-only ledger. A single grouped aggregate.
     *
     * @return array{float: int, debt: int}
     */
    private function walletBalances(): array
    {
        $balances = DB::table('wallet_transactions')
            ->whereIn('location_id', $this->resolvedLocationIds())
            ->groupBy('member_id', 'location_id')
            ->selectRaw('SUM(amount_cents) as bal')
            ->pluck('bal');

        $float = 0;
        $debt = 0;
        foreach ($balances as $bal) {
            $bal = (int) $bal;
            $bal >= 0 ? $float += $bal : $debt += -$bal;
        }

        return ['float' => $float, 'debt' => $debt];
    }
}
