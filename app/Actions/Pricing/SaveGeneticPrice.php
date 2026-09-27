<?php

namespace App\Actions\Pricing;

use App\Actions\RecordAuditLog;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * THE single writer for GeneticPrice rows (prompt 63) — the counterpart to ResolvePrice, which stays
 * the single READER. Given a genetic, a location, an optional tier and a rate in cents, it sets the ONE
 * price column the genetic's unit_type allows (per gram for WEIGHT, per unit for UNIT) and nulls the
 * other, then persists — the model's saving guard enforces the one-of-two, this just feeds it right.
 * Every write is audited as `genetic.price.updated` with the real old/new cents (prompt 48 placement:
 * inside the transaction). Euros are converted to cents by the caller at the form edge.
 */
class SaveGeneticPrice
{
    public function handle(
        Genetic $genetic,
        Location $location,
        ?string $tierId,
        int $priceCents,
        ?int $lowStockThresholdCg = null,
        bool $active = true,
        ?GeneticPrice $existing = null,
        ?int $eighthCents = null,
    ): GeneticPrice {
        // Prompt 271 — ONE row per (variety, sede, tarifa). A second one left the charged price to the database's row
        // order. The form refuses it first; this is the writer's own guard.
        if ($existing === null && self::rowExists($genetic, $location->id, $tierId)) {
            throw new InvalidArgumentException(__('Ya hay un precio para esta sede y tarifa. Edítalo en lugar de añadir otro.'));
        }

        return DB::transaction(function () use ($genetic, $location, $tierId, $priceCents, $lowStockThresholdCg, $active, $existing, $eighthCents): GeneticPrice {
            $column = $genetic->isUnitType() ? 'price_per_unit_cents' : 'price_per_gram_cents';
            $otherColumn = $genetic->isUnitType() ? 'price_per_gram_cents' : 'price_per_unit_cents';

            $price = $existing ?? new GeneticPrice([
                'organisation_id' => $genetic->organisation_id,
                'genetic_id' => $genetic->id,
                'location_id' => $location->id,
                'tier_id' => $tierId,
            ]);

            $before = $existing !== null ? [
                'price_per_gram_cents' => $existing->price_per_gram_cents,
                'price_per_unit_cents' => $existing->price_per_unit_cents,
                'low_stock_threshold_cg' => $existing->low_stock_threshold_cg,
                'active' => $existing->active,
            ] : null;

            $price->{$column} = $priceCents;
            $price->{$otherColumn} = null;
            // Eighth (3.5 g) price is WEIGHT-only (prompt 83); a UNIT genetic never carries one.
            $price->price_per_eighth_cents = $genetic->isUnitType() ? null : $eighthCents;
            $price->low_stock_threshold_cg = $lowStockThresholdCg;
            $price->active = $active;
            $price->save();

            (new RecordAuditLog)->handle('genetic.price.updated', $price, $before, [
                'genetic_id' => $genetic->id,
                'location_id' => $location->id,
                'tier_id' => $price->tier_id,
                'price_per_gram_cents' => $price->price_per_gram_cents,
                'price_per_eighth_cents' => $price->price_per_eighth_cents,
                'price_per_unit_cents' => $price->price_per_unit_cents,
                'low_stock_threshold_cg' => $price->low_stock_threshold_cg,
                'active' => $price->active,
            ]);

            return $price;
        });
    }

    /** Does this variety already have a price row for this sede and tarifa (null = the base price)? */
    public static function rowExists(Genetic $genetic, ?string $locationId, ?string $tierId): bool
    {
        return GeneticPrice::query()->withoutGlobalScopes()
            ->where('genetic_id', $genetic->id)
            ->where('location_id', $locationId)
            ->when($tierId === null, fn ($q) => $q->whereNull('tier_id'), fn ($q) => $q->where('tier_id', $tierId))
            ->exists();
    }
}
