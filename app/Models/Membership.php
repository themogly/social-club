<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\MembershipStatus;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use App\Support\MembershipExpiry;
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
        'covered_by_id', // prompt 348 — a linked membership at another sede, covered by the home one's fee
    ];

    /** What a linked membership takes from its home one whenever the home one changes (prompt 348). */
    private const FOLLOWS_HOME = ['tier_id', 'starts_at', 'expires_at', 'status'];

    /**
     * Prompt 348 — the linked memberships move WITH their home one: a renewal, an expiry, a cancellation, a tier change
     * or a date correction on the home membership is copied to every membership it covers, in the same request. One
     * hook rather than one line in every writer, so no writer can forget it. (A linked membership covers nothing, so
     * this never recurses; the copy bypasses events for the same reason.)
     */
    protected static function booted(): void
    {
        static::updated(function (Membership $home): void {
            if ($home->covered_by_id !== null || ! $home->wasChanged(self::FOLLOWS_HOME)) {
                return;
            }

            static::query()->withoutGlobalScopes()->where('covered_by_id', $home->getKey())
                ->update(array_intersect_key($home->getAttributes(), array_flip(self::FOLLOWS_HOME)) + ['updated_at' => now()]);
        });
    }

    /** @return BelongsTo<Membership, $this> */
    public function coveredBy(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'covered_by_id');
    }

    /** @return HasMany<Membership, $this> */
    public function covers(): HasMany
    {
        return $this->hasMany(Membership::class, 'covered_by_id');
    }

    public function isCovered(): bool
    {
        return $this->covered_by_id !== null;
    }

    /** «Cubierta por la membresía de Dream Green» — why a linked membership has no fee and no renewal of its own. */
    public function coveredLabel(): ?string
    {
        if ($this->covered_by_id === null) {
            return null;
        }
        $home = static::query()->withoutGlobalScopes()->with('location')->find($this->covered_by_id);

        return __('Cubierta por la membresía de :sede', ['sede' => $home?->location->name ?? '—']);
    }

    /** A linked membership is renewed and charged through its home one, never on its own. */
    public function assertNotCovered(): void
    {
        if ($this->covered_by_id !== null) {
            throw new \RuntimeException((string) $this->coveredLabel());
        }
    }

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
        return $query
            ->whereIn('status', [MembershipStatus::ACTIVE->value, MembershipStatus::EXPIRING_SOON->value])
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [now(), MembershipExpiry::windowEnd(now())]);
    }

    /** What is still owed on the fee: the fee less every payment and waiver recorded against it, never negative. */
    public function owedCents(): int
    {
        return max(0, $this->fee_cents->cents - (int) MembershipFeePayment::query()->where('membership_id', $this->id)->sum('amount_cents'));
    }

    /** Has anything been paid (or waived) against the fee? */
    public function hasFeePayments(): bool
    {
        return MembershipFeePayment::query()->where('membership_id', $this->id)->exists();
    }
}
