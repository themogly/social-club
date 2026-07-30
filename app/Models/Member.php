<?php

namespace App\Models;

use App\Enums\IdDocumentType;
use App\Enums\MemberStatus;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A socio. Org-wide (people are org-wide; membership is per location). NOT
 * location-scoped, so org-wide member search crosses locations by design.
 */
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organisation_id', 'member_no', 'first_name', 'last_name', 'email', 'phone',
        'date_of_birth', 'address', 'photo_path', 'document_type', 'document_number', 'document_hash',
        'document_scan_path', 'status', 'is_therapeutic', 'avalador_member_id',
        'joined_at', 'left_at', 'carencia_ends_at', 'declared_monthly_cg',
        'daily_limit_cg', 'monthly_limit_cg', 'sole_association_declared_at', 'anonymised_at',
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
        ];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
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
