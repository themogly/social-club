<?php

namespace App\Filament\Pages;

use App\Enums\DashboardAlert;
use App\Enums\Role;
use App\Filament\Pages\Reports\FinancialReportPage;
use App\Filament\Pages\Reports\LossesReportPage;
use App\Filament\Pages\Reports\StockReportPage;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Genetics\GeneticResource;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\TillSessions\TillSessionResource;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\TillSession;
use App\Models\User;
use App\Support\Money;
use App\Support\Period;
use App\Support\Weight;
use App\ViewModels\Dashboard as DashboardData;
use App\ViewModels\DashboardCharts;
use App\ViewModels\Reports\LossesReport;
use App\ViewModels\StaffHours;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * The club's home. THE screen that decides whether the product looks finished:
 * one period control drives every figure, cards carry a delta + sparkline, a right
 * rail groups the readouts + alerts, and the whole thing is role-aware (OWNER sees
 * the org rollup + per-location comparison; MANAGER their location; STAFF a small
 * operational board with no finance figures). Every number is a LIVE aggregate from
 * `App\ViewModels\Dashboard` / `DashboardCharts` — transactional data is never cached
 * — and money/weight are formatted only here at the display edge.
 */
class Dashboard extends BaseDashboard
{
    protected string $view = 'filament.pages.dashboard';

    /** today | week | month | custom — the single control every widget reads. */
    public string $period = 'today';

    public ?string $customStart = null;

    public ?string $customEnd = null;

