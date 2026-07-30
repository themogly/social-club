<?php

namespace App\Models;

use App\Enums\SanctionType;
use Database\Factories\MemberSanctionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberSanction extends Model
{
    /** @use HasFactory<MemberSanctionFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'member_id', 'type', 'reason', 'from_date', 'until_date', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => SanctionType::class,
            'from_date' => 'date',
            'until_date' => 'date',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
