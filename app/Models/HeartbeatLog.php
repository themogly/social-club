<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One liveness row per operational component (scheduler, queue). The scheduled
 * `system:heartbeat` command refreshes `ran_at`; the health panel reads the latest and
 * decides "stale" against a threshold. ULID-keyed like everything user-addressable.
 */
class HeartbeatLog extends Model
{
    use HasUlids;

    protected $fillable = ['component', 'ran_at'];

    protected function casts(): array
    {
        return [
            'ran_at' => 'datetime',
        ];
    }

    /** Record (or refresh) a component's heartbeat — one row per component, never unbounded. */
    public static function beat(string $component = 'scheduler'): self
    {
        return self::updateOrCreate(['component' => $component], ['ran_at' => now()]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeComponent(Builder $query, string $component): Builder
    {
        return $query->where('component', $component);
    }
}
