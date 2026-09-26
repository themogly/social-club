<?php

namespace App\Actions\Dispensing;

use App\Actions\Pricing\ResolvePrice;
use App\Actions\RecordAuditLog;
use App\Actions\Stock\AllocateFromBatches;
use App\Actions\Stock\RecordStockMovement;
use App\Actions\Stock\SelectBatch;
use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\StockMovementType;
use App\Enums\TillSessionStatus;
use App\Enums\WalletTransactionType;
use App\Exceptions\DispensationBlockedException;
use App\Exceptions\LimitExceededException;
use App\Exceptions\TillClosedException;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\TillSession;
use App\Models\User;
use App\Support\LimitSnapshot;
use App\Support\MemberEligibility;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Commit a dispensation. THE compliance boundary: membership, carencia and the
 * daily/monthly limits are checked INSIDE the same DB transaction as the stock
 * movement, with the member row locked FOR UPDATE — so two tills cannot each pass
 * the check and jointly breach. Limit breaches hard-block by default; a
 * `limits.override` holder may force one with a reason (audited). Enforcement per
 * rule comes from the counter enforcement matrix (Settings). Prices resolve to the
 * base per-gram price here; prompt 08 layers tier/discount resolution on top.
 *
 * For a WEIGHT genetic a line carries grams_cg; for a UNIT genetic (preroll/edible) it
 * carries units, and grams_cg is COMPUTED (units × genetic.grams_per_unit_cg) and stored
 * on every line — so limits, ceilings and reports keep reading grams_cg with zero change.
 *
 * Prompt 250 — `batch_id` is NULLABLE. A null batch means AUTOMATIC mode: the operator did not choose a lote,
 * and {@see AllocateFromBatches} draws the quantity from the sede's batches oldest-first
 * inside this transaction, storing one dispensation_lines row per batch drawn (priced once, split per lote). A
 * non-null batch is MANUAL mode: one movement, one row, refused if it does not fit — exactly as before.
 *
 * @phpstan-type Line array{genetic_id: string, batch_id?: ?string, grams_cg?: int, units?: int}
 * @phpstan-type NormalisedLine array{genetic_id: string, batch_id: ?string, grams_cg: int, units: ?int}
 * @phpstan-type CommitOptions array{operator_id?: ?string, till_session_id?: ?string, cash_cents?: int, wallet_cents?: int, signature_path?: ?string, idempotency_key?: ?string, reversal_of_id?: ?string, override?: bool, override_by?: ?User, override_reason?: ?string, price_override_cents?: ?int, price_override_reason?: ?string, price_override_by?: ?User, at?: ?\DateTimeInterface}
 */
