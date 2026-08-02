<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\OrganisationLockdownFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One panic-lockdown event (prompt 121). Append-only: a lockdown is OPEN until `reactivated_at` is set, and
 * {@see App\Actions\Lockdown\InitiateLockdown} refuses to open a second one while one is open, so `active()`
 * returns at most one row per org. The org is locked NOW iff `active()` exists — that single check is what the
 * gate middleware and the sensitive-document stream both consult.
 */
class OrganisationLockdown extends Model
{
    /** @use HasFactory<OrganisationLockdownFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids;

    protected $fillable = [
        'organisation_id', 'locked_at', 'locked_by', 'is_drill', 'reason',
        'reactivated_at', 'reactivated_by', 'reactivation_method',
    ];

    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
            'reactivated_at' => 'datetime',
            'is_drill' => 'boolean',
        ];
    }

    /**
     * The org's OPEN lockdown (not yet reactivated), or null. This is the hot path — the gate calls it on every
     * request — so it is a single indexed lookup and is intentionally never cached (an out-of-date lockdown
     * state is exactly the failure this feature exists to prevent).
     */
    public static function active(string $organisationId): ?self
    {
        return self::query()->withoutGlobalScopes()
            ->where('organisation_id', $organisationId)
            ->whereNull('reactivated_at')
            ->latest('locked_at')
            ->first();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('reactivated_at');
    }

    public function isOpen(): bool
    {
        return $this->reactivated_at === null;
    }

    /** @return BelongsTo<User, $this> */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reactivated_by');
    }
}
