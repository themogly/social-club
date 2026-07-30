<?php

namespace App\Models;

use App\Enums\MemberDocumentType;
use Database\Factories\MemberDocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemberDocument extends Model
{
    /** @use HasFactory<MemberDocumentFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'member_id', 'type', 'path', 'uploaded_by', 'signed_at', 'version', 'generated_from',
    ];

    protected function casts(): array
    {
        return [
            'type' => MemberDocumentType::class,
            'signed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<DocumentTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'generated_from');
    }

    /** @return HasMany<DocumentAccessLog, $this> */
    public function accessLogs(): HasMany
    {
        return $this->hasMany(DocumentAccessLog::class);
    }
}
