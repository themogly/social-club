<?php

namespace App\Models;

use App\Enums\BreachStatus;
use Database\Factories\BreachLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Data-breach incident register (AEPD 72-hour notification, Article 33). */
class BreachLog extends Model
{
    /** @use HasFactory<BreachLogFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'organisation_id', 'description', 'discovered_at', 'scope', 'aepd_notified_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'discovered_at' => 'datetime',
            'aepd_notified_at' => 'datetime',
            'status' => BreachStatus::class,
        ];
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
