<?php

namespace App\ViewModels;

use App\Enums\ApplicationStatus;
use App\Enums\BatchStatus;
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
            ->whereBetween('joined_at', [$start, $end])->count();
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

        if (($n = $this->membersOverLimit()) > 0) {
            $alerts[] = ['severity' => 'warning', 'key' => 'members_over_limit', 'count' => $n];
        }
        if (($n = $this->membersOverCap()) > 0) {
            $alerts[] = ['severity' => 'warning', 'key' => 'active_member_cap', 'count' => $n];
        }
        if ($this->hasUnreconciledTill()) {
            $n = $this->scopeByLocation(TillSession::query()->withoutGlobalScopes())
                ->where('status', TillSessionStatus::OPEN->value)->count();
            $alerts[] = ['severity' => 'warning', 'key' => 'unreconciled_till', 'count' => $n];
        }
        if (($n = $this->expiringBatches()) > 0) {
            $alerts[] = ['severity' => 'warning', 'key' => 'batches_expiring', 'count' => $n];
        }
        if (($n = count($this->ceilingBreaches())) > 0) {
            $alerts[] = ['severity' => 'error', 'key' => 'stock_ceiling_exceeded', 'count' => $n];
        }
        if (($n = $this->expiringMemberships()) > 0) {
            $alerts[] = ['severity' => 'info', 'key' => 'memberships_expiring', 'count' => $n];
        }
        if (($n = $this->pendingApplications()) > 0) {
            $alerts[] = ['severity' => 'info', 'key' => 'pending_applications', 'count' => $n];
        }

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

        return Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->organisationId)
            ->whereNotNull('monthly_limit_cg')->where('monthly_limit_cg', '>', 0)
            ->get(['id', 'monthly_limit_cg'])
            ->filter(function (Member $member) use ($start, $end): bool {
                $used = (int) DispensationLine::query()->withoutGlobalScopes()
                    ->whereHas('dispensation', fn (Builder $q) => $q
                        ->where('member_id', $member->id)
                        ->where('status', DispensationStatus::COMPLETED->value)
                        ->whereBetween('dispensed_at', [$start, $end]))
                    ->sum('grams_cg');

                return $used >= (int) $member->monthly_limit_cg;
            })->count();
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

    public function expiringMemberships(): int
    {
        return $this->scopeByLocation(Membership::query()->withoutGlobalScopes())
            ->where('status', MembershipStatus::ACTIVE->value)
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays(30)])
            ->count();
    }

    public function pendingApplications(): int
    {
        return $this->scopeByLocation(MemberApplication::query()->withoutGlobalScopes())
            ->where('status', ApplicationStatus::PENDING->value)->count();
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
            ->whereBetween('dispensed_at', [$start, $end]);
    }

    /**
     * @return Builder<Order>
     */
    private function orders(?Period $period = null): Builder
    {
        [$start, $end] = ($period ?? $this->period)->bounds();

        return $this->scopeByLocation(Order::query()->withoutGlobalScopes())
            ->where('status', OrderStatus::COMPLETED->value)
            ->whereBetween('created_at', [$start, $end]);
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
