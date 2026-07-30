<?php

namespace App\Models;

use Database\Factories\DocumentAccessLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Who opened whose passport scan" — one query, separate from the general audit trail. */
class DocumentAccessLog extends Model
{
    /** @use HasFactory<DocumentAccessLogFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'actor_id', 'member_document_id', 'viewed_at', 'ip',
    ];

    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<MemberDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(MemberDocument::class, 'member_document_id');
    }
}
