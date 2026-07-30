<?php

namespace App\ViewModels\Reports;

use App\Enums\DispensationStatus;
use App\Enums\FeePaymentMethod;
use App\Enums\OrderStatus;
use App\Support\Money;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Financiero — the non-profit money story. Takings by day split into the three income
 * types that must NEVER merge (Aportaciones / Barra / Cuotas), the payment-method mix,
 * where the money goes by category, the superávit (cost recovery — never *beneficio*),
 * supplier balances owing, and a purchase-vs-withdrawal reconciliation (what came in as
 * batch intake against what was dispensed). Every figure is integer cents/centigrams.
 */
class FinancialReport extends AbstractReport
{
    /** @var array<string, int> grand figures cached during build() for the summary strip */
    private array $figures = [];

    public function key(): string
    {
        return 'financiero';
    }

    public function title(): string
    {
        return __('Informe financiero');
    }

    protected function build(): array
    {
        return [
            $this->takingsByDay(),
            $this->byPaymentMethod(),
            $this->whereMoneyGoes(),
            $this->supplierBalances(),
            $this->reconciliation(),
        ];
    }

    public function summary(): array
    {
        $this->tables(); // ensure figures are populated

        $income = $this->figures['income'] ?? 0;
        $expenses = $this->figures['expenses'] ?? 0;
        $surplus = $income - $expenses;

        return [
            ['label' => __('Ingresos totales'), 'value' => Money::fromCents($income)->formatted()],
            ['label' => __('Gastos'), 'value' => Money::fromCents($expenses)->formatted()],
            ['label' => __('Superávit'), 'value' => Money::fromCents($surplus)->formatted(), 'tone' => $surplus >= 0 ? 'success' : 'error'],
            ['label' => __('Deuda a proveedores'), 'value' => Money::fromCents($this->figures['owed'] ?? 0)->formatted()],
        ];
    }

    // --- Primary: takings by day ----------------------------------------------------

    private function takingsByDay(): ReportTable
    {
        ['keys' => $keys, 'labels' => $labels, 'bounds' => $bounds] = $this->dayBuckets();
        $ids = $this->resolvedLocationIds();
        [$start, $end] = $this->bounds();

        $disp = DB::table('dispensations')->whereIn('location_id', $ids)
            ->where('status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensed_at', [$start, $end])->get(['dispensed_at', 'total_cents']);
        $ord = DB::table('orders')->whereIn('location_id', $ids)
            ->where('status', OrderStatus::COMPLETED->value)
            ->whereBetween('created_at', [$start, $end])->get(['created_at', 'total_cents']);
        $fees = DB::table('membership_fee_payments')
            ->join('memberships', 'membership_fee_payments.membership_id', '=', 'memberships.id')
            ->whereIn('memberships.location_id', $ids)
            ->whereBetween('membership_fee_payments.paid_at', [$start, $end])
            ->get(['membership_fee_payments.paid_at as paid_at', 'membership_fee_payments.amount_cents as amount_cents']);
        $exp = $this->expensesQuery()
            ->where('incurred_on', '>=', $start->toDateString())
            ->where('incurred_on', '<', $end->toDateString())
            ->get(['incurred_on', 'amount_cents']);

        $aportaciones = $this->bucketSum($disp, $bounds, 'dispensed_at', 'total_cents');
        $barra = $this->bucketSum($ord, $bounds, 'created_at', 'total_cents');
        $cuotas = $this->bucketSum($fees, $bounds, 'paid_at', 'amount_cents');
        $gastos = $this->bucketSum($exp, $bounds, 'incurred_on', 'amount_cents');

        $rows = [];
        $totals = ['aportaciones' => 0, 'barra' => 0, 'cuotas' => 0, 'ingresos' => 0, 'gastos' => 0, 'superavit' => 0];
        foreach ($keys as $i => $key) {
            $ingresos = $aportaciones[$i] + $barra[$i] + $cuotas[$i];
            $superavit = $ingresos - $gastos[$i];
            $rows[] = [
                'dia' => $labels[$i], 'sort' => $key,
                'aportaciones' => $aportaciones[$i], 'barra' => $barra[$i], 'cuotas' => $cuotas[$i],
                'ingresos' => $ingresos, 'gastos' => $gastos[$i], 'superavit' => $superavit,
            ];
            $totals['aportaciones'] += $aportaciones[$i];
            $totals['barra'] += $barra[$i];
            $totals['cuotas'] += $cuotas[$i];
            $totals['ingresos'] += $ingresos;
            $totals['gastos'] += $gastos[$i];
            $totals['superavit'] += $superavit;
        }

        $this->figures['income'] = $totals['ingresos'];
        $this->figures['expenses'] = $totals['gastos'];

        return new ReportTable(
            key: 'takings',
            title: __('Recaudación por día'),
            columns: [
                ReportColumn::text('dia', __('Fecha')),
                ReportColumn::money('aportaciones', __('Aportaciones')),
                ReportColumn::money('barra', __('Barra')),
                ReportColumn::money('cuotas', __('Cuotas')),
                ReportColumn::money('ingresos', __('Ingresos')),
                ReportColumn::money('gastos', __('Gastos')),
                ReportColumn::money('superavit', __('Superávit')),
            ],
            rows: $rows,
            totals: $totals,
            empty: __('Sin movimientos en este período'),
            emptyHint: __('Cuando se registren aportaciones, barra, cuotas o gastos aparecerán aquí día a día.'),
            defaultSort: 'sort',
            defaultSortDir: 'asc',
            sortable: true,
        );
    }

