<?php

namespace App\Actions\Stock;

use App\Actions\RecordAuditLog;
use App\Enums\StockMovementType;
use App\Enums\StockTakeStatus;
use App\Models\Article;
use App\Models\Batch;
use App\Models\StockTake;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Commit an inventory count: for each counted batch/article, record the variance as
 * a StockTakeLine and — when non-zero — an ADJUSTMENT movement (the count as the
 * reference) so the ledger reconciles to the counted figure. Never a silent
 * overwrite; permissioned by the caller and audited.
 *
 * A count entry may instead be flagged `not_counted` (prompt 91): the omission is recorded as a line with
 * its reason, and the batch's stock is left UNTOUCHED — NO variance, NO adjustment, NO merma. It is not a
 * count of zero (that is `counted: 0`); it is "could not weigh this jar", surfaced for a manager to chase.
 *
 * @phpstan-type Count array{type: 'batch'|'article', id: string, counted?: int, not_counted?: bool, reason?: ?string}
 */
class CommitStockTake
{
    /**
     * @param  list<Count>  $counts
     */
    public function handle(StockTake $stockTake, array $counts, User $committer): StockTake
    {
        return DB::transaction(function () use ($stockTake, $counts, $committer): StockTake {
            $recorder = new RecordStockMovement;

            foreach ($counts as $count) {
                // "Not counted": record the omission and its reason, touch NOTHING in the ledger.
                if (($count['not_counted'] ?? false) === true) {
                    $class = $count['type'] === 'batch' ? Batch::class : Article::class;
                    $stockTake->lines()->create([
                        'countable_type' => $class, 'countable_id' => $count['id'],
                        'not_counted' => true, 'not_counted_reason' => $count['reason'] ?? null,
                    ]);

                    continue;
                }

                $counted = (int) ($count['counted'] ?? 0);

                if ($count['type'] === 'batch') {
                    $batch = Batch::withoutGlobalScopes()->findOrFail($count['id']);
                    $expected = $batch->remaining_cg->centigrams;
                    $variance = $counted - $expected;

                    $stockTake->lines()->create([
                        'countable_type' => Batch::class, 'countable_id' => $batch->id,
                        'counted_cg' => $counted, 'expected_cg' => $expected, 'variance_cg' => $variance,
                    ]);

                    if ($variance !== 0) {
                        $recorder->handle($batch, StockMovementType::ADJUSTMENT, $variance, [
                            'stock_take_id' => $stockTake->id, 'reason' => 'Recuento de inventario', 'operator_id' => $committer->id,
                        ]);
                    }
                } else {
                    $article = Article::withoutGlobalScopes()->findOrFail($count['id']);
                    $expected = $article->stock;
                    $variance = $counted - $expected;

                    $stockTake->lines()->create([
                        'countable_type' => Article::class, 'countable_id' => $article->id,
                        'counted_units' => $counted, 'expected_units' => $expected, 'variance_units' => $variance,
                    ]);

                    if ($variance !== 0) {
                        $recorder->handle($article, StockMovementType::ADJUSTMENT, $variance, [
                            'stock_take_id' => $stockTake->id, 'reason' => 'Recuento de inventario', 'operator_id' => $committer->id,
                        ]);
                    }
                }
            }

            $stockTake->update([
                'status' => StockTakeStatus::COMMITTED,
                'committed_by' => $committer->id,
                'committed_at' => now(),
            ]);

            (new RecordAuditLog)->handle('stocktake.committed', $stockTake, null, ['lines' => count($counts)]);

            return $stockTake;
        });
    }
}
