<?php

namespace App\Actions\Stock;

use App\Actions\RecordAuditLog;
use App\Enums\LocationKind;
use App\Models\Article;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\LocationSwitcher;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Correct the sede a product was created at (prompt 297) — allowed only while it has no history
 * ({@see Article::hasHistory()}). In ONE transaction, with the article row locked (the counter's `CommitOrder` locks the
 * same row, so a sale made meanwhile is seen here and the move refuses): the article moves, its opening INTAKE movement
 * moves with it so the stock still reconciles, and one `article.location.changed` entry records from → to.
 */
class MoveArticleToLocation
{
    /**
     * @throws AuthorizationException
     * @throws DomainException the product has history, or the sede is not one
     */
    public function handle(Article $article, Location $to, User $actor): Article
    {
        $switcher = app(LocationSwitcher::class);
        if (! $switcher->canAccess($actor, $article->location_id) || ! $switcher->canAccess($actor, $to->id)) {
            throw new AuthorizationException(__('Esa sede no es una en la que trabajas.'));
        }
        if ($to->kind !== LocationKind::SEDE || $to->organisation_id !== $article->organisation_id) {
            throw new DomainException(__('Un producto de barra solo puede estar en una sede, no en el almacén.'));
        }

        return DB::transaction(function () use ($article, $to): Article {
            /** @var Article $locked */
            $locked = Article::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($article->getKey());

            if ($locked->location_id === $to->id) {
                return $locked;
            }
            if ($locked->hasHistory()) {
                throw new DomainException(self::lockedMessage($locked));
            }
            if ($locked->group_id !== null && $locked->groupSiblings()->where('location_id', $to->id)->exists()) {
                throw new DomainException(__('Este producto ya existe en :sede.', ['sede' => $to->name]));
            }

            $from = $locked->location_id;
            $locked->forceFill(['location_id' => $to->id])->save();
            StockMovement::query()->withoutGlobalScopes()
                ->where('stockable_type', $locked->getMorphClass())->where('stockable_id', $locked->getKey())
                ->update(['location_id' => $to->id]);

            (new RecordAuditLog)->handle('article.location.changed', $locked, ['location_id' => $from], ['location_id' => $to->id]);

            return $locked;
        });
    }

    /** Why the sede is locked, naming it — shown under the field and as the refusal. */
    public static function lockedMessage(Article $article): string
    {
        $sede = Location::query()->withoutGlobalScopes()->whereKey($article->location_id)->value('name');

        return __('Ya tiene ventas o movimientos de stock en :sede. Para venderlo en otra sede, usa «Añadir a otra sede».', ['sede' => $sede]);
    }
}