    public static function getNavigationLabel(): string
    {
        return __('Panel');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Resumen');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Panel de control');
    }

    public function getHeading(): string|Htmlable
    {
        return __('Panel de control');
    }

    /**
     * Prompt 247 — the home screen is where a manager reaches the two things they add most: a NEW strain (the
     * one-flow wizard — sellable when you finish) and MORE STOCK of an existing one (238's batch intake). Both
     * were buried in the Existencias resources; a club owner opening the panel had no button for either. Each is
     * gated by the same policy its create page is (`genetics.manage` / `stock.manage`), so STAFF see neither.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var ?User $user */
        $user = Auth::user();

        return [
            Action::make('addStrain')
                ->label(__('Añadir variedad'))
                ->icon(Heroicon::OutlinedSparkles)
                ->url(GeneticResource::getUrl('create'))
                ->visible(fn (): bool => (bool) $user?->can('create', Genetic::class)),

            Action::make('addStock')
                ->label(__('Añadir stock'))
                ->icon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->url(BatchResource::getUrl('create'))
                ->visible(fn (): bool => (bool) $user?->can('create', Batch::class)),
        ];
    }

    /**
     * The custom Blade view owns the layout (period toggle + two-column body + right
     * rail), so the default widget grid is disabled — charts are embedded deliberately.
     *
     * @return array<int, mixed>
     */
    public function getWidgets(): array
    {
        return [];
    }

    public function resolvePeriod(): Period
    {
        if ($this->period === 'custom' && filled($this->customStart) && filled($this->customEnd)) {
            return Period::custom(
                CarbonImmutable::parse($this->customStart),
                CarbonImmutable::parse($this->customEnd),
                $this->periodLocation(),
            );
        }

        // The dashboard's "Hoy/semana/mes" resolves through the scoped sede's BUSINESS day (prompt 105).
        return Period::fromKey($this->period, $this->periodLocation());
    }

    /** The sede whose business-day config resolves the period — active sede, else the org's canonical (first) sede. */
    protected function periodLocation(): ?Location
    {
        return Period::sedeInScope(); // one rule (prompt 271): the active sede, else the organisation's canonical one
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $period = $this->resolvePeriod();
        $data = DashboardData::for($user, $period);
        $charts = DashboardCharts::for($user, $period);
        $canSeeFinance = $data->canSeeFinance;
        $role = $this->roleFor($user);
        // Prompt 285 — staff hours, for holders of staff.hours.view (never STAFF): absent, not empty, without it.
        $staffHours = StaffHours::visibleOnDashboard($user) ? StaffHours::for($user, $period) : null;
        $losses = $this->lossesYesterday($user, $data);

        return [
            'period' => $period,
            'periodKey' => $this->period,
            'customStart' => $this->customStart,
            'customEnd' => $this->customEnd,
            'role' => $role,
            'canSeeFinance' => $canSeeFinance,
            'isRollup' => $data->isRollup,
            'data' => $data,
            'occupancy' => $occ = $charts->occupancy(),
            'stats' => $this->statCards($data, $charts, $period, $canSeeFinance),
            'alerts' => $this->decorateAlerts(array_merge($data->alerts(), $this->staffAlerts($staffHours), $this->lossAlerts($losses, $data), $this->tillCloseAlerts($user, $data))),
            'staffHours' => $staffHours,
            'staffNow' => $staffHours?->now() ?? [],
            'ceilingHeadroom' => $data->ceilingHeadroom(),
            'readouts' => $this->readouts($data, $period, $occ, $canSeeFinance, $losses),
            'comparisonRows' => $this->comparisonRows($charts->perLocationComparison($period), $canSeeFinance),
            'topDispensedRows' => $this->topDispensedRows($charts->dispensedByGenetic(6, $period), $canSeeFinance),
            'recentRows' => $this->recentRows($charts->recentTransactions(8, $period), $canSeeFinance),
            'footfall' => $charts->footfallHeatmap($period),
        ];
    }

    /**
     * The genetic + tx + grams columns are operational; the contribution total is a
     * finance column and is dropped for STAFF (who never see money figures).
     *
     * @param  list<array{genetic: string, tx: int, grams_cg: int, total_cents: int}>  $rows
     * @return list<list<string>>
     */
    private function topDispensedRows(array $rows, bool $finance): array
    {
        return array_map(function (array $r) use ($finance): array {
            $cells = [$r['genetic'], (string) $r['tx'], Weight::fromCentigrams($r['grams_cg'])->formatted()];
            if ($finance) {
                $cells[] = Money::fromCents($r['total_cents'])->formatted();
            }

            return $cells;
        }, $rows);
    }

    /**
     * The amount is a finance column, dropped for STAFF.
     *
     * @param  list<array{type: string, ref: string, member: ?string, operator: ?string, amount_cents: int, at: string}>  $rows
     * @return list<list<string|HtmlString>>
     */
    private function recentRows(array $rows, bool $finance): array
    {
        return array_map(function (array $r) use ($finance): array {
            $cells = [
                new HtmlString('<span class="csc-tag csc-tag-'.e($r['type']).'">'.e($r['ref']).'</span>'),
                $r['member'] ?? '—',
                $r['operator'] ?? '—',
            ];
            if ($finance) {
                $cells[] = Money::fromCents($r['amount_cents'])->formatted();
            }

            return $cells;
        }, $rows);
    }

    /**
     * @param  list<array{location: string, contributions_cents: int, grams_cg: int, inside: int}>  $rows
     * @return list<list<string>>
     */
    private function comparisonRows(array $rows, bool $finance): array
    {
        return array_map(fn (array $r): array => [
            $r['location'],
            $finance ? Money::fromCents($r['contributions_cents'])->formatted() : '·',
            Weight::fromCentigrams($r['grams_cg'])->formatted(),
            (string) $r['inside'],
        ], $rows);
    }

    /**
     * The registro de jornada's loose ends (prompt 285), over the last 31 days whatever the period — an alert must not
     * vanish because the owner switched to "Hoy". Panel only: the counter hub never carries staff hours.
     *
     * @return list<array{severity: string, key: string, count: int}>
     */
    private function staffAlerts(?StaffHours $hours): array
    {
        if ($hours === null) {
            return [];
        }

        $alerts = [];
        foreach ([DashboardAlert::STAFF_OPEN_SHIFTS->value => $hours->openShiftsCount(), DashboardAlert::STAFF_UNCLOCKED_ACTIVITY->value => $hours->unclockedDaysCount()] as $key => $count) {
            if ($count > 0) {
                $alerts[] = ['severity' => DashboardAlert::from($key)->severity(), 'key' => $key, 'count' => $count];
            }
        }

        return $alerts;
    }

    /**
     * Prompt 367 — yesterday's losses at the sedes this dashboard shows, ONE report split per sede (the readout and the alert
     * both read it), for holders of reports.view / reports.view.all only; null for anyone else.
     */
    private function lossesYesterday(User $user, DashboardData $data): ?LossesReport
    {
        // Prompt 375 — the last 7 days, which hold yesterday: ONE report answers the readout, the sede alert (yesterday, read
        // out of it) and the per-person alert (the 7 days), inside the dashboard's query budget.
        return $user->canAny(['reports.view', 'reports.view.all'])
            ? new LossesReport($data->organisationId, $this->dashboardSedes($data)->pluck('id')->values()->all(), LossesReport::lastSevenDays())
            : null;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} yesterday's business day (the sede in scope's day, 271) */
    private function yesterday(): array
    {
        return Period::today()->previous()->bounds();
    }

    /**
     * Prompt 367 (291's discount alert, extended to every loss) — how many of those sedes went over their threshold % of the
     * day's takings yesterday.
     *
     * @return list<array{severity: string, key: string, count: int}>
     */
    private function lossAlerts(?LossesReport $losses, DashboardData $data): array
    {
        if ($losses === null) {
            return [];
        }
        $alerts = [];
        $count = count($losses->sedesAboveThreshold($this->yesterday()));
        if ($count > 0) {
            $alerts[] = ['severity' => DashboardAlert::LOSSES_ABOVE_THRESHOLD->severity(), 'key' => DashboardAlert::LOSSES_ABOVE_THRESHOLD->value, 'count' => $count];
        }
        // Prompt 375 — and the people over the threshold of their own takings this week, at each sede shown.
        $people = (int) array_sum(array_column($losses->peopleAboveThresholdBySede(), 'count'));
        if ($people > 0) {
            $alerts[] = ['severity' => DashboardAlert::LOSSES_PEOPLE_ABOVE_THRESHOLD->severity(), 'key' => DashboardAlert::LOSSES_PEOPLE_ABOVE_THRESHOLD->value, 'count' => $people];
        }

        return $alerts;
    }

    /** @var Collection<int, Location>|null the sedes this render's dashboard shows (two rail sections read them) */
    private ?Collection $sedes = null;

    /** @return Collection<int, Location> the sedes this dashboard shows */
    private function dashboardSedes(DashboardData $data): Collection
    {
        return $this->sedes ??= Location::query()->withoutGlobalScopes()->where('organisation_id', $data->organisationId)->sedes()
            ->when($data->locationIds !== null, fn ($query) => $query->whereIn('id', (array) $data->locationIds))->get();
    }

    /**
     * Prompt 366 — «Cierres con diferencia sin explicar: N esta semana»: closes beyond the tolerance with no note, this
     * (business) week at each sede this dashboard shows, for holders of reports.view. Never the dashboard period.
     *
     * @return list<array{severity: string, key: string, count: int}>
     */
    private function tillCloseAlerts(User $user, DashboardData $data): array
    {
        if (! $user->can('reports.view')) {
            return [];
        }

        $count = $this->dashboardSedes($data)->sum(fn (Location $sede): int => TillSession::unexplainedClosesThisWeek($sede));

        return $count > 0
            ? [['severity' => DashboardAlert::TILL_CLOSES_UNEXPLAINED->severity(), 'key' => DashboardAlert::TILL_CLOSES_UNEXPLAINED->value, 'count' => (int) $count]]
            : [];
    }

    /**
     * Turn the view-model's terse alert tuples into rendered rows — a plain-language
     * Spanish sentence, an icon and a click-through to where the operator fixes it.
     *
     * @param  list<array{severity: string, key: string, count: int}>  $alerts
     * @return list<array{severity: string, key: string, count: int, message: string, href: string, icon: Heroicon}>
     */
    private function decorateAlerts(array $alerts): array
    {
        return array_map(function (array $alert): array {
            $count = $alert['count'];
            $case = DashboardAlert::tryFrom($alert['key']);

            // The DESTINATION comes from the enum, which is now the one map both dashboards read (prompt
            // 207) — this method used to carry a second copy, so an alert could be pointed at one resource
            // here and another on the counter. The SENTENCE stays here: this is the back office, and it says
            // "por vencer" to a manager reading a list where the counter says "vence pronto" to somebody
            // standing at a till. Two audiences, one set of destinations.
            [$message, $icon] = match ($alert['key']) {
                'members_over_limit' => [trans_choice(':count socio ha superado su límite mensual|:count socios han superado su límite mensual', $count, ['count' => $count]), Heroicon::OutlinedExclamationTriangle],
                'active_member_cap' => [trans_choice(':count socio activo · tope de socios alcanzado|:count socios activos · tope de socios alcanzado', $count, ['count' => $count]), Heroicon::OutlinedUserGroup],
                'unreconciled_till' => [trans_choice(':count caja abierta sin arquear|:count cajas abiertas sin arquear', $count, ['count' => $count]), Heroicon::OutlinedCalculator],
                'batches_expiring' => [trans_choice(':count lote caduca pronto|:count lotes caducan pronto', $count, ['count' => $count]), Heroicon::OutlinedClock],
                'stock_ceiling_exceeded' => [trans_choice(':count sede supera el techo de existencias|:count sedes superan el techo de existencias', $count, ['count' => $count]), Heroicon::OutlinedArchiveBox],
                'memberships_expiring' => [trans_choice(':count membresía por vencer|:count membresías por vencer', $count, ['count' => $count]), Heroicon::OutlinedClock],
                'pending_applications' => [trans_choice(':count solicitud pendiente de revisión|:count solicitudes pendientes de revisión', $count, ['count' => $count]), Heroicon::OutlinedInbox],
                'genetics_low_stock' => [trans_choice(':count variedad con stock bajo|:count variedades con stock bajo', $count, ['count' => $count]), Heroicon::OutlinedArchiveBoxXMark],
                'association_stock_ceiling' => [__('La asociación tiene más stock en total (sedes y almacén) que el techo orientativo'), Heroicon::OutlinedArchiveBox],
                'articles_low_stock' => [trans_choice(':count producto de barra y tienda con stock bajo|:count productos de barra y tienda con stock bajo', $count, ['count' => $count]), Heroicon::OutlinedShoppingBag],
                'staff_open_shifts', 'staff_unclocked_activity' => [(string) $case?->label($count), Heroicon::OutlinedClock],
                'till_closes_unexplained' => [(string) $case?->label($count), Heroicon::OutlinedCalculator],
                'losses_above_threshold', 'losses_people_above_threshold' => [(string) $case?->label($count), Heroicon::OutlinedArrowTrendingDown],
                default => [$case?->label($count) ?? __('Aviso'), Heroicon::OutlinedBell],
            };

            return ['severity' => $alert['severity'], 'key' => $alert['key'], 'count' => $count, 'message' => $message, 'href' => $case?->panelUrl() ?? '#', 'icon' => $icon];
        }, $alerts);
    }

    /**
     * The right-rail grouped readouts — secondary figures that don't warrant a card but
     * link through to their detail. Finanzas is present only when the actor sees finance.
     *
     * @param  array{inside: int, capacity: ?int, fraction: ?float}  $occ
     * @return array<string, array{title: string, rows: list<array{label: string, value: string, href: ?string}>}>
     */
    private function readouts(DashboardData $d, Period $period, array $occ, bool $finance, ?LossesReport $losses = null): array
    {
        $days = $d->daysOfInventory();
        $groups = [];

        if ($finance) {
            $groups['finanzas'] = ['title' => __('Finanzas'), 'rows' => [
                ['label' => __('Aportaciones'), 'value' => Money::fromCents($d->contributionsCents($period))->formatted(), 'href' => FinancialReportPage::getUrl()],
                ['label' => __('Saldo de monedero'), 'value' => Money::fromCents($d->walletFloatCents())->formatted(), 'href' => FinancialReportPage::getUrl()],
                ['label' => __('Deuda de socios'), 'value' => Money::fromCents($d->walletDebtCents())->formatted(), 'href' => FinancialReportPage::getUrl()],
                ['label' => __('Valor del stock'), 'value' => Money::fromCents($d->stockValueCents())->formatted(), 'href' => StockReportPage::getUrl()],
            ]];
            // Prompt 367 — «Pérdidas ayer: €46.20 (3.1 %)», opening Pérdidas on that day; for those who may open it.
            if ($losses !== null) {
                $yesterday = $losses->dayFigures($this->yesterday());
                $groups['finanzas']['rows'][] = ['label' => __('Pérdidas ayer'), 'value' => Money::fromCents($yesterday['total'])->formatted().' ('.$yesterday['pct'].' %)',
                    'href' => LossesReportPage::getUrl(['period' => 'yesterday']), 'data' => 'losses-yesterday'];
            }
        }

        $groups['socios'] = ['title' => __('Socios'), 'rows' => [
            ['label' => __('Activos'), 'value' => (string) $d->activeMembers(), 'href' => MemberResource::getUrl()],
            ['label' => __('Dentro ahora'), 'value' => (string) $occ['inside'], 'href' => '#'],
            ['label' => __('Nuevos este mes'), 'value' => (string) $d->newMembersThisMonth(), 'href' => MemberResource::getUrl()],
            ['label' => __('Solicitudes pendientes'), 'value' => (string) $d->pendingApplications(), 'href' => '#'],
        ]];

        $groups['operacion'] = ['title' => __('Dispensario y barra'), 'rows' => [
            ['label' => __('Dispensado'), 'value' => Weight::fromCentigrams($d->gramsDispensedCg($period))->formatted(), 'href' => '#'],
            ['label' => __('Transacciones'), 'value' => (string) $d->transactionCount($period), 'href' => '#'],
            ['label' => __('Stock en mano'), 'value' => Weight::fromCentigrams($d->stockOnHandCg())->formatted(), 'href' => '#'],
            ['label' => __('Días de inventario'), 'value' => $days !== null ? (string) $days : '—', 'href' => '#'],
        ]];

        return $groups;
    }

    private function roleFor(User $user): string
    {
        return match (true) {
            $user->hasRole(Role::OWNER->value) => 'owner',
            $user->hasRole(Role::MANAGER->value) => 'manager',
            default => 'staff',
        };
    }

    /**
     * The 8 stat cards, assembled from the two view-models and filtered by role: the
     * four finance cards (aportaciones, valor del stock, saldo de socios, caja) are
     * omitted entirely when the actor may not see finance, so a STAFF board is purely
     * operational (who's inside, dispensado, transacciones, socios).
     *
     * @return list<array<string, mixed>>
     */
    private function statCards(DashboardData $d, DashboardCharts $c, Period $period, bool $finance): array
    {
        $prev = $period->previous();
        $occ = $c->occupancy();
        $split = $d->contributionSplit($period);
        $cards = [];

        if ($finance) {
            $cards[] = [
                'key' => 'aportaciones',
                'label' => __('Aportaciones'),
                'icon' => Heroicon::OutlinedBanknotes,
                'value' => Money::fromCents($d->contributionsCents($period))->formatted(),
                'sub' => __('Efectivo :cash · Monedero :wallet', [
                    'cash' => Money::fromCents($split['cash'])->formatted(),
                    'wallet' => Money::fromCents($split['wallet'])->formatted(),
                ]),
                'delta' => $this->delta($d->contributionsCents($period), $d->contributionsCents($prev), true),
                'spark' => $c->contributionsSeries(),
                'href' => FinancialReportPage::getUrl(),
                'finance' => true,
            ];
        }

        $cards[] = [
            'key' => 'dispensado',
            'label' => __('Dispensado'),
            'icon' => Heroicon::OutlinedScale,
            'value' => Weight::fromCentigrams($d->gramsDispensedCg($period))->formatted(),
            'sub' => __('Este mes: :g', ['g' => Weight::fromCentigrams($d->gramsDispensedCg(Period::thisMonth()))->formatted()]),
            'delta' => $this->delta($d->gramsDispensedCg($period), $d->gramsDispensedCg($prev), true),
            'spark' => $c->gramsSeries(),
            'href' => '#',
            'finance' => false,
        ];

        $cards[] = [
            'key' => 'inside',
            'label' => __('Socios dentro'),
            'icon' => Heroicon::OutlinedUsers,
            'value' => (string) $occ['inside'],
            'sub' => $occ['capacity'] !== null
                ? __('Aforo :cap', ['cap' => $occ['capacity']])
                : __('Sin aforo definido'),
            'ring' => $occ,
            'href' => '#',
            'finance' => false,
        ];

        $cards[] = [
            'key' => 'transacciones',
            'label' => __('Transacciones'),
            'icon' => Heroicon::OutlinedReceiptPercent,
            'value' => (string) $d->transactionCount($period),
            'sub' => $finance
                ? __('Media :v', ['v' => Money::fromCents($d->averageContributionCents($period))->formatted()])
                : null,
            'delta' => $this->delta($d->transactionCount($period), $d->transactionCount($prev), true),
            'spark' => $c->transactionsSeries(),
            'href' => '#',
            'finance' => false,
        ];

        $cards[] = [
            'key' => 'socios_activos',
            'label' => __('Socios activos'),
            'icon' => Heroicon::OutlinedUserGroup,
            'value' => (string) $d->activeMembers(),
            'sub' => __('+:n nuevos este mes', ['n' => $d->newMembersThisMonth()]),
            'spark' => $c->newMembersSeries(),
            'href' => MemberResource::getUrl(),
            'finance' => false,
        ];

        if ($finance) {
            $days = $d->daysOfInventory();
            $cards[] = [
                'key' => 'stock_value',
                'label' => __('Valor del stock'),
                'icon' => Heroicon::OutlinedArchiveBox,
                'value' => Money::fromCents($d->stockValueCents())->formatted(),
                'sub' => $days !== null
                    ? __(':n días de inventario', ['n' => $days])
                    : __('Sin rotación reciente'),
                'href' => StockReportPage::getUrl(),
                'finance' => true,
            ];

            $cards[] = [
                'key' => 'wallet',
                'label' => __('Saldo de socios'),
                'icon' => Heroicon::OutlinedWallet,
                'value' => Money::fromCents($d->walletFloatCents())->formatted(),
                'sub' => __('Pasivo · Deuda :d', ['d' => Money::fromCents($d->walletDebtCents())->formatted()]),
                'tone' => 'liability',
                'href' => FinancialReportPage::getUrl(),
                'finance' => true,
            ];

            $variance = $d->lastSessionVarianceCents();
            $cards[] = [
                'key' => 'caja',
                'label' => __('Caja'),
                'icon' => Heroicon::OutlinedCalculator,
                'value' => $variance !== null ? Money::fromCents($variance)->formatted() : '—',
                'sub' => $d->hasUnreconciledTill()
                    ? __('Caja abierta sin arquear')
                    : __('Último descuadre'),
                'spark' => $c->varianceSeries(),
                'flag' => $d->hasUnreconciledTill(),
                'href' => TillSessionResource::getUrl(),
                'finance' => true,
            ];
        }

        return $cards;
    }

    /**
     * Direction + magnitude + a colour TONE for a period-over-period delta. Colour never
     * travels alone — the card always pairs it with an arrow icon and the number.
     *
     * @return array{dir: string, pct: int, tone: string, label: string}
     */
    private function delta(int $current, int $previous, bool $positiveIsGood): array
    {
        $diff = $current - $previous;
        $dir = $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat');
        $pct = $previous !== 0
            ? (int) round(abs($diff) / abs($previous) * 100)
            : ($current !== 0 ? 100 : 0);
        $tone = match ($dir) {
            'up' => $positiveIsGood ? 'success' : 'error',
            'down' => $positiveIsGood ? 'error' : 'success',
            default => 'muted',
        };
        $sign = $dir === 'up' ? '+' : ($dir === 'down' ? '−' : '');

        return ['dir' => $dir, 'pct' => $pct, 'tone' => $tone, 'label' => $sign.$pct.'%'];
    }
}
