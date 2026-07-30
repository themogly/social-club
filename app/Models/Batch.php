<?php

namespace App\Models;

use App\Casts\WeightCast;
use App\Enums\BatchStatus;
use App\Enums\UnitType;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use Database\Factories\BatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * A per-location lot of a genetic. A WEIGHT genetic's batch carries centigrams
 * (initial_cg / remaining_cg); a UNIT genetic's batch carries whole units
 * (initial_units / remaining_units). Exactly ONE of each pair is populated, driven
 * by the genetic's unit_type and enforced by the saving guard. `onHandCg()` gives the
 * gram-equivalent on hand for BOTH kinds so the premises stock picture aggregates them.
 */
class Batch extends Model
{
    /** @use HasFactory<BatchFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, ScopedToLocation, SoftDeletes;

    protected $fillable = [
        'organisation_id', 'genetic_id', 'location_id', 'batch_no',
        'acquired_or_harvested_on', 'expires_on', 'initial_cg', 'remaining_cg',
        'initial_units', 'remaining_units',
        'cost_per_gram_cents', 'lab_report_path', 'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'acquired_or_harvested_on' => 'date',
            'expires_on' => 'date',
            'initial_cg' => WeightCast::class,
            'remaining_cg' => WeightCast::class,
            'initial_units' => 'integer',
            'remaining_units' => 'integer',
            'cost_per_gram_cents' => 'integer',   // rate
            'status' => BatchStatus::class,
        ];
    }

    protected static function booted(): void
    {
        // One-of-two: each cg/units pair must match the genetic's unit_type.
        static::saving(function (Batch $batch): void {
            $genetic = Genetic::withoutGlobalScopes()->whereKey($batch->genetic_id)->first(['unit_type']);
            if ($genetic === null) {
                return; // genetic not resolvable yet — nothing to enforce against
            }

            $attrs = $batch->getAttributes();
            $initialCg = $attrs['initial_cg'] ?? null;
            $remainingCg = $attrs['remaining_cg'] ?? null;
            $initialUnits = $attrs['initial_units'] ?? null;
            $remainingUnits = $attrs['remaining_units'] ?? null;

            if ($genetic->unit_type === UnitType::UNIT) {
                if ($remainingUnits === null || $initialUnits === null || $remainingCg !== null || $initialCg !== null) {
                    throw new RuntimeException('A per-unit batch must set initial_units/remaining_units and leave the cg columns null.');
                }
            } elseif ($remainingCg === null || $initialCg === null || $remainingUnits !== null || $initialUnits !== null) {
                throw new RuntimeException('A by-weight batch must set initial_cg/remaining_cg and leave the unit columns null.');
            }
        });
    }

    /** @return BelongsTo<Genetic, $this> */
    public function genetic(): BelongsTo
    {
        return $this->belongsTo(Genetic::class);
    }

    /** True when this batch is stocked in whole units (preroll/edible), not by weight. */
    public function isUnitType(): bool
    {
        return $this->resolveGenetic()?->unit_type === UnitType::UNIT;
    }

    /**
     * Gram-equivalent on hand, integer centigrams — for a WEIGHT batch its remaining_cg,
     * for a UNIT batch remaining_units × the genetic's grams_per_unit_cg. Lets the
     * premises stock ceiling / on-hand picture aggregate both kinds on one scale.
     */
    public function onHandCg(): int
    {
        $genetic = $this->resolveGenetic();

        if ($genetic !== null && $genetic->unit_type === UnitType::UNIT) {
            return (int) ($this->remaining_units ?? 0) * (int) $genetic->grams_per_unit_cg;
        }

        // WEIGHT batch: remaining_cg is non-null by the one-of-two invariant.
        return $this->remaining_cg->centigrams;
    }

    private function resolveGenetic(): ?Genetic
    {
        if ($this->relationLoaded('genetic')) {
            return $this->genetic;
        }

        return Genetic::withoutGlobalScopes()->find($this->genetic_id);
    }

    /** @return MorphMany<StockMovement, $this> */
    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'stockable');
    }

    /**
     * Everything dispensed from this batch — the traceability spine.
     *
     * @return HasMany<DispensationLine, $this>
     */
    public function dispensationLines(): HasMany
    {
        return $this->hasMany(DispensationLine::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', BatchStatus::OPEN);
    }
}
