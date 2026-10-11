<?php

namespace App\Actions\Dispensing;

use App\Actions\Pricing\ResolvePrice;
use App\Actions\RecordAuditLog;
use App\Actions\Stock\AllocateFromBatches;
use App\Actions\Stock\RecordStockMovement;
use App\Actions\Stock\SelectBatch;
use App\Actions\Wallet\SpendFromWallet;
use App\Enums\DispensationStatus;
use App\Enums\MembershipStatus;
use App\Enums\ProductType;
use App\Enums\StockMovementType;
use App\Enums\TillSessionStatus;
use App\Enums\WalletTransactionType;
use App\Exceptions\DispensationBlockedException;
use App\Exceptions\LimitExceededException;
use App\Exceptions\StockUnavailableException;
use App\Exceptions\TillClosedException;
use App\Models\Batch;
use App\Models\Dispensation;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\TillSession;
use App\Models\User;
use App\Support\ChargeRounding;
use App\Support\DispensaryRounding;
use App\Support\LimitSnapshot;
use App\Support\ManagerApproval;
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
 * @phpstan-type CommitOptions array{operator_id?: ?string, till_session_id?: ?string, cash_cents?: int, wallet_cents?: int, signature_path?: ?string, idempotency_key?: ?string, reversal_of_id?: ?string, override?: bool, override_by?: ?User, override_reason?: ?string, price_override_cents?: ?int, price_override_reason?: ?string, price_override_by?: ?User, on_tab?: bool, charge_rounding?: bool, at?: ?\DateTimeInterface}
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

                [$total, $lineData, $discountKinds] = $this->buildLines($member, $lines, $location, $options);

                // Price override (prompt 64): a permissioned, reasoned adjustment to what the member pays for
                // the whole contribution — comping defective product, a €0 give-away, or (356) a batch entered too cheap. It changes only the
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
                    // Prompt 356 — THE WRITER decides the reason, not the form: a holder of `reasons.optional` (a manager, by
                    // default) gives none and «Aprobado por responsable» is recorded; anyone else must give one.
                    $overrideReason = trim((string) ($options['price_override_reason'] ?? ''));
                    if ($overrideReason === '' && ManagerApproval::allows($overrideBy)) {
                        $overrideReason = ManagerApproval::reason();
                    }
                    if ($overrideReason === '') {
                        throw new RuntimeException('A price override requires a reason.');
                    }
                    $originalTotal = $total;
                    // Prompt 356 — UP as well as down ("some stock is added too cheap"): any total ≥ 0. The lasting fix is the
                    // batch's own price (SetBatchPrice); this charges the right amount for THIS sale.
                    $total = max(0, (int) $options['price_override_cents']);
                }

                // Prompt 350 — the discounted total rounded to the euro (by default when a Local discount applied), ONCE,
                // the difference spread over the lines so they still add up. A manager's price adjustment wins: never
                // rounded on top. The same rule the counter showed (DispensaryRounding), so the commit is that figure.
                $rounding = 0;
                if ($originalTotal === null && DispensaryRounding::applies($discountKinds)) {
                    $rounding = DispensaryRounding::round($total) - $total;
                    if ($rounding !== 0) {
                        $shares = DispensaryRounding::spread(array_map(fn (array $l): int => (int) $l['line_total_cents'], $lineData), $rounding);
                        foreach ($shares as $i => $share) {
                            $lineData[$i]['line_total_cents'] += $share;
                        }
                        $total += $rounding;
                    }
                }

                $cash = $options['cash_cents'] ?? $total;
                $wallet = $options['wallet_cents'] ?? 0;

                // Prompt 373 — THE edibles-cash rule, written once: the cash goes to the edibles first, up to their line totals
                // (a visit paid partly from the wallet puts the wallet against the rest). Fixed here for every dispensation,
                // whatever the sede's boxes, so a later change of setting needs no history and editing a genetic's type never
                // rewrites the past. TillSummary and the counter's «Pon … en el bote de comestibles» both read it.
                $edibleIds = Genetic::query()->withoutGlobalScopes()->whereIn('id', array_column($lineData, 'genetic_id'))
                    ->where('product_type', ProductType::EDIBLE->value)->pluck('id')->map(fn ($id): string => (string) $id)->all();
                $ediblesTotal = array_sum(array_map(fn (array $l): int => in_array((string) $l['genetic_id'], $edibleIds, true) ? (int) $l['line_total_cents'] : 0, $lineData));
                $ediblesCash = max(0, min((int) $cash, $ediblesTotal));

                $dispensation = Dispensation::create([
                    'self_dispensed' => $this->selfDispensed($member, $options),
                    'organisation_id' => $member->organisation_id,
                    'member_id' => $member->id,
                    'location_id' => $location->id,
                    'operator_id' => $options['operator_id'] ?? Auth::id(),
                    'till_session_id' => $options['till_session_id'] ?? null,
                    'total_cents' => $total,
                    'rounding_cents' => $rounding,
                    'charge_rounding' => (bool) ($options['charge_rounding'] ?? false), // prompt 355
                    'original_total_cents' => $originalTotal,
                    'price_override_reason' => $overrideReason,
                    'price_override_by' => $overrideBy?->id,
                    'cash_cents' => $cash,
                    'wallet_cents' => $wallet,
                    'edibles_cash_cents' => $ediblesCash,
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
                            // Prompt 333 — "Aprobado por responsable" via the permission, not a typed reason.
                            ...(ManagerApproval::applies($overrideBy, $overrideReason) ? [ManagerApproval::AUDIT_KEY => ManagerApproval::PERMISSION] : []),
                        ]);
                }

                if ($wallet > 0) {
                    // Prompt 259 — no longer `allow_debt => true` (which opened a tab with debt switched off):
                    // beyond the member's credit only via the deliberate "Añadir a la cuenta", within their tab.
                    (new SpendFromWallet)->handle($member, $location, $wallet, WalletTransactionType::CONTRIBUTION, $dispensation, (bool) ($options['on_tab'] ?? false), [
                        'operator_id' => $options['operator_id'] ?? Auth::id(),
                        'till_session_id' => $options['till_session_id'] ?? null,
                        'reason' => 'Aportación por dispensación',
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
        // Prompt 348 — *Exigir foto para dispensar* (per sede, on by default): no photo, no dispensation. The counter's
        // blocked surface offers «Hacer foto»; this is the server's half, inside the commit.
        if (blank($member->photo_path) && (bool) Settings::get('require_photo_to_dispense', true, (string) $location->getKey())) {
            throw new DispensationBlockedException(__('Hazle una foto antes de dispensar.'));
        }

        if (Settings::photoEnforcement('counter') === 'OVERRIDE' && blank($member->photo_path)) {
            $this->authorisePhotoOverride($member, $location, $options);
        }

        // Prompt 347 — a member of staff serving their OWN member record: allowed and flagged (`self_dispensed`) unless the
        // sede says someone else must serve them. Blocked here, inside the commit, not just warned on the screen.
        if ($this->selfDispensed($member, $options) && (bool) Settings::get('block_self_dispensation', false, (string) $location->getKey())) {
            throw new DispensationBlockedException(__('Te estás atendiendo a ti mismo: en esta sede debe atenderte otra persona del personal.'));
        }
    }

    /**
     * Is the operator recording this the member being served (their staff account linked to this member record)?
     *
     * @param  CommitOptions  $options
     */
    private function selfDispensed(Member $member, array $options): bool
    {
        $operatorId = $options['operator_id'] ?? Auth::id();

        return $operatorId !== null && User::query()->whereKey($operatorId)->value('member_id') === $member->getKey();
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

            // WARN records the breach and carries on; OFF (limits switched off, prompt 296) never checked it at all.
            if (in_array(Settings::enforcement('counter', "{$rule}_limit"), ['WARN', 'OFF'], true)) {
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
                    throw new RuntimeException(__('Cada línea necesita una cantidad positiva.'));
                }

                return [
                    'genetic_id' => $genetic->id,
                    'batch_id' => isset($line['batch_id']) ? (string) $line['batch_id'] : null,
                    'grams_cg' => $units * (int) $genetic->grams_per_unit_cg,
                    'units' => $units,
                ];
            }

            // Prompt 256 — the weight twin of the unit guard above. `addLine()` refuses a non-positive weight, but
            // the basket is a public Livewire property: a forged `grams_cg: -500` on a manual lote reached the
            // locked decrement (whose guard only stops the RESULT going below zero), so the lote's stock ROSE,
            // cash was recorded leaving the till and the member's daily allowance went up. The writer holds the
            // invariant, before any allocation or pricing, for every caller (POS, combined settle, seeds).
            $grams = (int) ($line['grams_cg'] ?? 0);
            if ($grams <= 0) {
                throw new RuntimeException(__('Cada línea necesita una cantidad positiva.'));
            }

            return [
                'genetic_id' => $genetic->id,
                'batch_id' => isset($line['batch_id']) ? (string) $line['batch_id'] : null,
                'grams_cg' => $grams,
                'units' => null,
            ];
        }, $lines);
    }

    /**
     * @param  list<NormalisedLine>  $lines
     * @param  CommitOptions  $options
     * @return array{0: int, 1: list<array<string, mixed>>, 2: list<?string>}
     */
    private function buildLines(Member $member, array $lines, Location $location, array $options): array
    {
        $resolver = new ResolvePrice;
        $priced = [];       // per OPERATOR-line: the whole-quantity price + its batch allocation (FEFO parts)
        $eighthInput = [];  // per OPERATOR-line input to the basket-wide eighth break
        $discountKinds = []; // per OPERATOR-line: the applied discount's kind (prompt 350)
        $measure = [];      // per OPERATOR-line: what measures its discount (prompt 383)

        // PASS 1 — price each operator-line on its WHOLE quantity (prompt 250: price once), and decide which
        // batches it draws from. Manual (a chosen batch_id) is one part; automatic (null) is FEFO across the
        // sede's dispensable batches. No stock is moved yet — the eighth pass needs every line's total first.
        // Prompt 355 — half-gram rounding of what is CHARGED, decided by the server (the counter passes the operator's
        // session choice; a request cannot), once per weight line on its total. Stock and limits keep the weighed grams.
        $rounding = (bool) ($options['charge_rounding'] ?? false);

        foreach ($lines as $line) {
            $genetic = Genetic::withoutGlobalScopes()->findOrFail($line['genetic_id']);
            $grams = (int) $line['grams_cg'];
            $units = $line['units'];
            $charged = $units === null && $rounding ? ChargeRounding::charged($grams) : $grams;
            $quantity = $units ?? $grams; // the allocation unit: whole units for UNIT, centigrams for WEIGHT

            if ($line['batch_id'] !== null) {
                // MANUAL: the operator's chosen lote. Must be dispensable and must fit — refused, as before.
                $batch = Batch::withoutGlobalScopes()->whereKey($line['batch_id'])->firstOrFail();
                // Prompt 256 — the chosen lote must be THIS line's product at THIS sede. `isDispensable` only asks
                // open/unexpired/non-empty, so a forged `batch_id` drew a Centro sale from another genetic's lote
                // or from a Norte lote, and the register showed grams from a lote that did not match what was sold.
                if ($batch->genetic_id !== $genetic->id || $batch->location_id !== $location->id) {
                    throw new StockUnavailableException(__('El lote :batch no corresponde a este producto en esta sede.', ['batch' => $batch->displayName()]));
                }
                if (! (new SelectBatch)->isDispensable($batch)) {
                    throw new RuntimeException("Batch {$batch->batch_no} is not dispensable (closed, expired or empty).");
                }
                $allocation = [['batch' => $batch, 'qty' => $quantity]];
            } else {
                // AUTOMATIC: draw from the sede's batches oldest-first (throws, naming the genetic + the sede's
                // total, if they cannot cover it — no lote, because the operator chose none).
                $allocation = (new AllocateFromBatches)->handle($genetic, $location, $quantity);
            }

            // Prompt 278 — each part at ITS OWN batch's price (owner decision 2); the line is their sum.
            $whole = $resolver->priceParts($genetic, $location, $member, $allocation, $units !== null, $units === null ? $charged : null);

            // The eighth break groups on CHARGED grams (355): 3.40 g weighed → 3.5 g charged → one eighth.
            $eighthInput[] = $units !== null
                ? ['grams_cg' => $grams, 'rate_cents' => 0, 'per_gram_total' => $whole['total_cents'], 'eighth_price' => null]
                : ['grams_cg' => $charged, 'rate_cents' => $whole['effective_rate_cents'], 'per_gram_total' => $whole['total_cents'], 'eighth_price' => $whole['eighth_price']];

            // Prompt 383 — the same line for a standard member: a list line's discount is measured against it.
            $measure[] = ['list' => $whole['list'] !== null, 'discount_cents' => $whole['discount_cents'],
                'standard' => $resolver->standardLine($whole, $units === null ? $charged : $grams, $units !== null)];

            $discountKinds[] = $whole['discount_kind']; // prompt 350 — for the rounding scope
            $priced[] = [
                'genetic' => $genetic,
                'is_unit' => $units !== null,
                'quantity' => $quantity,          // total in the allocation unit
                'charged' => $units === null ? $charged : $quantity, // what the price was computed on (355)
                'discount_cents' => $whole['discount_cents'],
                'discount_kind' => $whole['discount_kind'], // prompt 375 — stored on each discounted part
                'list' => $whole['list'] !== null,
                'parts' => $whole['parts'],       // per part: its batch, qty, rate, total and discount
            ];
        }

        // Eighth (3.5 g) break across the WHOLE basket (prompt 83), at OPERATOR-line granularity exactly as
        // before — the split below never changes the eighth arithmetic. The total is eighth-aware so a price
        // override reduces from IT (prompt 64).
        $adjusted = $resolver->applyEighthBreaks($eighthInput);
        $discounts = $resolver->measuredDiscounts($measure, $adjusted);

        // PASS 2 — for each operator-line, move stock per batch and store one row per batch. The eighth-adjusted
        // line total and the line discount are split proportional to each part's quantity, with the REMAINDER on
        // the last part so the stored parts sum to the priced total exactly (prompt 250). One batch ⇒ one row,
        // byte-for-byte the pre-250 shape.
        $total = 0;
        $lineData = [];

        foreach ($priced as $i => $p) {
            $lineTotal = $adjusted[$i]['total_cents'];
            $lineDiscount = $discounts[$i];
            $pricingNote = $adjusted[$i]['eighth_applied'] ? __('Octavo (1/8)') : null;
            $qtyTotal = $p['charged']; // an eighth-adjusted total is split by the CHARGED grams of each part (355)
            $parts = $p['parts'];
            $lastIndex = count($parts) - 1;
            // Without an eighth break, every part keeps its OWN batch-priced total (278). An eighth break re-prices the
            // line as a whole, so its total is then split by quantity as before (prompt 250).
            $ownTotals = ! $adjusted[$i]['eighth_applied'];

            $allocatedTotal = 0;
            $allocatedDiscount = 0;

            foreach ($parts as $j => $part) {
                /** @var Batch $batch */
                $batch = $part['batch'];
                $partQty = $part['qty'];
                $isLast = $j === $lastIndex;

                $partCharged = (int) $part['charged_qty'];
                $partTotal = $ownTotals ? (int) $part['total_cents']
                    : ($isLast ? $lineTotal - $allocatedTotal : intdiv($lineTotal * $partCharged, max(1, $qtyTotal)));
                // A list line's discount is measured on the whole line (383), so it is split by quantity like an eighth's total.
                $partDiscount = $ownTotals && ! $p['list'] ? (int) $part['discount_cents']
                    : ($isLast ? $lineDiscount - $allocatedDiscount : intdiv($lineDiscount * $partCharged, max(1, $qtyTotal)));
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
                    'charged_cg' => $p['is_unit'] ? null : $partCharged, // prompt 355 — what this part was charged for
                    'units_dispensed' => $partUnits,
                    'discount_cents' => $partDiscount,
                    // Prompt 375 — which discount priced it (LOCAL, STAFF…), so the reports split member discounts by kind.
                    'discount_kind' => $partDiscount > 0 ? $p['discount_kind'] : null,
                    'line_total_cents' => $partTotal,
                    // Prompt 382 — charged on the member's price list: the list's name beside any eighth note, its own rate frozen.
                    'pricing_note' => implode(' · ', array_filter([$part['list_label'] ?? null, $pricingNote])) ?: null,
                    'list_rate_cents' => $part['list_rate_cents'] ?? null,
                    'genetic_name_snapshot' => $p['genetic']->name,
                    'batch_no_snapshot' => $batch->batch_no,
                    // The rate THIS part was charged at — its own batch's (278) — frozen with the row.
                    'price_per_gram_cents' => $p['is_unit'] ? null : (int) $part['rate_cents'],
                    'price_per_unit_cents' => $p['is_unit'] ? (int) $part['rate_cents'] : null,
                ];
                $total += $partTotal;
            }
        }

        return [$total, $lineData, $discountKinds];
    }
}
