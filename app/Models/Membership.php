<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\MembershipStatus;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use App\Support\Settings;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, ScopedToLocation, SoftDeletes;

    protected $fillable = [
        'organisation_id', 'member_id', 'location_id', 'tier_id',
        'starts_at', 'expires_at', 'fee_cents', 'fee_override_by', 'status', 'reminder_sent_for',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'fee_cents' => MoneyCast::class,
            'status' => MembershipStatus::class,
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<MembershipTier, $this> */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(MembershipTier::class, 'tier_id');
    }

    /** @return HasMany<MembershipFeePayment, $this> */
    public function feePayments(): HasMany
    {
        return $this->hasMany(MembershipFeePayment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function feeOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fee_override_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MembershipStatus::ACTIVE);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLapsed(Builder $query): Builder
    {
        return $query->where('status', MembershipStatus::LAPSED);
    }

    /**
     * Memberships inside the renewal window — the ONE definition of "vence pronto" (prompt 207).
     *
     * There were two, and they disagreed in a way that emptied the alert. `Dashboard::expiringMemberships()`
     * counted `status = ACTIVE` inside a **hardcoded 30 days**, while `SweepMembershipExpiry` reads the
     * `expiring_soon_days` Setting and, on the way past, **flips exactly those rows to `EXPIRING_SOON`** — so
     * the nightly sweep took every membership the dashboard was counting out of the count. A club that had
     * widened the window disagreed twice over. Both statuses are in scope here, and the window is the Setting
     * the sweep already uses.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeExpiringSoon(Builder $query): Builder
    {
        $days = (int) Settings::get('expiring_soon_days', 30);

        return $query
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::EXPIRING_SOON->value])
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }
}
