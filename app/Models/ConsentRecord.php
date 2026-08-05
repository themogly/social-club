<?php

namespace App\Models;

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
        'member_id', 'purpose', 'consent_text_version', 'locale', 'granted_at', 'withdrawn_at', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
