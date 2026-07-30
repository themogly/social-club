<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ExpenseKind;
use App\Enums\ExpensePaidFrom;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money out. May be per-location (TILL petty cash) or org-wide (OVERHEAD, null
 * location) — so it is org-scoped but NOT location-scoped (which would hide
 * null-location overheads and mis-fill the location on create).
 */
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organisation_id', 'location_id', 'category_id', 'amount_cents', 'paid_from', 'kind',
        'till_session_id', 'recurrence', 'receipt_path', 'recorded_by', 'approved_by',
        'approved_at', 'incurred_on',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => MoneyCast::class,
            'paid_from' => ExpensePaidFrom::class,
            'kind' => ExpenseKind::class,
            'recurrence' => 'array',
            'approved_at' => 'datetime',
            'incurred_on' => 'date',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    /** @return BelongsTo<TillSession, $this> */
    public function tillSession(): BelongsTo
    {
        return $this->belongsTo(TillSession::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