class CommitDispensation
{
    /**
     * @param  list<Line>  $lines
     * @param  CommitOptions  $options
     */
    public function handle(Member $member, Location $location, array $lines, array $options = []): Dispensation
    {
        if ($lines === []) {
            throw new RuntimeException('A dispensation needs at least one line.');
        }

        $idempotencyKey = $options['idempotency_key'] ?? null;

        // The pre-check inside handles the common non-concurrent retry cheaply. Under TRUE concurrency both
        // requests can miss it and both insert; the unique index on idempotency_key is the real guarantee, and
        // the request that LOSES that race raises a UniqueConstraintViolationException here instead of taking the
        // pre-check's return path. Catch it, and do what the pre-check would have (prompt 123).
        try {
            return DB::transaction(function () use ($member, $location, $lines, $options): Dispensation {
                // Idempotency FAST PATH: never double-commit the same basket on a plain (non-concurrent) retry.
                $existing = $this->findByIdempotencyKey($options['idempotency_key'] ?? null);
                if ($existing !== null) {
                    return $existing;
                }

                // A dispensation may only attach to an OPEN till session.
                $tillSessionId = $options['till_session_id'] ?? null;
                if ($tillSessionId !== null) {
                    $till = TillSession::withoutGlobalScopes()->find($tillSessionId);
                    // Prompt 186 — a drawer nobody holds takes no money. A session can be OPEN with no OPEN
                    // shift (Toast's middle state), and that is refused SERVER-SIDE rather than by hiding a
                    // button: a cash variance is attributable to whoever held the drawer, so a charge landing
                    // while nobody does would belong to nobody, which is the defect this whole branch exists
                    // to remove.
                    if ($till !== null && $till->status === TillSessionStatus::OPEN && $till->isBetweenShifts()) {
                        throw new TillClosedException('The till is between shifts — take it over before charging to it.');
                    }

                    if ($till === null || $till->status !== TillSessionStatus::OPEN) {
                        throw new TillClosedException('The dispensation must attach to an open till session.');
                    }
                }

                // Serialise per member so concurrent tills cannot jointly breach the limit.
                Member::withoutGlobalScopes()->whereKey($member->id)->lockForUpdate()->first();

                $this->assertEligible($member, $location, $options);

                // Normalise every line to a stored grams_cg (computed for UNIT lines) BEFORE the
                // limit check, so the daily/monthly ceiling arithmetic is fed the same figure it
                // always was — ResolveMemberLimits itself is untouched.
                $lines = $this->normalise($lines);

                $totalGrams = array_sum(array_map(fn (array $line) => (int) $line['grams_cg'], $lines));
                $snapshot = (new ResolveMemberLimits)->handle($member, $location, $options['at'] ?? null);
                $this->assertWithinLimits($snapshot, $totalGrams, $member, $location, $options);

                [$total, $lineData] = $this->buildLines($member, $lines, $location, $options);

                // Price override (prompt 64): a permissioned, reasoned adjustment to what the member pays for
                // the whole contribution — comping defective product, or a €0 give-away. It changes only the
                // CHARGED total; limits/eligibility (already enforced above) are UNTOUCHED. The resolved figure
                // is kept in original_total_cents so the override is reconstructable, attributed and reportable.
                // Zero is valid and goes through the identical permission + reason + audit path.
                $originalTotal = null;
                $overrideReason = null;
                $overrideBy = null;
                if (($options['price_override_cents'] ?? null) !== null) {
                    $overrideBy = $options['price_override_by'] ?? null;
                    if (! ($overrideBy instanceof User) || ! $overrideBy->can('dispensation.price.override')) {
                        throw new AuthorizationException('Overriding the dispensation price requires the dispensation.price.override permission.');
                    }
                    $overrideReason = trim((string) ($options['price_override_reason'] ?? ''));
                    if ($overrideReason === '') {
                        throw new RuntimeException('A price override requires a reason.');
                    }
                    $originalTotal = $total;
                    $total = max(0, min((int) $options['price_override_cents'], $total)); // reduce only: 0 (free) .. resolved
                }

                $cash = $options['cash_cents'] ?? $total;
                $wallet = $options['wallet_cents'] ?? 0;

                $dispensation = Dispensation::create([
                    'organisation_id' => $member->organisation_id,
                    'member_id' => $member->id,
                    'location_id' => $location->id,
                    'operator_id' => $options['operator_id'] ?? Auth::id(),
                    'till_session_id' => $options['till_session_id'] ?? null,
                    'total_cents' => $total,
                    'original_total_cents' => $originalTotal,
                    'price_override_reason' => $overrideReason,
                    'price_override_by' => $overrideBy?->id,
                    'cash_cents' => $cash,
                    'wallet_cents' => $wallet,
                    'status' => DispensationStatus::COMPLETED,
                    'reversal_of_id' => $options['reversal_of_id'] ?? null,
                    'signature_path' => $options['signature_path'] ?? null,
                    'idempotency_key' => $options['idempotency_key'] ?? null,
                    'dispensed_at' => $options['at'] ?? now(),
                ]);

                foreach ($lineData as $line) {
                    $dispensation->lines()->create($line);
                }

                // Audit the override — resolved vs overridden, reason, authoriser, operator, member (prompt 48
                // placement: inside the txn, so a failed audit rolls the whole dispensation back).
                if ($originalTotal !== null) {
                    (new RecordAuditLog)->handle('dispensation.price.override', $member,
                        ['total_cents' => $originalTotal],
                        [
                            'total_cents' => $total,
                            'reason' => $overrideReason,
                            'authorised_by' => $overrideBy->id, // non-null here: set together with $originalTotal
                            'operator_id' => $options['operator_id'] ?? Auth::id(),
                        ]);
                }

                if ($wallet > 0) {
                    (new RecordWalletTransaction)->handle($member, $location, -$wallet, WalletTransactionType::CONTRIBUTION, [
                        'source' => $dispensation,
                        'operator_id' => $options['operator_id'] ?? Auth::id(),
                        'till_session_id' => $options['till_session_id'] ?? null,
                        'reason' => 'Aportación por dispensación',
                        'allow_debt' => true, // debt policy on the wallet spend is enforced separately
                    ]);
                }

                return $dispensation;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Re-read on the now-HEALTHY connection — the doomed transaction has already rolled back — and
            // return the winner's row, so the caller cannot tell it lost (the whole point of an idempotency
            // key). A row exists for this key ONLY when the violation WAS the idempotency collision; any other
            // unique violation finds nothing here and rethrows as the real error it is.
            if ($idempotencyKey !== null) {
                $existing = Dispensation::withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    /**
     * The idempotency FAST-PATH lookup (the pre-check only). Overridable so a test can force a pre-check MISS
     * and drive the true-race path; the catch does its OWN inline re-read, so an override never masks it.
     */
    protected function findByIdempotencyKey(?string $key): ?Dispensation
    {
        return $key !== null ? Dispensation::withoutGlobalScopes()->where('idempotency_key', $key)->first() : null;
    }

    /**
     * @param  CommitOptions  $options
     */
    private function assertEligible(Member $member, Location $location, array $options): void
    {
        $hasActiveMembership = $member->memberships()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', MembershipStatus::ACTIVE->value)
            ->exists();

        if (! $hasActiveMembership && Settings::enforcement('counter', 'membership') !== 'WARN') {
            throw new DispensationBlockedException(__('Sin membresía activa en esta sede.'));
        }

        if (! MemberEligibility::carenciaPassed($member) && Settings::enforcement('counter', 'carencia') !== 'WARN') {
            throw new DispensationBlockedException(__('En periodo de carencia (puede entrar, no puede dispensarse).'));
        }

        // Photo-on-file (prompt 157). OFF: no check. WARN: proceed (the counter shows the warning, not a
        // block). OVERRIDE: blocked unless a manager forces it with a reason — the SAME override path a limit
        // breach uses, so a club mid-migration with paper members is never wedged. photoEnforcement() reads
        // OFF-safe: a legacy enforcement matrix with no `photo` key resolves to OFF, never a surprise BLOCK.
        if (Settings::photoEnforcement('counter') === 'OVERRIDE' && blank($member->photo_path)) {
            $this->authorisePhotoOverride($member, $location, $options);
        }
    }

    /**
     * @param  CommitOptions  $options
     */
    private function authorisePhotoOverride(Member $member, Location $location, array $options): void
    {
        $overrideBy = $options['override_by'] ?? null;

        if (! ($options['override'] ?? false) || $overrideBy === null) {
            throw new DispensationBlockedException(__('Sin foto en ficha: se requiere la autorización de un responsable.'));
        }

        if (! $overrideBy->can('limits.override')) {
            throw new AuthorizationException('Overriding a missing member photo requires the limits.override permission.');
        }

        (new RecordAuditLog)->handle('dispensation.photo.override', $member, null, [
            'location_id' => $location->id,
            'authorised_by' => $overrideBy->id,
            'reason' => $options['override_reason'] ?? null,
        ]);
    }

    /**
     * @param  CommitOptions  $options
     */
    private function assertWithinLimits(LimitSnapshot $snapshot, int $grams, Member $member, Location $location, array $options): void
    {
        foreach (['daily' => $snapshot->wouldBreachDaily($grams), 'monthly' => $snapshot->wouldBreachMonthly($grams)] as $rule => $breached) {
            if (! $breached) {
                continue;
            }

            if (Settings::enforcement('counter', "{$rule}_limit") === 'WARN') {
                continue;
            }

            $this->authoriseOverride($rule, $grams, $member, $location, $options);
        }
    }

    /**
     * @param  CommitOptions  $options
     */
    private function authoriseOverride(string $rule, int $grams, Member $member, Location $location, array $options): void
    {
        $overrideBy = $options['override_by'] ?? null;

        if (! ($options['override'] ?? false) || $overrideBy === null) {
            throw new LimitExceededException("Dispensation would breach the {$rule} limit for this member.");
        }

        if (! $overrideBy->can('limits.override')) {
            throw new AuthorizationException('Overriding a consumption limit requires the limits.override permission.');
        }

        (new RecordAuditLog)->handle('dispensation.limit.override', $member, null, [
            'rule' => $rule,
            'location_id' => $location->id,
            'grams_cg_attempted' => $grams,
            'authorised_by' => $overrideBy->id,
            'reason' => $options['override_reason'] ?? null,
        ]);
    }

    /**
     * Resolve each line's stored grams_cg once, up front. A UNIT line's grams_cg is
     * COMPUTED from its unit count × the genetic's grams_per_unit_cg; a WEIGHT line's is
     * the entered grams. Every line downstream carries a real grams_cg.
     *
     * @param  list<Line>  $lines
     * @return list<NormalisedLine>
     */
    private function normalise(array $lines): array
    {
        return array_map(function (array $line): array {
            $genetic = Genetic::withoutGlobalScopes()->findOrFail($line['genetic_id']);

            if ($genetic->isUnitType()) {
                $units = (int) ($line['units'] ?? 0);
                if ($units <= 0) {
                    throw new RuntimeException('A unit dispensation line needs a positive unit count.');
                }

                return [
                    'genetic_id' => $genetic->id,
                    'batch_id' => isset($line['batch_id']) ? (string) $line['batch_id'] : null,
                    'grams_cg' => $units * (int) $genetic->grams_per_unit_cg,
                    'units' => $units,
                ];
            }

            return [
                'genetic_id' => $genetic->id,
                'batch_id' => isset($line['batch_id']) ? (string) $line['batch_id'] : null,
                'grams_cg' => (int) ($line['grams_cg'] ?? 0),
                'units' => null,
            ];
        }, $lines);
    }

    /**
     * @param  list<NormalisedLine>  $lines
     * @param  CommitOptions  $options
     * @return array{0: int, 1: list<array<string, mixed>>}
     */
    private function buildLines(Member $member, array $lines, Location $location, array $options): array
    {
        $resolver = new ResolvePrice;
        $priced = [];       // per OPERATOR-line: the whole-quantity price + its batch allocation (FEFO parts)
        $eighthInput = [];  // per OPERATOR-line input to the basket-wide eighth break

        // PASS 1 — price each operator-line on its WHOLE quantity (prompt 250: price once), and decide which
        // batches it draws from. Manual (a chosen batch_id) is one part; automatic (null) is FEFO across the
        // sede's dispensable batches. No stock is moved yet — the eighth pass needs every line's total first.
        foreach ($lines as $line) {
            $genetic = Genetic::withoutGlobalScopes()->findOrFail($line['genetic_id']);
            $grams = (int) $line['grams_cg'];
            $units = $line['units'];
            $quantity = $units ?? $grams; // the allocation unit: whole units for UNIT, centigrams for WEIGHT

            if ($line['batch_id'] !== null) {
                // MANUAL: the operator's chosen lote. Must be dispensable and must fit — refused, as before.
                $batch = Batch::withoutGlobalScopes()->whereKey($line['batch_id'])->firstOrFail();
                if (! (new SelectBatch)->isDispensable($batch)) {
                    throw new RuntimeException("Batch {$batch->batch_no} is not dispensable (closed, expired or empty).");
                }
                $allocation = [['batch' => $batch, 'qty' => $quantity]];
            } else {
                // AUTOMATIC: draw from the sede's batches oldest-first (throws, naming the genetic + the sede's
                // total, if they cannot cover it — no lote, because the operator chose none).
                $allocation = (new AllocateFromBatches)->handle($genetic, $location, $quantity);
            }

            $price = $resolver->forGenetic($genetic, $location, $member);

            if ($units !== null) {
                $whole = $price->lineForUnits($units); // no eighth (weight only)
                $rateFreeze = ['price_per_gram_cents' => null, 'price_per_unit_cents' => $whole['rate_cents']];
                $eighthInput[] = ['grams_cg' => $grams, 'rate_cents' => 0, 'per_gram_total' => $whole['total_cents'], 'eighth_price' => null];
            } else {
                $whole = $price->lineFor($grams);
                $rateFreeze = ['price_per_gram_cents' => $whole['rate_cents'], 'price_per_unit_cents' => null];
                $eighthInput[] = ['grams_cg' => $grams, 'rate_cents' => $price->effectiveRatePerGramCents(), 'per_gram_total' => $whole['total_cents'], 'eighth_price' => $price->effectiveEighthPriceCents()];
            }

            $priced[] = [
                'genetic' => $genetic,
                'is_unit' => $units !== null,
                'quantity' => $quantity,          // total in the allocation unit
                'discount_cents' => $whole['discount_cents'],
                'rate_freeze' => $rateFreeze,
                'allocation' => $allocation,
            ];
        }

        // Eighth (3.5 g) break across the WHOLE basket (prompt 83), at OPERATOR-line granularity exactly as
        // before — the split below never changes the eighth arithmetic. The total is eighth-aware so a price
        // override reduces from IT (prompt 64).
        $adjusted = $resolver->applyEighthBreaks($eighthInput);

        // PASS 2 — for each operator-line, move stock per batch and store one row per batch. The eighth-adjusted
        // line total and the line discount are split proportional to each part's quantity, with the REMAINDER on
        // the last part so the stored parts sum to the priced total exactly (prompt 250). One batch ⇒ one row,
        // byte-for-byte the pre-250 shape.
        $total = 0;
        $lineData = [];

        foreach ($priced as $i => $p) {
            $lineTotal = $adjusted[$i]['total_cents'];
            $lineDiscount = $p['discount_cents'];
            $pricingNote = $adjusted[$i]['eighth_applied'] ? __('Octavo (1/8)') : null;
            $qtyTotal = $p['quantity'];
            $parts = $p['allocation'];
            $lastIndex = count($parts) - 1;

            $allocatedTotal = 0;
            $allocatedDiscount = 0;

            foreach ($parts as $j => $part) {
                /** @var Batch $batch */
                $batch = $part['batch'];
                $partQty = $part['qty'];
                $isLast = $j === $lastIndex;

                $partTotal = $isLast ? $lineTotal - $allocatedTotal : intdiv($lineTotal * $partQty, $qtyTotal);
                $partDiscount = $isLast ? $lineDiscount - $allocatedDiscount : intdiv($lineDiscount * $partQty, $qtyTotal);
                $allocatedTotal += $partTotal;
                $allocatedDiscount += $partDiscount;

                // The single stock writer — one signed movement per part, against ITS batch. FEFO order.
                (new RecordStockMovement)->handle($batch, StockMovementType::DISPENSE, -$partQty, [
                    'operator_id' => $options['operator_id'] ?? Auth::id(),
                ]);

                $partUnits = $p['is_unit'] ? $partQty : null;
                $partGrams = $p['is_unit'] ? $partQty * (int) $p['genetic']->grams_per_unit_cg : $partQty;

                $lineData[] = [
                    'genetic_id' => $p['genetic']->id,
                    'batch_id' => $batch->id,
                    // grams_cg is populated on EVERY row (computed for UNIT) — the load-bearing invariant.
                    'grams_cg' => $partGrams,
                    'units_dispensed' => $partUnits,
                    'discount_cents' => $partDiscount,
                    'line_total_cents' => $partTotal,
                    'pricing_note' => $pricingNote,
                    'genetic_name_snapshot' => $p['genetic']->name,
                    'batch_no_snapshot' => $batch->batch_no,
                    ...$p['rate_freeze'],
                ];
                $total += $partTotal;
            }
        }

        return [$total, $lineData];
    }
}
