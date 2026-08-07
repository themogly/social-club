<?php

namespace App\Models;

use App\Enums\ConsentChannel;
use Database\Factories\ConsentRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per consent per version — consent history is never a scalar column. */
class ConsentRecord extends Model
{
    /** @use HasFactory<ConsentRecordFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'member_id', 'purpose', 'consent_text_version', 'locale', 'channel', 'attested_by',
        'granted_at', 'withdrawn_at', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'channel' => ConsentChannel::class,
        ];
    }

    /**
     * The staff member who RECORDED this consent, when the club captured it rather than the applicant
     * (prompt 210). Null on an applicant tick, which is the only kind that existed before that branch.
     *
     * @return BelongsTo<User, $this>
     */
    public function attestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attested_by');
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
