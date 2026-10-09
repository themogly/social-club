<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\CashPot;
use App\Enums\TillSessionStatus;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\ScopedToLocation;
use App\Support\Period;
use App\Support\Settings;
use Database\Factories\TillSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TillSession extends Model
{
    /** @use HasFactory<TillSessionFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, ScopedToLocation;

    protected $fillable = [
        'organisation_id', 'location_id', 'terminal', 'opened_by', 'opened_at', 'float_cents',
        'closed_by', 'closed_at', 'counted_cents', 'expected_cents', 'variance_cents', 'status', 'notes',
        'variance_tolerance_cents', // prompt 366 — the tolerance in force at the close
        // Prompt 349 — the bar and fees pots (the float/counted/expected/variance above are the dispensary pot's when on).
        'separate_pots',
        'bar_opening_cents', 'bar_counted_cents', 'bar_expected_cents', 'bar_variance_cents',
        'fees_opening_cents', 'fees_counted_cents', 'fees_expected_cents', 'fees_variance_cents',
        // Prompt 373 — the session's own boxes (snapshotted at opening) and the edibles box.
        'own_boxes',
        'edibles_opening_cents', 'edibles_counted_cents', 'edibles_expected_cents', 'edibles_variance_cents',
        // Prompt 378 — the shop box, and where the shop's money went when it had none ('with_bar' | 'till' | 'own').
        'shop_box', 'shop_opening_cents', 'shop_counted_cents', 'shop_expected_cents', 'shop_variance_cents',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'float_cents' => MoneyCast::class,
            'counted_cents' => MoneyCast::class,
            'expected_cents' => MoneyCast::class,
            'variance_cents' => MoneyCast::class,
            'variance_tolerance_cents' => 'integer',
            'status' => TillSessionStatus::class,
            'separate_pots' => 'boolean',
            'bar_opening_cents' => MoneyCast::class,
            'bar_counted_cents' => MoneyCast::class,
            'bar_expected_cents' => MoneyCast::class,
            'bar_variance_cents' => MoneyCast::class,
            'fees_opening_cents' => MoneyCast::class,
            'fees_counted_cents' => MoneyCast::class,
            'fees_expected_cents' => MoneyCast::class,
            'fees_variance_cents' => MoneyCast::class,
            'own_boxes' => 'array',
            'edibles_opening_cents' => MoneyCast::class,
            'edibles_counted_cents' => MoneyCast::class,
            'edibles_expected_cents' => MoneyCast::class,
            'edibles_variance_cents' => MoneyCast::class,
            'shop_opening_cents' => MoneyCast::class,
            'shop_counted_cents' => MoneyCast::class,
            'shop_expected_cents' => MoneyCast::class,
            'shop_variance_cents' => MoneyCast::class,
        ];
    }

    /**
     * The shift currently holding this drawer, if any (prompt 186).
     *
     * A session that is OPEN with no OPEN shift is Toast's middle state: the drawer is between people, so
     * nothing may be charged to it. In practice a handover is atomic — the incoming operator identifies
     * before the outgoing one is released — so the window does not arise in the ordinary flow. This exists
     * because the gate must be a real gate rather than a picture of one: any path that leaves a drawer
     * unheld refuses money, whether or not the UI can produce it.
     */
    public function currentShift(): ?TillShift
    {
        return TillShift::query()->withoutGlobalScopes()
            ->where('till_session_id', $this->id)->open()->latest('opened_at')->first();
    }

    public function hasOpenShift(): bool
    {
        return $this->currentShift() !== null;
    }

    /**
     * Is this drawer BETWEEN people — Toast's middle state?
     *
     * Deliberately narrower than "has no open shift". A session that never had a shift at all is not
     * between people: it is a session from before shifts existed, and the migration backfills every one
     * that was open at deploy time. Refusing money on those would break a live drawer for no safety gain,
     * because nobody handed anything over. What must be refused is a drawer that HAD a holder and does not
     * now — that is the state where a charge would belong to nobody.
     */
    public function isBetweenShifts(): bool
    {
        return ! $this->hasOpenShift()
            && TillShift::query()->withoutGlobalScopes()->where('till_session_id', $this->id)->exists();
    }

    /**
     * Every shift this drawer passed through, oldest first — the day's attribution trail.
     *
     * @return HasMany<TillShift, $this>
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(TillShift::class)->orderBy('opened_at');
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return HasMany<CashMovement, $this> */
    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    /** @return HasMany<Dispensation, $this> */
    public function dispensations(): HasMany
    {
        return $this->hasMany(Dispensation::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', TillSessionStatus::OPEN);
    }

    /**
     * Prompt 373 — does this session keep that kind of money in a box of its own (snapshotted at opening)? The dispensary is
     * the till, never a box. Every reader of 349's `separate_pots` reads this now.
     */
    public function hasOwnBox(CashPot $pot): bool
    {
        return $pot !== CashPot::DISPENSARY && in_array($pot->value, (array) ($this->own_boxes ?? []), true);
    }

    /**
     * Prompt 378 — where this session put a kind of money: its own box when it has one; the shop, when it has none, wherever
     * the bar's money goes («Con la barra», the default, and every session from before 378) unless its choice was the till;
     * anything else, the till. TillSummary, the counter's «Pon …» line and the day sheet all ask this.
     */
    public function cashPotFor(CashPot $kind): CashPot
    {
        if ($kind !== CashPot::DISPENSARY && $this->hasOwnBox($kind)) {
            return $kind;
        }
        if ($kind === CashPot::SHOP && ($this->shop_box ?? 'with_bar') === 'with_bar') {
            return $this->cashPotFor(CashPot::BAR);
        }

        return CashPot::DISPENSARY;
    }

    /** @return list<CashPot> the session's own boxes, in {@see CashPot::optional()} order */
    public function ownBoxes(): array
    {
        return array_values(array_filter(CashPot::optional(), fn (CashPot $pot): bool => $this->hasOwnBox($pot)));
    }

    /**
     * Prompt 366 — the largest difference of the close, across the drawer (or dispensary pot) and whichever of the bar and
     * fees pots were counted. Null while the session is open.
     */
    public function worstVarianceCents(): ?int
    {
        if ($this->status !== TillSessionStatus::CLOSED) {
            return null;
        }
        $columns = ['variance_cents', ...array_map(fn (CashPot $pot): string => $pot->column().'_variance_cents', $this->ownBoxes())];
        $differences = array_map(fn (string $column): int => abs((int) $this->getRawOriginal($column)),
            array_filter($columns, fn (string $column): bool => $this->getRawOriginal($column) !== null));

        return $differences === [] ? 0 : max($differences);
    }

    /** The tolerance in force when it closed; an older close (before 366 kept it) reads the sede's current setting. */
    public function varianceToleranceCents(): int
    {
        return $this->variance_tolerance_cents ?? (int) Settings::get('arqueo_variance_tolerance_cents', 500, $this->location_id);
    }

    public function closedBeyondTolerance(): bool
    {
        return ($this->worstVarianceCents() ?? 0) > $this->varianceToleranceCents();
    }

    /** Beyond the tolerance and nobody said why — what the owner's report flags «Sin explicar». */
    public function closedUnexplained(): bool
    {
        return $this->closedBeyondTolerance() && blank($this->notes);
    }

    /**
     * «Cierres con diferencia sin explicar: N esta semana» — the sede's sessions opened this (business) week, the same
     * window Informes → Cajas shows on «Esta semana», so the line and the report it links to agree.
     */
    public static function unexplainedClosesThisWeek(Location $sede): int
    {
        $week = Period::thisWeek($sede);

        return static::query()->withoutGlobalScopes()
            ->where('location_id', $sede->id)->where('status', TillSessionStatus::CLOSED)
            ->where('opened_at', '>=', $week->start)->where('opened_at', '<', $week->end)
            ->get()->filter(fn (TillSession $session): bool => $session->closedUnexplained())->count();
    }
}
