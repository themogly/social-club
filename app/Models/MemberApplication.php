<?php

namespace App\Models;

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
        'organisation_id', 'location_id', 'invite_token_hash', 'payload', 'status',
        'reject_reason', 'reviewed_by', 'reviewed_at', 'resulting_member_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => ApplicationStatus::class,
            'reviewed_at' => 'datetime',
        ];
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
