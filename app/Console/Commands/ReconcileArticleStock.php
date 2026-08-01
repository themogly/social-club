<?php

namespace App\Console\Commands;

use App\Enums\StockMovementType;
use App\Models\Article;
use App\Models\StockMovement;
use Illuminate\Console\Command;

/**
 * One-off (prompt 104): every article created before opening stock entered the ledger is missing its opening
 * INTAKE, so its movement history sums negative. This back-fills ONE opening INTAKE per article sized so the
 * ledger reconciles to the current stock (`stock − Σqty_units`). The rows carry a reconciliation reason so
 * they are identifiable as a back-fill, not a real delivery. Idempotent — an article that already reconciles
 * gets nothing, so running twice never double-fills. Manual, like csc:install.
 */
class ReconcileArticleStock extends Command
{
    protected $signature = 'csc:reconcile-article-stock {--dry-run : Report what would be back-filled without writing}';

    protected $description = 'Back-fill the opening INTAKE movement for pre-existing articles so their ledger reconciles';

    public const REASON = 'Saldo inicial de reconciliación (prompt 104)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $morph = (new Article)->getMorphClass();
        $filled = 0;
        $alreadyOk = 0;

        Article::query()->withoutGlobalScopes()->chunkById(200, function ($articles) use (&$filled, &$alreadyOk, $morph, $dryRun): void {
            foreach ($articles as $article) {
                $ledger = (int) StockMovement::query()->withoutGlobalScopes()
                    ->where('stockable_type', $morph)->where('stockable_id', $article->id)->sum('qty_units');
                $opening = (int) $article->stock - $ledger;

                if ($opening === 0) {
                    $alreadyOk++;

                    continue; // already reconciles — idempotent
                }

                if (! $dryRun) {
                    StockMovement::create([
                        'organisation_id' => $article->organisation_id,
                        'location_id' => $article->location_id,
                        'stockable_type' => $morph,
                        'stockable_id' => $article->id,
                        'qty_cg' => null,
                        'qty_units' => $opening,
                        'type' => StockMovementType::INTAKE,
                        'reason' => self::REASON,
                        'operator_id' => null,
                    ]);
                }
                $filled++;
            }
        });

        $this->info(($dryRun ? '[dry-run] ' : '')."Back-filled {$filled} article(s); {$alreadyOk} already reconciled.");

        return self::SUCCESS;
    }
}
