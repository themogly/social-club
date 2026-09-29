<?php

namespace App\Actions\Stock;

use App\Actions\RecordAuditLog;
use App\Enums\BatchStatus;
use App\Enums\StockMovementType;
use App\Exceptions\StockCeilingExceededException;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\BelowCost;
use App\Support\BusinessDay;
use App\Support\Settings;
use App\Support\StockCeiling;
use App\Support\Weight;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Record a new batch. Keys off the genetic's unit_type: a WEIGHT genetic takes GRAMS
 * (2 dp) at the edge and stores integer centigrams; a UNIT genetic (preroll/edible)
 * takes whole UNITS. A float grams value is never stored. The opening stock is written
 * as an INTAKE movement (opening balances always enter through the ledger). Exactly one
 * of the cg / units column pairs is populated — the other is set null explicitly.
 *
 * @phpstan-type IntakeData array{grams?: int|float|string, units?: int|string, batch_no?: ?string, label?: ?string, cost_per_gram_cents?: int, price_per_gram_cents?: ?int, price_per_unit_cents?: ?int, price_per_eighth_cents?: ?int, images?: list<string>, acquired_or_harvested_on?: mixed, expires_on?: mixed, lab_report_path?: ?string, notes?: ?string, operator_id?: ?string, override?: bool, override_by?: ?User, override_reason?: ?string}
 */
class IntakeBatch
{
    /**
     * @param  IntakeData  $data
     *
     * @throws DomainException a typed lote number the strain already has, or one over 40 characters
     * @throws StockCeilingExceededException over a BLOCK ceiling with no valid override
     */
    public function handle(Genetic $genetic, Location $location, array $data): Batch
    {
        $part = ['location' => $location] + array_intersect_key($data, array_flip(['grams', 'units']));

        return $this->handleParts($genetic, [$part], $data)->first();
    }

