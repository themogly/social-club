<?php

namespace App\Actions\Stock;

use App\Enums\LocationKind;
use App\Models\Article;
use App\Models\Location;
use App\Models\User;
use App\Support\LocationSwitcher;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * *Añadir a otra sede* (prompt 297): the same product — name, price, photos, low-stock threshold, active flag — at more
 * sedes, each with its own opening stock. The copies join the source's group (one is made if it had none). Only a sede
 * the actor works at, and never one that already has a product of the same name.
 */
class AddArticleToLocations
{
    /**
     * @param  array<string, int>  $openingByLocation  location id => opening units
     * @return Collection<int, Article>
     *
     * @throws AuthorizationException
     * @throws DomainException a sede that is not a sede, or already has the product
     */
    public function handle(Article $source, array $openingByLocation, User $actor): Collection
    {
        $switcher = app(LocationSwitcher::class);

        foreach (array_keys($openingByLocation) as $locationId) {
            $sede = Location::query()->withoutGlobalScopes()->find($locationId);
            if (! $sede instanceof Location || ! $switcher->canAccess($actor, (string) $locationId)) {
                throw new AuthorizationException(__('Esa sede no es una en la que trabajas.'));
            }
            if ($sede->kind !== LocationKind::SEDE || $sede->organisation_id !== $source->organisation_id) {
                throw new DomainException(__('Un producto de barra solo puede estar en una sede, no en el almacén.'));
            }
            if (self::nameTakenAt($source, (string) $locationId)) {
                throw new DomainException(__('Este producto ya existe en :sede.', ['sede' => $sede->name]));
            }
        }

        return DB::transaction(function () use ($source, $openingByLocation, $actor): Collection {
            if ($source->group_id === null) {
                $source->forceFill(['group_id' => (string) Str::ulid()])->save();
            }

            return (new IntakeArticleAtLocations)->handle(
                $source->only(['organisation_id', 'name', 'price_cents', 'images', 'low_stock_threshold', 'active']),
                array_map('intval', $openingByLocation),
                ['operator_id' => $actor->getKey()],
                $source->group_id,
            );
        });
    }

    public static function nameTakenAt(Article $source, string $locationId): bool
    {
        return Article::query()->withoutGlobalScopes()
            ->where('organisation_id', $source->organisation_id)
            ->where('location_id', $locationId)
            ->where('name', $source->name)
            ->whereNull('deleted_at')
            ->exists();
    }
}
