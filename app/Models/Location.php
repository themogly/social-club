<?php

namespace App\Models;

use App\Enums\LocationKind;
use App\Enums\Role;
use App\Models\Concerns\BelongsToOrganisation;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * A premises — the operational scope. Its timezone + business_day_cutoff drive
 * every daily aggregate and daily limit via BusinessDay.
 */
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use BelongsToOrganisation, HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'organisation_id', 'name', 'kind', 'address', 'capacity', 'timezone',
        'business_day_cutoff', 'opening_time', 'closing_time', 'accent', 'active',
        'terminals',
    ];

    /**
     * Prompt 283 — a store has no opening hours and no counter to theme, so those three are always null on one. The
     * form hides them; a hidden Filament field is left out of the save (not blanked), so an edit alone would keep a stale
     * value — the rule lives here, where every write passes.
     */
    protected static function booted(): void
    {
        static::saving(function (Location $location): void {
            if ($location->isStore()) {
                $location->opening_time = null;
                $location->closing_time = null;
                $location->accent = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => LocationKind::class,
            'capacity' => 'integer',
            'active' => 'boolean',
            'terminals' => 'array',
        ];
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * The configured till terminals for this location (prompt 84) — the picker's options.
     *
     * @return list<string>
     */
    public function terminalNames(): array
    {
        return array_values(array_filter((array) ($this->terminals ?? []), 'is_string'));
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** The grow / central store (prompt 277): holds stock, has no counter. */
    public function isStore(): bool
    {
        return $this->kind === LocationKind::ALMACEN;
    }

    /**
     * Premises with a counter — everything that lists sedes for counter work (the sede picker, memberships, tills,
     * the per-sede ceiling) reads this, so the store can never behave like a counter (prompt 277).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSedes(Builder $query): Builder
    {
        return $query->where('kind', LocationKind::SEDE->value);
    }

    /**
     * The sedes a person may WRITE to from the panel (prompt 273): every sede for an owner, otherwise the ones they are
     * assigned to. Every sede dropdown on a panel form reads this, so a manager cannot record prices, batches, wallet
     * movements or expenses at a sede they do not work at — the object-ownership rule `LocationPolicy` applies (270).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAssignableTo(Builder $query, ?User $user): Builder
    {
        if ($user !== null && $user->hasRole(Role::OWNER->value)) {
            return $query;
        }

        return $query->whereIn('locations.id', $user !== null ? $user->locations()->pluck('locations.id')->all() : []);
    }

    /**
     * {@see self::scopeAssignableTo()} for the signed-in user, applied to a relationship Select's query.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function limitToAssignable(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user instanceof User && $user->hasRole(Role::OWNER->value)) {
            return $query;
        }

        return $query->whereIn('locations.id', $user instanceof User ? $user->locations()->pluck('locations.id')->all() : []);
    }

    /**
     * {@see self::scopeAssignableTo()} for the signed-in user, as select options.
     *
     * @return array<string, string>
     */
    public static function assignableOptions(bool $includeStores = false): array
    {
        $user = Auth::user();

        // The store (277) is offered only where stock is received or moved; never for members, tills or money.
        return static::query()->assignableTo($user instanceof User ? $user : null)
            ->when(! $includeStores, fn (Builder $query): Builder => $query->sedes())
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Derived (prompt 93) — a location with NO active price is a counter that can sell nothing. Never
     * stored; queried live over the per-location GeneticPrice rows.
     */
    public function hasActivePrices(): bool
    {
        return GeneticPrice::query()->withoutGlobalScopes()
            ->where('location_id', $this->id)->where('active', true)->exists();
    }
}
