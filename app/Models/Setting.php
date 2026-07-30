<?php

namespace App\Models;

use App\Enums\SettingType;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single keyed, typed setting. Location-scoped when location_id is set,
 * otherwise organisation-level. Read through the App\Support\Settings accessor
 * (location → organisation → code default) — never queried ad hoc by features.
 */
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids;

    protected $fillable = [
        'organisation_id', 'location_id', 'key', 'value', 'type',
    ];

    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
