<?php

namespace App\Models;

use Database\Factories\OrganisationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The club association. Keyed everywhere so multi-org SaaS is additive later; the
 * scope-tree root, so it carries no organisation_id / global scope itself.
 */
class Organisation extends Model
{
    /** @use HasFactory<OrganisationFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'name', 'legal_name', 'tax_id', 'address', 'logo_path',
        'contact_email', 'contact_phone', 'member_no_sequence',
    ];

    protected function casts(): array
    {
        return [
            'member_no_sequence' => 'integer',
        ];
    }

    /** @return HasMany<Location, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /** @return HasMany<Member, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }
}
