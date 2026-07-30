<?php

namespace App\Models;

use App\Casts\WeightCast;
use App\Enums\BatchStatus;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use Database\Factories\BatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Batch extends Model
{
    /** @use HasFactory<BatchFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, ScopedToLocation, SoftDeletes;

    protected $fillable = [
        'organisation_id', 'genetic_id', 'location_id', 'batch_no',
        'acquired_or_harvested_on', 'expires_on', 'initial_cg', 'remaining_cg',
        'cost_per_gram_cents', 'lab_report_path', 'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'acquired_or_harvested_on' => 'date',
            'expires_on' => 'date',
            'initial_cg' => WeightCast::class,
            'remaining_cg' => WeightCast::class,
            'cost_per_gram_cents' => 'integer',   // rate
            'status' => BatchStatus::class,
        ];
    }

    /** @return BelongsTo<Genetic, $this> */
    public function genetic(): BelongsTo
    {
        return $this->belongsTo(Genetic::class);
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