    /**
     * Prompt 303 — one lote arriving SPLIT across locations: one batch per part, all sharing the strain, `batch_no`,
     * `lote_seq`, label, prices, cost, photos and dates, each with its own quantity and INTAKE movement at its own sede.
     * `parent_batch_id` stays null — nothing was transferred; `Batch::lotePartsQuery()` already treats the parts as one
     * lote (a rename and a recall reach every part). All or nothing, in one transaction. A single part is exactly the
     * intake this action always did ({@see self::handle()} delegates here).
     *
     * @param  list<array{location: Location, grams?: int|float|string, units?: int|string}>  $parts
     * @param  IntakeData  $data  everything shared by the parts (their quantities come from `$parts`)
     * @return Collection<int, Batch>
     *
     * @throws DomainException a typed lote number the strain already has, or one over 40 characters
     * @throws StockCeilingExceededException a part over a BLOCK ceiling with no valid override
     */
    public function handleParts(Genetic $genetic, array $parts, array $data): Collection
    {
        $isUnit = $genetic->isUnitType();
        $parts = array_map(fn (array $part): array => [
            'location' => $part['location'],
            'units' => $isUnit ? (int) ($part['units'] ?? 0) : null,
            'cg' => $isUnit ? null : Weight::fromGrams($part['grams'] ?? 0)->centigrams,
        ], $parts);
        if ($parts === []) {
            throw new DomainException(__('Elige al menos una sede.'));
        }

        // Premises stock ceiling (prompt 110), per sede with that sede's own quantity: WARN → proceed; BLOCK → the WHOLE
        // intake is refused unless a limits.override holder authorised it with a reason (one override covers it).
        $breaches = array_values(array_filter(array_map(fn (array $part): ?array => $this->ceilingBreach($genetic, $part['location'], $part['cg'], $part['units']), $parts)));
        $ceilingOverride = $this->authoriseOverride($breaches, $data);

        // The batches + their opening-balance movements are atomic, and new stock entering the premises is audited
        // (prompt 48 — the most traceability-sensitive event in a cannabis club). INSIDE the txn, so a failed audit rolls
        // back the intake (boundary matches CommitStockTake).
        return DB::transaction(function () use ($genetic, $parts, $data, $ceilingOverride, $isUnit): Collection {
            // Prompt 298 — the lote's number within its strain, organisation-wide. The STRAIN row is locked for the rest of
            // the intake, so two intakes of the same strain at once take turns and never share a number (locking the
            // strain rather than its batches also covers a strain's very first batch, when there is nothing else to lock).
            Genetic::query()->withoutGlobalScopes()->whereKey($genetic->getKey())->lockForUpdate()->first();
            $loteSeq = (int) Batch::query()->withoutGlobalScopes()
                ->where('organisation_id', $genetic->organisation_id)->where('genetic_id', $genetic->id)
                ->max('lote_seq') + 1;
            $receivedOn = CarbonImmutable::parse($data['acquired_or_harvested_on'] ?? BusinessDay::today($parts[0]['location']));
            // ONE lote number for the whole intake: the 298 duplicate check runs once, against EXISTING lotes — the parts
            // of this intake are not duplicates of each other.
            $batchNo = $this->loteNumber($genetic, $data['batch_no'] ?? null, $receivedOn, $loteSeq);

            $identity = [
                'batch_no' => $batchNo,
                'lote_seq' => $loteSeq,
                'label' => $data['label'] ?? null, // the club's own name (prompt 282); trimmed/nulled by the model
                'acquired_or_harvested_on' => $data['acquired_or_harvested_on'] ?? $receivedOn->toDateString(),
                'expires_on' => $data['expires_on'] ?? null,
                'cost_per_gram_cents' => $data['cost_per_gram_cents'] ?? 0,
                // The SALE price and photos of this batch (prompt 278). One price column per kind; the eighth is weight only.
                'price_per_gram_cents' => $isUnit ? null : ($data['price_per_gram_cents'] ?? null),
                'price_per_unit_cents' => $isUnit ? ($data['price_per_unit_cents'] ?? null) : null,
                'price_per_eighth_cents' => $isUnit ? null : ($data['price_per_eighth_cents'] ?? null),
                'images' => $data['images'] ?? null,
                'lab_report_path' => $data['lab_report_path'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];
            $batches = collect($parts)->map(fn (array $part): Batch => $this->createPart($genetic, $part, $identity, 'Alta de lote', $data['operator_id'] ?? null));

            $first = $batches->first();
            (new RecordAuditLog)->handle('batch.intake', $first, null, array_filter([
                'batch_no' => $first->batch_no,
                'label' => $first->label,
                'genetic' => $genetic->name,
                'initial_cg' => $batches->count() === 1 ? $parts[0]['cg'] : null,
                'initial_units' => $batches->count() === 1 ? $parts[0]['units'] : null,
                // Prompt 303 — a split lists its parts (sede and quantity each).
                'parts' => $batches->count() > 1 ? $batches->map(fn (Batch $b): array => [
                    'batch_id' => $b->id, 'location_id' => $b->location_id,
                    'initial_cg' => $b->initial_cg?->centigrams, 'initial_units' => $b->initial_units,
                ])->all() : null,
                'cost_per_gram_cents' => (int) ($data['cost_per_gram_cents'] ?? 0),
                // Prompt 295 — received at a sale price below its cost (a warning the owner confirmed, never a block).
                'below_cost' => BelowCost::forBatch($first) !== [] ? true : null,
            ], fn ($v): bool => $v !== null));

            // A ceiling override is its OWN audit row — a reasoned, permissioned breach of a compliance limit.
            if ($ceilingOverride !== null) {
                (new RecordAuditLog)->handle('stock.ceiling.overridden', $first, null, $ceilingOverride);
            }

            return $batches;
        });
    }

    /**
     * Prompt 305 — the SAME lote turning up at more locations, as each is visited and weighed: real stock goes in in
     * stages. A sibling part per location, reusing the lote's identity (strain, `batch_no`, `lote_seq`, label, prices,
     * cost, photos, dates, lab report), `parent_batch_id` null — nothing was transferred, the stock was already there —
     * each with its own quantity and INTAKE movement (the reason, default "Recuento inicial"), the same per-location
     * ceiling check and single override as {@see self::handleParts()}, one `batch.part_added` entry. A location that
     * already holds a part of the lote is refused: there the tool is *Recuento* (251's same-location top-up stays open).
     *
     * @param  list<array{location: Location, grams?: int|float|string, units?: int|string}>  $parts
     * @param  array{reason?: ?string, operator_id?: ?string, override?: bool, override_by?: ?User, override_reason?: ?string}  $data
     * @return Collection<int, Batch>
     *
     * @throws DomainException a location that already holds a part, or no location at all
     * @throws StockCeilingExceededException a part over a BLOCK ceiling with no valid override
     */
    public function addParts(Batch $lote, array $parts, array $data): Collection
    {
        /** @var Genetic $genetic */
        $genetic = Genetic::query()->withoutGlobalScopes()->findOrFail($lote->genetic_id);
        $isUnit = $genetic->isUnitType();
        $parts = array_map(fn (array $part): array => [
            'location' => $part['location'],
            'units' => $isUnit ? (int) ($part['units'] ?? 0) : null,
            'cg' => $isUnit ? null : Weight::fromGrams($part['grams'] ?? 0)->centigrams,
        ], $parts);
        if ($parts === []) {
            throw new DomainException(__('Elige al menos una sede.'));
        }

        $held = $lote->lotePartsQuery()->pluck('location_id')->all();
        foreach ($parts as $part) {
            if (in_array($part['location']->id, $held, true)) {
                throw new DomainException(__('Este lote ya tiene existencias en :sede; usa «Recuento».', ['sede' => $part['location']->name]));
            }
        }

        $breaches = array_values(array_filter(array_map(fn (array $part): ?array => $this->ceilingBreach($genetic, $part['location'], $part['cg'], $part['units']), $parts)));
        $ceilingOverride = $this->authoriseOverride($breaches, $data);

        return DB::transaction(function () use ($genetic, $lote, $parts, $data, $ceilingOverride): Collection {
            $identity = $lote->only([
                'batch_no', 'lote_seq', 'label', 'acquired_or_harvested_on', 'expires_on', 'cost_per_gram_cents',
                'price_per_gram_cents', 'price_per_unit_cents', 'price_per_eighth_cents', 'images', 'lab_report_path', 'notes',
            ]);
            $reason = trim((string) ($data['reason'] ?? '')) ?: __('Recuento inicial');
            $batches = collect($parts)->map(fn (array $part): Batch => $this->createPart($genetic, $part, $identity, $reason, $data['operator_id'] ?? null));

            (new RecordAuditLog)->handle('batch.part_added', $lote, null, [
                'batch_no' => $lote->batch_no,
                'reason' => $reason,
                'parts' => $batches->map(fn (Batch $b): array => [
                    'batch_id' => $b->id, 'location_id' => $b->location_id,
                    'initial_cg' => $b->initial_cg?->centigrams, 'initial_units' => $b->initial_units,
                ])->all(),
            ]);
            if ($ceilingOverride !== null) {
                (new RecordAuditLog)->handle('stock.ceiling.overridden', $batches->first(), null, $ceilingOverride);
            }

            return $batches;
        });
    }

    /**
     * One part of a lote at one location, and its opening INTAKE movement — shared by a new intake ({@see
     * self::handleParts()}) and a part added later ({@see self::addParts()}).
     *
     * @param  array{location: Location, cg: ?int, units: ?int}  $part
     * @param  array<string, mixed>  $identity  the lote's shared columns (batch_no, lote_seq, label, prices, dates…)
     */
    private function createPart(Genetic $genetic, array $part, array $identity, string $reason, ?string $operatorId): Batch
    {
        $batch = Batch::create(array_merge($identity, [
            'organisation_id' => $genetic->organisation_id,
            'genetic_id' => $genetic->id,
            'location_id' => $part['location']->id,
            'initial_cg' => $part['cg'],
            'remaining_cg' => $part['cg'],
            'initial_units' => $part['units'],
            'remaining_units' => $part['units'],
            'status' => BatchStatus::OPEN,
        ]));

        StockMovement::create([
            'organisation_id' => $batch->organisation_id,
            'location_id' => $part['location']->id,
            'stockable_type' => Batch::class,
            'stockable_id' => $batch->id,
            'qty_cg' => $part['cg'],
            'qty_units' => $part['units'],
            'type' => StockMovementType::INTAKE,
            'reason' => $reason,
            'operator_id' => $operatorId ?? Auth::id(),
            'reference' => $batch->batch_no,
        ]);

        return $batch;
    }

    /**
     * The lote number (prompt 298). The grow's or supplier's own, when typed — trimmed, and refused if that strain already
     * has a lote with it (whether a repeat delivery tops up a lote is still 251's open question). Otherwise a readable
     * one, generated once and never changed: the strain's first three letters, ASCII, padded with X, the intake date and
     * the lote's number within the strain — `AMN-260912-3` — with `-2`, `-3`… in the unlikely case it already exists.
     */
    private function loteNumber(Genetic $genetic, ?string $typed, CarbonImmutable $receivedOn, int $loteSeq): string
    {
        $typed = trim((string) $typed);
        $taken = fn (string $number, bool $sameStrain): bool => Batch::query()->withoutGlobalScopes()
            ->where('organisation_id', $genetic->organisation_id)
            ->when($sameStrain, fn ($query) => $query->where('genetic_id', $genetic->id))
            ->where('batch_no', $number)->exists();

        if ($typed !== '') {
            if (mb_strlen($typed) > 40) {
                throw new DomainException(__('El número de lote no puede tener más de 40 caracteres.'));
            }
            if ($taken($typed, true)) {
                throw new DomainException(__('Ya existe un lote con ese número para esta variedad.'));
            }

            return $typed;
        }

        $code = str_pad(substr((string) preg_replace('/[^A-Z]/', '', strtoupper(Str::ascii($genetic->name))), 0, 3), 3, 'X');
        $base = $code.'-'.$receivedOn->format('ymd').'-'.$loteSeq;
        $number = $base;
        for ($n = 2; $taken($number, false); $n++) {
            $number = $base.'-'.$n;
        }

        return $number;
    }

    /**
     * Would this part push its premises over the legal stock ceiling, with the club enforcing it as BLOCK? The breach,
     * or null (within the ceiling, WARN mode, or the grow / central store, which has no per-location ceiling — stock
     * ENTERING a sede is checked there, prompt 277).
     *
     * @return array{location_id: string, location: string, projected_on_site_cg: int, ceiling_cg: int}|null
     */
    private function ceilingBreach(Genetic $genetic, Location $location, ?int $cg, ?int $units): ?array
    {
        $incomingCg = $cg ?? (($units ?? 0) * (int) $genetic->grams_per_unit_cg);
        if ($incomingCg <= 0 || $location->isStore()) {
            return null;
        }

        $ceiling = StockCeiling::forLocation($location);
        $projected = $ceiling['on_site_cg'] + $incomingCg;
        if ($projected <= $ceiling['ceiling_cg'] || Settings::enforcement('stock', 'ceiling') === 'WARN') {
            return null; // within the ceiling, or allowed — surfaced by the dashboard indicator and the intake form
        }

        return ['location_id' => (string) $location->id, 'location' => (string) $location->name, 'projected_on_site_cg' => $projected, 'ceiling_cg' => $ceiling['ceiling_cg']];
    }

    /**
     * BLOCK breaches refuse the whole intake unless a limits.override holder authorised it with a reason — the same
     * contract as a member limit. Returns the override metadata to audit once (null when no override was needed). A
     * single-sede intake audits the same fields it always did; a split lists each sede that breached.
     *
     * @param  list<array{location_id: string, location: string, projected_on_site_cg: int, ceiling_cg: int}>  $breaches
     * @param  IntakeData  $data
     * @return array<string, mixed>|null
     */
    private function authoriseOverride(array $breaches, array $data): ?array
    {
        if ($breaches === []) {
            return null;
        }

        $by = $data['override_by'] ?? null;
        $reason = trim((string) ($data['override_reason'] ?? ''));

        if (empty($data['override']) || ! $by instanceof User) {
            throw new StockCeilingExceededException(count($breaches) === 1
                ? __('Esta entrada superaría el límite legal de stock de :sede.', ['sede' => $breaches[0]['location']])
                : __('Esta entrada superaría el límite legal de stock de :sedes.', ['sedes' => implode(', ', array_column($breaches, 'location'))]));
        }
        if (! $by->can('limits.override')) {
            throw new AuthorizationException('Overriding the stock ceiling requires the limits.override permission.');
        }
        if ($reason === '') {
            throw new StockCeilingExceededException('A stock-ceiling override requires a reason.');
        }

        $override = ['override_by' => $by->id, 'reason' => $reason];

        return count($breaches) === 1
            ? $override + ['projected_on_site_cg' => $breaches[0]['projected_on_site_cg'], 'ceiling_cg' => $breaches[0]['ceiling_cg'], 'breaches' => $breaches]
            : $override + ['breaches' => $breaches];
    }
}
