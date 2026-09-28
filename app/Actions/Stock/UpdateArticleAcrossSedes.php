<?php

namespace App\Actions\Stock;

use App\Actions\RecordAuditLog;
use App\Models\Article;
use App\Models\Location;
use App\Models\User;
use App\Support\LocationSwitcher;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Save a product and, where the owner ticked them, the same product at other sedes of its group (prompt 297). One
 * transaction: a sede correction first ({@see MoveArticleToLocation}), then the edit, then the SHARED fields — name,
 * price, photos, low-stock threshold, active — on the ticked siblings only. Stock is never shared: each sede counts its
 * own. A sibling at a sede the actor does not work at is refused, not skipped. One `article.group.updated` entry lists
 * the products and sedes the change reached.
 */
class UpdateArticleAcrossSedes
{
    public const SHARED = ['name', 'price_cents', 'images', 'low_stock_threshold', 'active'];

    /**
     * @param  array<string, mixed>  $attributes  the edited columns (never stock or group_id)
     * @param  list<string>  $siblingIds  articles of the same group to apply the shared fields to
     *
     * @throws AuthorizationException
     * @throws DomainException when the sede cannot change ({@see MoveArticleToLocation})
     */
    public function handle(Article $article, array $attributes, ?string $locationId, array $siblingIds, User $actor): Article
    {
        return DB::transaction(function () use ($article, $attributes, $locationId, $siblingIds, $actor): Article {
            if ($locationId !== null && $locationId !== $article->location_id) {
                $article = (new MoveArticleToLocation)->handle($article, Location::query()->withoutGlobalScopes()->findOrFail($locationId), $actor);
            }

            unset($attributes['stock'], $attributes['group_id'], $attributes['location_id'], $attributes['organisation_id']);
            $article->fill($attributes)->save();

            $siblings = $siblingIds === [] || $article->group_id === null
                ? new Collection
                : $article->groupSiblings()->whereKey($siblingIds)->lockForUpdate()->get();
            if ($siblings->count() !== count(array_unique($siblingIds))) {
                throw new AuthorizationException(__('Ese producto no es de este grupo.'));
            }

            $switcher = app(LocationSwitcher::class);
            foreach ($siblings as $sibling) {
                if (! $switcher->canAccess($actor, $sibling->location_id)) {
                    throw new AuthorizationException(__('Esa sede no es una en la que trabajas.'));
                }
                $sibling->fill(array_intersect_key($attributes, array_flip(self::SHARED)))->save();
            }

            if ($siblings->isNotEmpty()) {
                (new RecordAuditLog)->handle('article.group.updated', $article, null, [
                    'article_ids' => [$article->getKey(), ...$siblings->modelKeys()],
                    'location_ids' => [$article->location_id, ...$siblings->pluck('location_id')->all()],
                    'fields' => array_values(array_intersect(self::SHARED, array_keys($attributes))),
                ]);
            }

            return $article;
        });
    }
}
