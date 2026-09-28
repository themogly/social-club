<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Number the existing lotes within their strain (prompt 298), for the automatic description "#3 · entrada …". Per
 * organisation and strain, the LOTES — batches sharing `genetic_id` and `batch_no`, i.e. a lote and its transferred
 * parts — are ordered by their earliest `created_at` and numbered 1, 2, 3…; every part of a lote gets its lote's
 * number. Display only: `batch_no` is never touched (the registro and every movement's reference hang on it).
 * Idempotent: a strain whose batches are already numbered keeps its numbers; numbering continues after the highest.
 */
class LoteSeqBackfill
{
    public static function run(): void
    {
        $lotes = DB::table('batches')
            ->whereNull('lote_seq')
            ->select('organisation_id', 'genetic_id', 'batch_no', DB::raw('MIN(created_at) as first_at'))
            ->groupBy('organisation_id', 'genetic_id', 'batch_no')
            ->orderBy('organisation_id')->orderBy('genetic_id')->orderBy('first_at')->orderBy('batch_no')
            ->get();

        $next = [];
        foreach ($lotes as $lote) {
            $strain = $lote->organisation_id.'|'.$lote->genetic_id;
            $next[$strain] ??= (int) DB::table('batches')
                ->where('organisation_id', $lote->organisation_id)->where('genetic_id', $lote->genetic_id)
                ->max('lote_seq') + 1;

            DB::table('batches')
                ->where('organisation_id', $lote->organisation_id)->where('genetic_id', $lote->genetic_id)
                ->where('batch_no', $lote->batch_no)->whereNull('lote_seq')
                ->update(['lote_seq' => $next[$strain]++]);
        }
    }
}
