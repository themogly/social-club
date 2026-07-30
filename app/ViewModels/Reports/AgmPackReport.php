<?php

namespace App\ViewModels\Reports;

use App\Enums\DispensationStatus;
use App\Enums\MemberStatus;
use App\Enums\OrderStatus;
use App\Support\ConsumptionForecast;
use App\Support\Money;
use App\Support\Weight;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Comité / Asamblea — the owner's assembly pack. Ingresos, gastos and superávit per sede
 * with the org total, members by status, where the money went by category, plus the
 * aggregate declared forecast (the authorised cultivation volume). Always org-wide; the
 * page gates it on reports.view.all so a manager cannot open another club's pack.
 */
class AgmPackReport extends AbstractReport
{
    /** @var array<string, int> */
    private array $figures = [];

    public function key(): string
    {
        return 'comite';
    }

    public function title(): string
    {
        return __('Dossier para la asamblea');
    }

    protected function build(): array
    {
        return [
            $this->byLocation(),
            $this->membersByStatus(),
            $this->expensesByCategory(),
        ];
    }

    public function summary(): array
    {
        $this->tables();
        $surplus = ($this->figures['income'] ?? 0) - ($this->figures['expenses'] ?? 0);

        return [
            ['label' => __('Ingresos totales'), 'value' => Money::fromCents($this->figures['income'] ?? 0)->formatted()],
            ['label' => __('Gastos'), 'value' => Money::fromCents($this->figures['expenses'] ?? 0)->formatted()],
            ['label' => __('Superávit'), 'value' => Money::fromCents($surplus)->formatted(), 'tone' => $surplus >= 0 ? 'success' : 'error'],
            ['label' => __('Socios activos'), 'value' => (string) ($this->figures['active_members'] ?? 0)],
            ['label' => __('Previsión declarada'), 'value' => Weight::fromCentigrams(ConsumptionForecast::authorisedVolumeCg($this->organisationId))->formatted()],
        ];
    }

    // --- Income / expense / surplus by sede -----------------------------------------

    private function byLocation(): ReportTable
    {
        $ids = $this->resolvedLocationIds();
        [$start, $end] = $this->bounds();

        $disp = DB::table('dispensations')->whereIn('location_id', $ids)
            ->where('status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensed_at', [$start, $end])
            ->groupBy('location_id')->pluck(DB::raw('SUM(total_cents)'), 'location_id');
        $ord = DB::table('orders')->whereIn('location_id', $ids)
            ->where('status', OrderStatus::COMPLETED->value)
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('location_id')->pluck(DB::raw('SUM(total_cents)'), 'location_id');
        $fees = DB::table('membership_fee_payments')
            ->join('memberships', 'membership_fee_payments.membership_id', '=', 'memberships.id')
            ->whereIn('memberships.location_id', $ids)
            ->whereBetween('membership_fee_payments.paid_at', [$start, $end])
            ->groupBy('memberships.location_id')->pluck(DB::raw('SUM(membership_fee_payments.amount_cents)'), 'memberships.location_id');
        $exp = $this->expensesInPeriod()->whereIn('location_id', $ids)
            ->groupBy('location_id')->pluck(DB::raw('SUM(amount_cents)'), 'location_id');
        $overheads = (int) $this->expensesInPeriod()->whereNull('location_id')->sum('amount_cents');

        $rows = [];
        $totals = ['aportaciones' => 0, 'barra' => 0, 'cuotas' => 0, 'gastos' => 0, 'superavit' => 0];
        foreach ($this->scopedLocations() as $location) {
            $ap = (int) ($disp[$location->id] ?? 0);
            $ba = (int) ($ord[$location->id] ?? 0);
            $cu = (int) ($fees[$location->id] ?? 0);
            $ga = (int) ($exp[$location->id] ?? 0);
            $rows[] = $this->locationRow($location->name, $ap, $ba, $cu, $ga, $totals);
        }

        if ($overheads !== 0) {
            $rows[] = $this->locationRow(__('General (organización)'), 0, 0, 0, $overheads, $totals);
        }

        $this->figures['income'] = $totals['aportaciones'] + $totals['barra'] + $totals['cuotas'];
        $this->figures['expenses'] = $totals['gastos'];

        return new ReportTable(
            key: 'by_location',
            title: __('Ingresos y gastos por sede'),
            columns: [
                ReportColumn::text('sede', __('Sede')),
                ReportColumn::money('aportaciones', __('Aportaciones')),
                ReportColumn::money('barra', __('Barra')),
                ReportColumn::money('cuotas', __('Cuotas')),
                ReportColumn::money('gastos', __('Gastos')),
                ReportColumn::money('superavit', __('Superávit')),
            ],
            rows: $rows,
            totals: $totals,
            empty: __('Sin actividad en este período'),
            emptyHint: __('El dossier se completa a partir de la actividad registrada en las sedes.'),
            defaultSort: 'superavit',
            sortable: true,
        );
    }

