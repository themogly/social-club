<?php

namespace App\Models;

use App\Enums\AlertType;
use App\Models\Concerns\BelongsToOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prompt 311 — one alert condition while it lasts: opened when an item crosses the line, cleared when it recovers. An item
 * that stays low for three days is ONE row and ONE message; it fires again only after it cleared and crossed again.
 * `detail` holds names and quantities for the message — never member data.
 *
 * @property-read Location|null $location none for a System alert (it has no sede)
 */
class OwnerAlertState extends Model
{
    use BelongsToOrganisation, HasUlids;

    protected $fillable = ['organisation_id', 'type', 'subject', 'location_id', 'detail', 'active_since', 'notified_at', 'cleared_at'];

    protected function casts(): array
    {
        return [
            'type' => AlertType::class,
            'detail' => 'array',
            'active_since' => 'datetime',
            'notified_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withoutGlobalScopes();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cleared_at');
    }

    /** The identity of the condition: the same type, subject and place is the same alert. */
    public function key(): string
    {
        return self::keyOf($this->type, $this->subject, $this->location_id);
    }

    public static function keyOf(AlertType $type, string $subject, ?string $locationId): string
    {
        return $type->value.'|'.$subject.'|'.($locationId ?? '-');
    }
}
