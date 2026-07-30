<?php

namespace App\Actions\Dispensing;

use App\Actions\Pricing\ResolvePrice;
use App\Actions\RecordAuditLog;
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
 * @phpstan-type Line array{genetic_id: string, batch_id: string, grams_cg?: int, units?: int}
 * @phpstan-type NormalisedLine array{genetic_id: string, batch_id: string, grams_cg: int, units: ?int}
 * @phpstan-type CommitOptions array{operator_id?: ?string, till_session_id?: ?string, cash_cents?: int, wallet_cents?: int, signature_path?: ?string, idempotency_key?: ?string, reversal_of_id?: ?string, override?: bool, override_by?: ?User, override_reason?: ?string, at?: ?\DateTimeInterface}
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

        return DB::transaction(function () use ($member, $location, $lines, $options): Dispensation {
            // Idempotency: never double-commit the same basket.
            $key = $options['idempotency_key'] ?? null;
            if ($key !== null) {
                $existing = Dispensation::withoutGlobalScopes()->where('idempotency_key', $key)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            // A dispensation may only attach to an OPEN till session.
            $tillSessionId = $options['till_session_id'] ?? null;
            if ($tillSessionId !== null) {
                $till = TillSession::withoutGlobalScopes()->find($tillSessionId);
                if ($till === null || $till->status !== TillSessionStatus::OPEN) {
                    throw new TillClosedException('The dispensation must attach to an open till session.');
                }
            }

            // Serialise per member so concurrent tills cannot jointly breach the limit.
            Member::withoutGlobalScopes()->whereKey($member->id)->lockForUpdate()->first();

            $this->assertEligible($member, $location);

            // Normalise every line to a stored grams_cg (computed for UNIT lines) BEFORE the
            // limit check, so the daily/monthly ceiling arithmetic is fed the same figure it
            // always was — ResolveMemberLimits itself is untouched.
            $lines = $this->normalise($lines);

            $totalGrams = array_sum(array_map(fn (array $line) => (int) $line['grams_cg'], $lines));
            $snapshot = (new ResolveMemberLimits)->handle($member, $location, $options['at'] ?? null);
            $this->assertWithinLimits($snapshot, $totalGrams, $member, $location, $options);

            [$total, $lineData] = $this->buildLines($member, $lines, $location, $options);

            $cash = $options['cash_cents'] ?? $total;
            $wallet = $options['wallet_cents'] ?? 0;

            $dispensation = Dispensation::create([
                'organisation_id' => $member->organisation_id,
                'member_id' => $member->id,
                'location_id' => $location->id,
                'operator_id' => $options['operator_id'] ?? Auth::id(),
                'till_session_id' => $options['till_session_id'] ?? null,
                'total_cents' => $total,
                'cash_cents' => $cash,
                'wallet_cents' => $wallet,
                'status' => DispensationStatus::COMPLETED,
                'reversal_of_id' => $options['reversal_of_id'] ?? null,
                'signature_path' => $options['signature_path'] ?? null,
                'idempotency_key' => $key,
                'dispensed_at' => $options['at'] ?? now(),
            ]);

            foreach ($lineData as $line) {
                $dispensation->lines()->create($line);
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
    }

    private function assertEligible(Member $member, Location $location): void
    {
        $hasActiveMembership = $member->memberships()->withoutGlobalScopes()
            ->where('location_id', $location->id)
            ->where('status', MembershipStatus::ACTIVE->value)
            ->exists();

        if (! $hasActiveMembership && Settings::enforcement('counter', 'membership') !== 'WARN') {
            throw new DispensationBlockedException('The member has no active membership at this location.');
        }

        if (! MemberEligibility::carenciaPassed($member) && Settings::enforcement('counter', 'carencia') !== 'WARN') {
            throw new DispensationBlockedException('The member is still within their carencia (waiting period).');
        }
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
                    'batch_id' => (string) $line['batch_id'],
                    'grams_cg' => $units * (int) $genetic->grams_per_unit_cg,
                    'units' => $units,
                ];
            }

            return [
                'genetic_id' => $genetic->id,
                'batch_id' => (string) $line['batch_id'],
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
        $total = 0;
        $lineData = [];
        $resolver = new ResolvePrice;

        foreach ($lines as $line) {
            $batch = Batch::withoutGlobalScopes()->whereKey($line['batch_id'])->firstOrFail();
            $grams = (int) $line['grams_cg'];
            $units = $line['units'];

            if (! (new SelectBatch)->isDispensable($batch)) {
                throw new RuntimeException("Batch {$batch->batch_no} is not dispensable (closed, expired or empty).");
            }

            $price = $resolver->forGenetic($batch->genetic, $location, $member);

            if ($units !== null) {
                // UNIT line: freeze the per-unit rate; grams_cg was computed in normalise().
                $priced = $price->lineForUnits($units);
                (new RecordStockMovement)->handle($batch, StockMovementType::DISPENSE, -$units, [
                    'operator_id' => $options['operator_id'] ?? Auth::id(),
                ]);

                $rateFreeze = ['price_per_gram_cents' => null, 'price_per_unit_cents' => $priced['rate_cents'], 'units_dispensed' => $units];
            } else {
                // WEIGHT line: unchanged — freeze the per-gram rate, decrement centigrams.
                $priced = $price->lineFor($grams);
                (new RecordStockMovement)->handle($batch, StockMovementType::DISPENSE, -$grams, [
                    'operator_id' => $options['operator_id'] ?? Auth::id(),
                ]);

                $rateFreeze = ['price_per_gram_cents' => $priced['rate_cents'], 'price_per_unit_cents' => null, 'units_dispensed' => null];
            }

            $total += $priced['total_cents'];

            $lineData[] = [
                'genetic_id' => $batch->genetic_id,
                'batch_id' => $batch->id,
                // grams_cg is populated on EVERY line (computed for UNIT) — the load-bearing invariant.
                'grams_cg' => $grams,
                'discount_cents' => $priced['discount_cents'],
                'line_total_cents' => $priced['total_cents'],
                'genetic_name_snapshot' => $batch->genetic->name,
                'batch_no_snapshot' => $batch->batch_no,
                ...$rateFreeze,
            ];
        }

        return [$total, $lineData];
    }
}
