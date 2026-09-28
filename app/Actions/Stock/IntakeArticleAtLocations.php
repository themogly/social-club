<?php

namespace App\Actions\Stock;

use App\Models\Article;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One product at several sedes at once (prompt 297): one article per sede, each through {@see IntakeArticle} with its
 * OWN opening stock, all in one transaction — if one fails, none is created. Created together, they share a new
 * `group_id` (or the given one, when adding to an existing group), so an edit can later be applied to the sedes chosen.
 * A product made at a single sede gets no group.
 */
class IntakeArticleAtLocations
{
    /**
     * @param  array<string, mixed>  $attributes  Article columns except location_id, stock and group_id.
     * @param  array<string, int>  $openingByLocation  location id => opening units
     * @param  array<string, mixed>  $options  Passed to RecordStockMovement (operator_id).
     * @return Collection<int, Article>
     */
    public function handle(array $attributes, array $openingByLocation, array $options = [], ?string $groupId = null): Collection
    {
        return DB::transaction(function () use ($attributes, $openingByLocation, $options, $groupId): Collection {
            $groupId ??= count($openingByLocation) > 1 ? (string) Str::ulid() : null;

            return collect($openingByLocation)->map(fn (int $units, string $locationId): Article => (new IntakeArticle)->handle(
                array_merge($attributes, ['location_id' => $locationId, 'group_id' => $groupId]),
                max(0, $units),
                $options,
            ))->values();
        });
    }
}
