<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\MembershipPeriod;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\MembershipTierFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Org-wide membership tier template. */
class MembershipTier extends Model
{
    /** @use HasFactory<MembershipTierFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organisation_id', 'name', 'default_fee_cents', 'default_period',
        'daily_limit_cg', 'monthly_limit_cg', 'benefits', 'active',
    ];

    protected function casts(): array
    {
        return [
            'default_fee_cents' => MoneyCast::class,
            'default_period' => MembershipPeriod::class,
            'daily_limit_cg' => 'integer',
            'monthly_limit_cg' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'tier_id');
    }
}
