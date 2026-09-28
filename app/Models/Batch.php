<?php

namespace App\Models;

use App\Casts\WeightCast;
use App\Enums\BatchStatus;
use App\Enums\UnitType;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Database\Factories\BatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'organisation_id', 'genetic_id', 'parent_batch_id', 'location_id', 'batch_no', 'lote_seq', 'label',
        'acquired_or_harvested_on', 'expires_on', 'initial_cg', 'remaining_cg',
        'initial_units', 'remaining_units',
        'cost_per_gram_cents', 'price_per_gram_cents', 'price_per_unit_cents', 'price_per_eighth_cents',
        'lab_report_path', 'images', 'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'acquired_or_harvested_on' => 'date',
            'expires_on' => 'date',
            'initial_cg' => WeightCast::class,
            'remaining_cg' => WeightCast::class,
            'lote_seq' => 'integer',
            'initial_units' => 'integer',
            'remaining_units' => 'integer',
            'cost_per_gram_cents' => 'integer',   // rate
            'price_per_gram_cents' => 'integer',  // rate (prompt 278) — the SALE price of THIS batch
            'price_per_unit_cents' => 'integer',  // rate
            'price_per_eighth_cents' => 'integer', // rate — the 3.5 g price, weight only
            'images' => 'array',
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

    /**
     * The batch this one was split from by a part-transfer (prompt 277) — same lote number, same harvest. Null for a
     * batch received directly.
     *
     * @return BelongsTo<Batch, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'parent_batch_id');
    }

    /**
     * The club's own name for the batch (prompt 282) — trimmed, and a blank one is stored as null.
     *
     * @return Attribute<?string, ?string>
     */
    protected function label(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => filled($value) ? trim($value) : null);
    }

    /**
     * How a batch is shown to a person (prompt 298, after 282): WHAT it is first — the strain — then what tells it apart:
     * the club's name for it, or the automatic description. The lote number is not part of it; it stays where
     * traceability needs it ({@see self::referenceName()}, the registro, the recall, the snapshots).
     */
    public function displayName(): string
    {
        return implode(' · ', array_filter([$this->displayTitle(), $this->displaySubtitle()], 'filled'));
    }

    /** The strain's name (prompt 298). */
    public function displayTitle(): string
    {
        return (string) $this->resolveGenetic()?->name;
    }

    /**
     * The batch's `label` when the club named it, otherwise "#3 · entrada 12 sep · 250,00 g" (prompt 298): its lote's
     * number within the strain, the day it came in (the year only when not this year) and what came in. `short` is the
     * counter's version under a strain heading: "#3 · 12 sep".
     */
    public function displaySubtitle(bool $short = false): string
    {
        if (filled($this->label)) {
            return (string) $this->label;
        }

        $day = $this->receivedOn();
        $date = $day === null ? null : $day->locale(app()->getLocale())->isoFormat('D').' '
            .rtrim($day->locale(app()->getLocale())->isoFormat('MMM'), '.')
            .($day->year !== now()->year ? ' '.$day->year : '');
        $seq = $this->lote_seq !== null ? '#'.$this->lote_seq : null;

        if ($short) {
            return implode(' · ', array_filter([$seq, $date], 'filled'));
        }

        $quantity = $this->initial_units !== null
            ? __(':count uds', ['count' => $this->initial_units])
            : ($this->initial_cg !== null ? $this->initial_cg->formatted() : null);

        return implode(' · ', array_filter([$seq, $date !== null ? __('entrada :date', ['date' => $date]) : null, $quantity], 'filled'));
    }

    /** The display name WITH the lote number — for the places traceability needs it (a recall, a stock-count report). */
    public function referenceName(): string
    {
        return $this->displayName().' · '.$this->batch_no;
    }

    /** The day it came in: the harvest/acquisition date, or the business day it was entered. */
    private function receivedOn(): ?CarbonImmutable
    {
        if ($this->acquired_or_harvested_on !== null) {
            return CarbonImmutable::parse($this->acquired_or_harvested_on);
        }
        $location = Location::query()->withoutGlobalScopes()->find($this->location_id);

        return $this->created_at !== null && $location instanceof Location ? BusinessDay::date($location, $this->created_at) : null;
    }

    /**
     * Every part of this batch's lote — same organisation, strain and lote number (a part transfer keeps all three),
     * this batch included. The name belongs to the lote, so a rename reaches all of them.
     *
     * @return Builder<Batch>
     */
    public function lotePartsQuery(): Builder
    {
        return Batch::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->organisation_id)
            ->where('genetic_id', $this->genetic_id)
            ->where('batch_no', $this->batch_no)
            ->whereNull('deleted_at');
    }

    /** Does this batch carry its own sale price? (prompt 278) — per unit for a unit product, per gram otherwise. */
    public function hasOwnPrice(): bool
    {
        return $this->isUnitType() ? $this->price_per_unit_cents !== null : $this->price_per_gram_cents !== null;
    }

    /**
     * The photos to show for this batch (prompt 278): its own, else its parent's (a transferred part shows the harvest's
     * photos until it gets its own — stored once, never copied), else none.
     *
     * @return list<string> public-disk paths
     */
    public function displayImages(int $depth = 0): array
    {
        $own = array_values(array_filter((array) ($this->images ?? []), 'is_string'));

        if ($own !== [] || $this->parent_batch_id === null || $depth > 10) {
            return $own;
        }

        return Batch::query()->withoutGlobalScopes()->find($this->parent_batch_id)?->displayImages($depth + 1) ?? [];
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

    /**
     * Dispensable right now: OPEN, in stock, and not past expiry. "In stock" keys off
     * whichever unit the genetic uses — the other column is null by the one-of-two
     * invariant, so `remaining_cg > 0 OR remaining_units > 0` resolves per type without
     * a join. The ONE predicate the FEFO selector and every POS stock query route through.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDispensable(Builder $query, ?string $locationId = null): Builder
    {
        // Expiry against the SEDE's business date when the caller names the sede (prompt 275), not the UTC calendar.
        $today = $locationId !== null ? BusinessDay::today($locationId) : today()->toDateString();

        return $query->where('status', BatchStatus::OPEN)
            ->where(fn (Builder $q): Builder => $q->where('remaining_cg', '>', 0)->orWhere('remaining_units', '>', 0))
            ->where(fn (Builder $q): Builder => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $today));
    }

    /**
     * "Dispensable, oldest first" — FEFO, in ONE place (prompt 273). The lote the counter offers (`SelectBatch::fefo`)
     * and the lotes the allocator draws (`AllocateFromBatches`) used to type the ordering separately; if they drifted,
     * "oldest first" would mean two things in manual and automatic mode.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFefo(Builder $query, ?string $locationId = null): Builder
    {
        return $query->dispensable($locationId)->orderBy('acquired_or_harvested_on')->orderBy('id');
    }
}