    // --- Payment method mix ---------------------------------------------------------

    private function byPaymentMethod(): ReportTable
    {
        $ids = $this->resolvedLocationIds();
        [$start, $end] = $this->bounds();

        $dispCash = (int) DB::table('dispensations')->whereIn('location_id', $ids)
            ->where('status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensed_at', [$start, $end])->sum('cash_cents');
        $dispWallet = (int) DB::table('dispensations')->whereIn('location_id', $ids)
            ->where('status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensed_at', [$start, $end])->sum('wallet_cents');
        $ordCash = (int) DB::table('orders')->whereIn('location_id', $ids)
            ->where('status', OrderStatus::COMPLETED->value)
            ->whereBetween('created_at', [$start, $end])->sum('cash_cents');
        $ordWallet = (int) DB::table('orders')->whereIn('location_id', $ids)
            ->where('status', OrderStatus::COMPLETED->value)
            ->whereBetween('created_at', [$start, $end])->sum('wallet_cents');

        $feesByMethod = DB::table('membership_fee_payments')
            ->join('memberships', 'membership_fee_payments.membership_id', '=', 'memberships.id')
            ->whereIn('memberships.location_id', $ids)
            ->whereBetween('membership_fee_payments.paid_at', [$start, $end])
            ->groupBy('membership_fee_payments.method')
            ->pluck(DB::raw('SUM(membership_fee_payments.amount_cents)'), 'membership_fee_payments.method');

        $fee = fn (FeePaymentMethod $m): int => (int) ($feesByMethod[$m->value] ?? 0);

        $methods = [
            ['metodo' => __('Efectivo'), 'importe' => $dispCash + $ordCash + $fee(FeePaymentMethod::CASH)],
            ['metodo' => __('Monedero'), 'importe' => $dispWallet + $ordWallet + $fee(FeePaymentMethod::WALLET)],
            ['metodo' => __('Banco'), 'importe' => $fee(FeePaymentMethod::BANK)],
            ['metodo' => __('Tarjeta'), 'importe' => $fee(FeePaymentMethod::CARD)],
        ];

        $rows = array_values(array_filter($methods, fn (array $r): bool => $r['importe'] !== 0));
        $total = array_sum(array_column($methods, 'importe'));

        return new ReportTable(
            key: 'methods',
            title: __('Por método de pago'),
            columns: [
                ReportColumn::text('metodo', __('Método'), sortable: false),
                ReportColumn::money('importe', __('Importe')),
            ],
            rows: $rows,
            totals: ['importe' => $total],
            empty: __('Sin ingresos en este período'),
        );
    }

    // --- Where the money goes -------------------------------------------------------

