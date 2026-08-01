<?php

namespace App\Actions\Stock;

use App\Enums\StockMovementType;
use App\Models\Article;
use Illuminate\Support\Facades\DB;

/**
 * Create a bar/shop article and enter its OPENING stock through the ledger (prompt 104) — the article
 * mirror of IntakeBatch. The article is created empty and its opening balance is written as an INTAKE
 * movement through the single stock writer, in ONE transaction, so the article reconciles (sum of qty_units
 * movements == stock) from the first row rather than the ledger holding only depletions and summing negative.
 * Zero opening stock writes no spurious movement. Both the create screen and the seeder route through here.
 */
class IntakeArticle
{
    /**
     * @param  array<string, mixed>  $attributes  Article columns EXCEPT stock (entered through the ledger).
     * @param  array<string, mixed>  $options  Passed to RecordStockMovement (operator_id, reason).
     */
    public function handle(array $attributes, int $openingUnits, array $options = []): Article
    {
        return DB::transaction(function () use ($attributes, $openingUnits, $options): Article {
            /** @var Article $article */
            $article = Article::create(array_merge($attributes, ['stock' => 0]));

            if ($openingUnits > 0) {
                (new RecordStockMovement)->handle($article, StockMovementType::INTAKE, $openingUnits, $options + [
                    'reason' => __('Alta de artículo'),
                ]);
            }

            return $article->refresh();
        });
    }
}
