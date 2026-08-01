<?php

namespace App\ViewModels;

use App\Models\Batch;
use App\ViewModels\Reports\ReportColumn;
use App\ViewModels\Reports\ReportTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Batch recall (prompt 86) — the OTHER direction of prompt 07's traceability spine: given one batch, WHO
 * received product from it, how much each, and when, with contact details so they can be reached. Read-only
 * over the dispensing history; it never alters a dispensation, movement or wallet.
 *
 * For a health recall we err toward COMPLETENESS: voided and refunded lines are INCLUDED and labelled (a
 * voided dispensation still means product left the counter at some point; a refund may mean some came back)
 * rather than filtered out and someone missed. The batch's current stock/status is irrelevant — recall is
 * precisely the case of a batch that is closed, empty or expired. Members are org-wide, so the affected
 * members may span sedes; the recall is by PRODUCT (this batch's lines), never narrowed to a home sede.
 *
 * Aggregated per member in SQL (one row each, correct total + date range), so it does not N+1 the history.
 */
class BatchRecall
{
    public function __construct(public readonly Batch $batch) {}

    /**
     * One row per affected member: total grams, first/last date, and a status flag (voided/refunded present).
     *
     * @return list<array{member_no: string, socio: string, contacto: string, gramos: int, primera: ?string, ultima: ?string, estado: string}>
     */
    public function rows(): array
    {
        $refundedMemberIds = DB::table('refunds')
            ->join('dispensations', 'dispensations.id', '=', 'refunds.dispensation_id')
            ->join('dispensation_lines', 'dispensation_lines.dispensation_id', '=', 'dispensations.id')
            ->where('dispensation_lines.batch_id', $this->batch->id)
            ->distinct()->pluck('dispensations.member_id')->all();

        return DB::table('dispensation_lines')
            ->join('dispensations', 'dispensations.id', '=', 'dispensation_lines.dispensation_id')
            ->join('members', 'members.id', '=', 'dispensations.member_id')
            ->where('dispensation_lines.batch_id', $this->batch->id)
            ->groupBy('members.id', 'members.member_no', 'members.first_name', 'members.last_name', 'members.email', 'members.phone')
            ->orderByRaw('SUM(dispensation_lines.grams_cg) DESC')
            ->get([
                'members.id as member_id',
                'members.member_no as member_no',
                'members.first_name as first_name',
                'members.last_name as last_name',
                'members.email as email',
                'members.phone as phone',
                DB::raw('SUM(dispensation_lines.grams_cg) as grams'),
                DB::raw('MIN(dispensations.dispensed_at) as first_at'),
                DB::raw('MAX(dispensations.dispensed_at) as last_at'),
                DB::raw("SUM(CASE WHEN dispensations.status = 'VOIDED' THEN 1 ELSE 0 END) as voided_count"),
            ])
            ->map(function (\stdClass $r) use ($refundedMemberIds): array {
                $flags = [];
                if ((int) $r->voided_count > 0) {
                    $flags[] = __('anulada');
                }
                if (in_array($r->member_id, $refundedMemberIds, true)) {
                    $flags[] = __('reembolsada');
                }

                return [
                    'member_no' => (string) $r->member_no,
                    'socio' => trim($r->first_name.' '.$r->last_name),
                    'contacto' => trim(($r->phone ?? '').' '.($r->email ?? '')) ?: '—',
                    'gramos' => (int) $r->grams,
                    'primera' => $r->first_at,
                    'ultima' => $r->last_at,
                    'estado' => $flags === [] ? __('Completada') : implode(' · ', $flags),
                ];
            })->all();
    }

    /**
     * The first thing anyone asks: how many members, how many grams, over what date range.
     *
     * @return array{members: int, grams_cg: int, first: ?Carbon, last: ?Carbon}
     */
    public function totals(): array
    {
        $rows = $this->rows();
        $firsts = array_filter(array_column($rows, 'primera'));
        $lasts = array_filter(array_column($rows, 'ultima'));

        return [
            'members' => count($rows),
            'grams_cg' => (int) array_sum(array_column($rows, 'gramos')),
            'first' => $firsts === [] ? null : Carbon::parse(min($firsts)),
            'last' => $lasts === [] ? null : Carbon::parse(max($lasts)),
        ];
    }

    /** The recall list as the shared report table, so the CSV export reuses the report machinery exactly. */
    public function table(): ReportTable
    {
        return new ReportTable(
            key: 'recall',
            title: __('Retirada de lote :batch', ['batch' => $this->batch->batch_no]),
            columns: [
                ReportColumn::text('member_no', __('Nº')),
                ReportColumn::text('socio', __('Socio')),
                ReportColumn::text('contacto', __('Contacto'), sortable: false),
                ReportColumn::weight('gramos', __('Gramos')),
                ReportColumn::datetime('primera', __('Primera')),
                ReportColumn::datetime('ultima', __('Última')),
                ReportColumn::text('estado', __('Estado'), sortable: false),
            ],
            rows: $this->rows(),
            totals: ['gramos' => $this->totals()['grams_cg']],
            empty: __('Nadie recibió producto de este lote'),
            defaultSort: 'gramos',
            defaultSortDir: 'desc',
            sortable: true,
        );
    }
}