    private function whereMoneyGoes(): ReportTable
    {
        [$start, $end] = $this->bounds();

        $rows = $this->expensesQuery()
            ->join('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
            ->where('incurred_on', '>=', $start->toDateString())
            ->where('incurred_on', '<', $end->toDateString())
            ->groupBy('expense_categories.id', 'expense_categories.name')
            ->orderByDesc(DB::raw('SUM(expenses.amount_cents)'))
            ->get([
                DB::raw('MAX(expense_categories.name) as categoria'),
                DB::raw('SUM(expenses.amount_cents) as importe'),
            ])
            ->map(fn (\stdClass $r): array => [
                'categoria' => (string) $r->categoria,
                'importe' => (int) $r->importe,
            ])->all();

        $total = array_sum(array_column($rows, 'importe'));

        return new ReportTable(
            key: 'expenses',
            title: __('A dónde va el dinero'),
            columns: [
                ReportColumn::text('categoria', __('Categoría'), sortable: false),
                ReportColumn::money('importe', __('Gastos')),
            ],
            rows: $rows,
            totals: ['importe' => $total],
            empty: __('Sin gastos registrados en este período'),
        );
    }

    // --- Supplier balances owing (a running snapshot, not period-bounded) -----------

    private function supplierBalances(): ReportTable
    {
        $rows = DB::table('purchases')
            ->join('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
            ->where('purchases.organisation_id', $this->organisationId)
            ->whereNull('purchases.deleted_at')
            ->where(fn (QueryBuilder $q) => $this->scopeExpenseLocation($q, 'purchases'))
            ->groupBy('suppliers.id', 'suppliers.name')
            ->get([
                DB::raw('MAX(suppliers.name) as proveedor'),
                DB::raw('SUM(purchases.amount_cents) as facturado'),
                DB::raw('SUM(purchases.paid_cents) as pagado'),
            ])
            ->map(fn (\stdClass $r): array => [
                'proveedor' => (string) $r->proveedor,
                'facturado' => (int) $r->facturado,
                'pagado' => (int) $r->pagado,
                'debe' => (int) $r->facturado - (int) $r->pagado,
            ])
            ->sortByDesc('debe')->values()->all();

        $totals = [
            'facturado' => array_sum(array_column($rows, 'facturado')),
            'pagado' => array_sum(array_column($rows, 'pagado')),
            'debe' => array_sum(array_column($rows, 'debe')),
        ];
        $this->figures['owed'] = $totals['debe'];

        return new ReportTable(
            key: 'suppliers',
            title: __('Saldos con proveedores'),
            columns: [
                ReportColumn::text('proveedor', __('Proveedor'), sortable: false),
                ReportColumn::money('facturado', __('Facturado')),
                ReportColumn::money('pagado', __('Pagado')),
                ReportColumn::money('debe', __('Debe')),
            ],
            rows: $rows,
            totals: $totals,
            empty: __('Sin compras a proveedores'),
            note: __('Saldo pendiente a fecha de hoy (no limitado al período).'),
        );
    }

    // --- Purchase vs withdrawal reconciliation --------------------------------------

    private function reconciliation(): ReportTable
    {
        $ids = $this->resolvedLocationIds();
        [$start, $end] = $this->bounds();

        // Batch intake (grams + cost) by genetic, over the period.
        $intake = [];
        DB::table('batches')->whereIn('location_id', $ids)
            ->whereNull('deleted_at')
            ->whereNotNull('acquired_or_harvested_on')
            ->where('acquired_or_harvested_on', '>=', $start->toDateString())
            ->where('acquired_or_harvested_on', '<', $end->toDateString())
            ->get(['genetic_id', 'initial_cg', 'cost_per_gram_cents'])
            ->each(function (\stdClass $b) use (&$intake): void {
                $g = (string) $b->genetic_id;
                $intake[$g] ??= ['entrada_cg' => 0, 'coste' => 0];
                $intake[$g]['entrada_cg'] += (int) $b->initial_cg;
                $intake[$g]['coste'] += intdiv((int) $b->initial_cg * (int) $b->cost_per_gram_cents, 100);
            });

        // Dispensed grams by genetic, over the period.
        $dispensed = DB::table('dispensation_lines')
            ->join('dispensations', 'dispensation_lines.dispensation_id', '=', 'dispensations.id')
            ->whereIn('dispensations.location_id', $ids)
            ->where('dispensations.status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensations.dispensed_at', [$start, $end])
            ->groupBy('dispensation_lines.genetic_id')
            ->pluck(DB::raw('SUM(dispensation_lines.grams_cg)'), 'dispensation_lines.genetic_id');

        $geneticIds = array_values(array_unique([...array_keys($intake), ...$dispensed->keys()->map(fn ($k) => (string) $k)->all()]));
        $names = DB::table('genetics')->whereIn('id', $geneticIds)->pluck('name', 'id');

        $rows = [];
        foreach ($geneticIds as $gid) {
            $rows[] = [
                'genetica' => (string) ($names[$gid] ?? __('Sin genética')),
                'entrada' => $intake[$gid]['entrada_cg'] ?? 0,
                'coste' => $intake[$gid]['coste'] ?? 0,
                'dispensado' => (int) ($dispensed[$gid] ?? 0),
            ];
        }
        usort($rows, fn (array $a, array $b): int => $b['dispensado'] <=> $a['dispensado']);

        $totals = [
            'entrada' => array_sum(array_column($rows, 'entrada')),
            'coste' => array_sum(array_column($rows, 'coste')),
            'dispensado' => array_sum(array_column($rows, 'dispensado')),
        ];

        return new ReportTable(
            key: 'reconciliation',
            title: __('Conciliación compra–retirada'),
            columns: [
                ReportColumn::text('genetica', __('Genética'), sortable: false),
                ReportColumn::weight('entrada', __('Entrada')),
                ReportColumn::money('coste', __('Coste entrada')),
                ReportColumn::weight('dispensado', __('Dispensado')),
            ],
            rows: $rows,
            totals: $totals,
            empty: __('Sin entradas ni dispensaciones en este período'),
        );
    }

    // --- Shared expense scoping -----------------------------------------------------

    /**
     * Concrete (non-template) expenses in scope. A specific-location view sees that
     * location's outgoings; only the owner's "All" view folds in org-wide (null-location)
     * overheads such as rent — attributing shared overheads to one sede would be a fiction.
     */
    private function expensesQuery(): QueryBuilder
    {
        return DB::table('expenses')
            ->where('expenses.organisation_id', $this->organisationId)
            ->whereNull('expenses.deleted_at')
            ->whereNull('expenses.recurrence')
            ->where(fn (QueryBuilder $q) => $this->scopeExpenseLocation($q, 'expenses'));
    }

    private function scopeExpenseLocation(QueryBuilder $query, string $table): QueryBuilder
    {
        $query->whereIn($table.'.location_id', $this->resolvedLocationIds());
        if ($this->includesAllLocations()) {
            $query->orWhereNull($table.'.location_id');
        }

        return $query;
    }
}
