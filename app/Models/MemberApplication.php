<?php

namespace App\Models;

use App\Casts\NormalisedEmail;
use App\Enums\ApplicationStatus;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\MemberApplicationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberApplication extends Model
{
    /** @use HasFactory<MemberApplicationFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids;

    protected $fillable = [
        'organisation_id', 'location_id', 'invite_token_hash', 'invite_token', 'invited_by', 'applicant_email', 'applicant_reference',
        'invite_expires_at', 'opened_at', 'submitted_at', 'revoked_at', 'payload', 'status',
        'reject_reason', 'reviewed_by', 'reviewed_at', 'resulting_member_id',
    ];

    protected function casts(): array
    {
        return [
            'applicant_email' => NormalisedEmail::class,   // invite target — normalised at the boundary (prompt 146)
            'payload' => 'array',
            'status' => ApplicationStatus::class,
            'reviewed_at' => 'datetime',
            // The raw invite token, encrypted at rest, so the link can be RE-COPIED from the
            // Invitations view. Verification still uses the hash column — this is display-only.
            'invite_token' => 'encrypted',
            'invite_expires_at' => 'datetime',
            'opened_at' => 'datetime',
            'submitted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** not_opened → started → submitted, from the lifecycle timestamps. */
    public function inviteStatus(): string
    {
        return match (true) {
            $this->submitted_at !== null => 'submitted',
            $this->opened_at !== null => 'started',
            default => 'not_opened',
        };
    }

    public function isInviteExpired(): bool
    {
        return $this->invite_expires_at !== null && $this->invite_expires_at->isPast();
    }

    public function isInviteRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Openable = still pending review, not revoked, not expired. */
    public function isInviteLive(): bool
    {
        return $this->status === ApplicationStatus::PENDING && ! $this->isInviteRevoked() && ! $this->isInviteExpired();
    }

    /** The shareable link, rebuilt from the encrypted token (null once the token is gone). */
    public function inviteUrl(): ?string
    {
        return $this->invite_token !== null
            ? route('socio.application', ['token' => $this->invite_token])
            : null;
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'resulting_member_id');
    }
}
