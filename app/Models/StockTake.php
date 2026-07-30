<?php

namespace App\Models;

use App\Enums\StockTakeStatus;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use Database\Factories\StockTakeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTake extends Model
{
    /** @use HasFactory<StockTakeFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, ScopedToLocation;

    protected $fillable = [
        'organisation_id', 'location_id', 'opened_by', 'opened_at',
        'committed_by', 'committed_at', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'committed_at' => 'datetime',
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
}
