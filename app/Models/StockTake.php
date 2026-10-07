<?php

namespace App\Models;

use App\Enums\StockTakeStatus;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use Database\Factories\StockTakeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTake extends Model
{
    /** @use HasFactory<StockTakeFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, ScopedToLocation;

    /** Prompt 318 — a full count for a sede (*Inventario*), vs the till's closing recount of today's flower. */
    public const KIND_INVENTORY = 'inventory';

    public const KIND_TILL_RECOUNT = 'till_recount';

    protected $fillable = [
        'organisation_id', 'location_id', 'kind', 'opened_by', 'opened_at',
        'committed_by', 'committed_at', 'cancelled_by', 'cancelled_at', 'status', 'notes',
        'reason', // prompt 360 — the end-of-day weigh's one answer when the count was off (copied onto its adjustments)
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'committed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'status' => StockTakeStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return BelongsTo<User, $this> */
    public function committedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }

    /** @return HasMany<StockTakeLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTakeLine::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withoutGlobalScopes();
    }

    /**
     * Prompt 318 — the full counts (*Inventario*), not the till's recounts.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInventories(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_INVENTORY);
    }

    public function isOpen(): bool
    {
        return $this->status === StockTakeStatus::OPEN;
    }
}
