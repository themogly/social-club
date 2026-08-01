<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ExpenseKind;
use App\Enums\ExpensePaidFrom;
use App\Models\Concerns\BelongsToOrganisation;
use App\Support\Settings;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;

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
        'till_session_id', 'recurrence', 'receipt_path', 'note', 'supplier_id', 'recorded_by',
        'approved_by', 'approved_at', 'incurred_on',
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

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Concrete expenses only — a template (recurrence set) is a schedule, not a real outgoing.
     *
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    public function scopeConcrete(Builder $query): Builder
    {
        return $query->whereNull('recurrence');
    }

    /**
     * THE single definition of a real outgoing for the period (prompt 107) — organisation, NOT soft-deleted,
     * CONCRETE (not a recurrence template), and location-scoped WITH the org-level fold-in (rows with a null
     * location_id — rent, utilities, insurance — belong to the all-locations view). The financial report and
     * the dashboard both call this instead of each describing an outgoing differently, so they agree. Operates
     * on a raw QueryBuilder because both callers aggregate over joins; `$table` lets it prefix a join alias.
     *
     * @param  list<string>  $locationIds
     */
    public static function concreteForPeriod(QueryBuilder $query, string $organisationId, array $locationIds, bool $includesAllLocations, string $table = 'expenses'): QueryBuilder
    {
        return $query
            ->where("{$table}.organisation_id", $organisationId)
            ->whereNull("{$table}.deleted_at")   // raw DB::table() bypasses SoftDeletingScope — re-apply by hand
            ->whereNull("{$table}.recurrence")    // concrete outgoings only — a template is a schedule
            ->where(function (QueryBuilder $q) use ($table, $locationIds, $includesAllLocations): void {
                $q->whereIn("{$table}.location_id", $locationIds);
                if ($includesAllLocations) {
                    $q->orWhereNull("{$table}.location_id"); // org-level overheads live at null location
                }
            });
    }

    /**
     * Above the configurable threshold an unapproved expense still needs a recorded
     * approval (expenses.approve) before it is settled. Below it, none is required.
     * Reads the threshold through the Settings accessor (safe default, never throws).
     */
    public function requiresApproval(): bool
    {
        if ($this->approved_at !== null) {
            return false;
        }

        return $this->amount_cents->cents > (int) Settings::get('expense_approval_threshold_cents', 10000);
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
