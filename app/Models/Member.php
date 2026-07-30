<?php

namespace App\Models;

use App\Enums\IdDocumentType;
use App\Enums\MemberStatus;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\MemberFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * A socio. Org-wide (people are org-wide; membership is per location). NOT
 * location-scoped, so org-wide member search crosses locations by design.
 *
 * Authenticatable for the SEPARATE `member` guard (prompt 15) — passwordless, so it
 * carries no password column; login happens through a single-use magic-link token.
 * Notifiable + HasPushSubscriptions wire the Web Push channel (prompt 15): the member
 * owns their subscriptions and a per-channel opt-out (`push_opt_outs`).
 */
class Member extends Model implements Authenticatable
{
    /** @use HasFactory<MemberFactory> */
    use AuthenticatableTrait, BelongsToOrganisation, HasFactory, HasPushSubscriptions, HasUlids, Notifiable, SoftDeletes;

    /** The push channels a member can independently opt out of (prompt 15). */
    public const PUSH_CHANNELS = ['low_balance', 'membership_expiring', 'new_announcement', 'event_reminder'];

    protected $fillable = [
        'organisation_id', 'member_no', 'first_name', 'last_name', 'email', 'phone',
        'date_of_birth', 'address', 'photo_path', 'document_type', 'document_number', 'document_hash',
        'document_scan_path', 'status', 'is_therapeutic', 'avalador_member_id',
        'joined_at', 'left_at', 'carencia_ends_at', 'declared_monthly_cg',
        'daily_limit_cg', 'monthly_limit_cg', 'sole_association_declared_at', 'anonymised_at',
        'push_opt_outs',
    ];

    protected static function booted(): void
    {
        // Keep the blind index in sync with the (encrypted) document number.
        static::saving(function (Member $member): void {
            $member->document_hash = self::hashDocument($member->document_number);
        });
    }

    /** Deterministic blind index of a normalised document number, for dedup/uniqueness. */
    public static function hashDocument(?string $number): ?string
    {
        $normalised = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $number));

        return $normalised === '' ? null : hash('sha256', $normalised);
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'document_type' => IdDocumentType::class,
            'document_number' => 'encrypted',        // special-category data, encrypted at rest
            'status' => MemberStatus::class,
            'is_therapeutic' => 'boolean',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'carencia_ends_at' => 'datetime',
            'declared_monthly_cg' => 'integer',
            'daily_limit_cg' => 'integer',
            'monthly_limit_cg' => 'integer',
            'sole_association_declared_at' => 'datetime',
            'anonymised_at' => 'datetime',
            'push_opt_outs' => 'array',
        ];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /**
     * The channel keys this member has switched OFF (empty = opted in to all).
     *
     * @return list<string>
     */
    public function pushOptOuts(): array
    {
        $stored = is_array($this->push_opt_outs) ? $this->push_opt_outs : [];

        return array_values(array_filter($stored, fn ($k): bool => in_array($k, self::PUSH_CHANNELS, true)));
    }

    /** Does the member still want push on this channel? Unknown/absent = yes (opted in by default). */
    public function wantsPush(string $channel): bool
    {
        return ! in_array($channel, $this->pushOptOuts(), true);
    }

    /** @return BelongsTo<Member, $this> */
    public function avalador(): BelongsTo
    {
        return $this->belongsTo(self::class, 'avalador_member_id');
    }

    /** @return HasMany<Member, $this> */
    public function avalados(): HasMany
    {
        return $this->hasMany(self::class, 'avalador_member_id');
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasMany<CheckIn, $this> */
    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class);
    }

    /** @return HasMany<Dispensation, $this> */
    public function dispensations(): HasMany
    {
        return $this->hasMany(Dispensation::class);
    }

    /** @return HasMany<WalletTransaction, $this> */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /** @return HasMany<MemberToken, $this> */
    public function tokens(): HasMany
    {
        return $this->hasMany(MemberToken::class);
    }

    /** @return HasMany<MemberDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(MemberDocument::class);
    }

    /** @return HasMany<ConsentRecord, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(ConsentRecord::class);
    }

    /** @return HasMany<MemberSanction, $this> */
    public function sanctions(): HasMany
    {
        return $this->hasMany(MemberSanction::class);
    }

    /** @return HasMany<MemberDiscount, $this> */
    public function memberDiscounts(): HasMany
    {
        return $this->hasMany(MemberDiscount::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MemberStatus::ACTIVE);
    }
}
