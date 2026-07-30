<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\TillSessions\TillSessionResource;
use App\Models\User;
use App\Support\Money;
use App\Support\Period;
use App\Support\Weight;
use App\ViewModels\Dashboard as DashboardData;
use App\ViewModels\DashboardCharts;
use Carbon\CarbonImmutable;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
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
            );
        }

        return Period::fromKey($this->period);
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
            'alerts' => $this->decorateAlerts($data->alerts()),
            'readouts' => $this->readouts($data, $period, $occ, $canSeeFinance),
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
     * Turn the view-model's terse alert tuples into rendered rows — a plain-language
     * Spanish sentence, an icon and a click-through to where the operator fixes it.
     *
     * @param  list<array{severity: string, key: string, count: int}>  $alerts
     * @return list<array{severity: string, count: int, message: string, href: string, icon: Heroicon}>
     */
    private function decorateAlerts(array $alerts): array
    {
        return array_map(function (array $alert): array {
            $count = $alert['count'];
            [$message, $href, $icon] = match ($alert['key']) {
                'members_over_limit' => [trans_choice(':count socio ha superado su límite mensual|:count socios han superado su límite mensual', $count, ['count' => $count]), MemberResource::getUrl(), Heroicon::OutlinedExclamationTriangle],
                'unreconciled_till' => [trans_choice(':count caja abierta sin arquear|:count cajas abiertas sin arquear', $count, ['count' => $count]), TillSessionResource::getUrl(), Heroicon::OutlinedCalculator],
                'batches_expiring' => [trans_choice(':count lote caduca pronto|:count lotes caducan pronto', $count, ['count' => $count]), BatchResource::getUrl(), Heroicon::OutlinedClock],
                'stock_ceiling_exceeded' => [trans_choice(':count sede supera el techo de existencias|:count sedes superan el techo de existencias', $count, ['count' => $count]), BatchResource::getUrl(), Heroicon::OutlinedArchiveBox],
                'memberships_expiring' => [trans_choice(':count membresía por vencer|:count membresías por vencer', $count, ['count' => $count]), MemberResource::getUrl(), Heroicon::OutlinedClock],
                'pending_applications' => [trans_choice(':count solicitud pendiente de revisión|:count solicitudes pendientes de revisión', $count, ['count' => $count]), MemberApplicationResource::getUrl(), Heroicon::OutlinedInbox],
                default => [__('Aviso'), '#', Heroicon::OutlinedBell],
            };

            return ['severity' => $alert['severity'], 'count' => $count, 'message' => $message, 'href' => $href, 'icon' => $icon];
        }, $alerts);
    }

    /**
     * The right-rail grouped readouts — secondary figures that don't warrant a card but
     * link through to their detail. Finanzas is present only when the actor sees finance.
     *
     * @param  array{inside: int, capacity: ?int, fraction: ?float}  $occ
     * @return array<string, array{title: string, rows: list<array{label: string, value: string, href: ?string}>}>
     */
    private function readouts(DashboardData $d, Period $period, array $occ, bool $finance): array
    {
        $days = $d->daysOfInventory();
        $groups = [];

        if ($finance) {
            $groups['finanzas'] = ['title' => __('Finanzas'), 'rows' => [
                ['label' => __('Aportaciones'), 'value' => Money::fromCents($d->contributionsCents($period))->formatted(), 'href' => '#'],
                ['label' => __('Saldo de monedero'), 'value' => Money::fromCents($d->walletFloatCents())->formatted(), 'href' => '#'],
                ['label' => __('Deuda de socios'), 'value' => Money::fromCents($d->walletDebtCents())->formatted(), 'href' => '#'],
                ['label' => __('Valor del stock'), 'value' => Money::fromCents($d->stockValueCents())->formatted(), 'href' => '#'],
            ]];
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
                'href' => '#',
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
                'href' => '#',
                'finance' => true,
            ];

            $cards[] = [
                'key' => 'wallet',
                'label' => __('Saldo de socios'),
                'icon' => Heroicon::OutlinedWallet,
                'value' => Money::fromCents($d->walletFloatCents())->formatted(),
                'sub' => __('Pasivo · Deuda :d', ['d' => Money::fromCents($d->walletDebtCents())->formatted()]),
                'tone' => 'liability',
                'href' => '#',
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