    /**
     * @param  array<string, int>  $totals
     * @return array<string, int|string>
     */
    private function locationRow(string $name, int $ap, int $ba, int $cu, int $ga, array &$totals): array
    {
        $superavit = $ap + $ba + $cu - $ga;
        $totals['aportaciones'] += $ap;
        $totals['barra'] += $ba;
        $totals['cuotas'] += $cu;
        $totals['gastos'] += $ga;
        $totals['superavit'] += $superavit;

        return ['sede' => $name, 'aportaciones' => $ap, 'barra' => $ba, 'cuotas' => $cu, 'gastos' => $ga, 'superavit' => $superavit];
    }

    // --- Members by status ----------------------------------------------------------

    private function membersByStatus(): ReportTable
    {
        $counts = DB::table('members')
            ->where('organisation_id', $this->organisationId)
            ->whereNull('deleted_at')
            ->groupBy('status')->pluck(DB::raw('COUNT(*)'), 'status');

        $this->figures['active_members'] = (int) ($counts[MemberStatus::ACTIVE->value] ?? 0);

        $labels = [
            MemberStatus::APPLICANT->value => __('Solicitante'),
            MemberStatus::ACTIVE->value => __('Activo'),
            MemberStatus::INACTIVE->value => __('Inactivo'),
            MemberStatus::EXPIRED->value => __('Caducado'),
            MemberStatus::SUSPENDED->value => __('Suspendido'),
            MemberStatus::EXPELLED->value => __('Expulsado'),
        ];

        $rows = [];
        foreach ($labels as $value => $label) {
            $n = (int) ($counts[$value] ?? 0);
            if ($n > 0) {
                $rows[] = ['estado' => $label, 'socios' => $n];
            }
        }

        return new ReportTable(
            key: 'members',
            title: __('Socios por estado'),
            columns: [
                ReportColumn::text('estado', __('Estado'), sortable: false),
                ReportColumn::number('socios', __('Socios')),
            ],
            rows: $rows,
            totals: ['socios' => array_sum(array_column($rows, 'socios'))],
            empty: __('Sin socios'),
        );
    }

    // --- Expenses by category -------------------------------------------------------

    private function expensesByCategory(): ReportTable
    {
        $rows = $this->expensesInPeriod()
            ->join('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
            ->groupBy('expense_categories.id')
            ->orderByDesc(DB::raw('SUM(expenses.amount_cents)'))
            ->get([
                DB::raw('MAX(expense_categories.name) as categoria'),
                DB::raw('SUM(expenses.amount_cents) as importe'),
            ])
            ->map(fn (\stdClass $r): array => ['categoria' => (string) $r->categoria, 'importe' => (int) $r->importe])
            ->all();

        return new ReportTable(
            key: 'expenses',
            title: __('Gastos por categoría'),
            columns: [
                ReportColumn::text('categoria', __('Categoría'), sortable: false),
                ReportColumn::money('importe', __('Gastos')),
            ],
            rows: $rows,
            totals: ['importe' => array_sum(array_column($rows, 'importe'))],
            empty: __('Sin gastos en este período'),
        );
    }

    /**
     * Concrete org expenses whose incurred date falls in the period (all sedes + org-wide).
     */
    private function expensesInPeriod(): QueryBuilder
    {
        [$start, $end] = $this->bounds();

        return DB::table('expenses')
            ->where('expenses.organisation_id', $this->organisationId)
            ->whereNull('expenses.deleted_at')
            ->whereNull('expenses.recurrence')
            ->where('incurred_on', '>=', $start->toDateString())
            ->where('incurred_on', '<', $end->toDateString());
    }
}
