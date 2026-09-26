<?php

namespace App\Actions\Counter;

use App\Actions\Bar\CommitOrder;
use App\Actions\Dispensing\CommitDispensation;
use App\Exceptions\DebtLimitExceededException;
use App\Models\Dispensation;
use App\Models\Location;
use App\Models\Member;
use App\Models\Order;
use App\Support\Wallet;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One visit, one payment, TWO records (prompt 118). A member who takes cannabis AND buys at the bar in the
 * same visit settles once, but the two stay on their SEPARATE ledgers — a Dispensation and an Order, never a
 * single merged row — because they are legally different things (a shared-cost aportación vs a bar sale) and
 * bar spend must never touch the gram cap.
 *
 * The two single writers ({@see CommitDispensation}, {@see CommitOrder}) are unchanged and do all the real
 * work; this only ORCHESTRATES them so the pair is ATOMIC — both commit or neither does — and adds the one
 * thing neither can do alone: a COMBINED wallet check, so a member cannot wallet-pay each half within their tab
 * yet blow it across the two. This validates the combined draw up front, before any write.
 *
 * @phpstan-type SettleOptions array{till_session_id?: ?string, operator_id?: ?string, on_tab?: bool, dispensation?: array<string, mixed>, order?: array<string, mixed>}
 */
class CommitCombinedSettle
{
    /**
     * @param  list<array<string, mixed>>  $dispensationLines
     * @param  list<array<string, mixed>>  $orderLines
     * @param  SettleOptions  $options
     * @return array{dispensation: Dispensation, order: Order}
     */
    public function handle(Member $member, Location $location, array $dispensationLines, array $orderLines, array $options = []): array
    {
        // A combined settle is exactly that — both baskets present. An empty side means the caller wants a
        // plain dispensation or a plain order, which have their own single-writer entry points.
        if ($dispensationLines === [] || $orderLines === []) {
            throw new RuntimeException('A combined settle needs both a dispensation line and an order line.');
        }

        /** @var array<string, mixed> $dispOptions */
        $dispOptions = $options['dispensation'] ?? [];
        /** @var array<string, mixed> $orderOptions */
        $orderOptions = $options['order'] ?? [];

        // Shared context: the SAME member, the SAME open till, one operator (attribution). The order carries
        // the member id so its wallet spend + the member's purchase history attach to the same socio.
        $shared = [];
        foreach (['till_session_id', 'operator_id'] as $k) {
            if (($options[$k] ?? null) !== null) {
                $shared[$k] = $options[$k];
            }
        }

        // COMBINED wallet check — fail-closed BEFORE any write. Prompt 259: the draw may exceed the member's credit
        // only on the deliberate tab (`on_tab`, passed to BOTH writers, whose SpendFromWallet re-checks each half)
        // and then only within the member's approved headroom — the same `Wallet::maxDebitCents()` the wallet
        // writer enforces, so the two halves cannot each fit while the pair blows the limit.
        $onTab = (bool) ($options['on_tab'] ?? false);
        $walletDraw = (int) ($dispOptions['wallet_cents'] ?? 0) + (int) ($orderOptions['wallet_cents'] ?? 0);
        if ($walletDraw > 0) {
            $available = $onTab
                ? Wallet::maxDebitCents($member, $location->id)
                : max(0, Wallet::balance($member->id, $location->id));
            if ($walletDraw > $available) {
                throw new DebtLimitExceededException(__('El pago combinado con monedero superaría el saldo disponible del socio.'));
            }
        }
        if ($onTab) {
            $shared['on_tab'] = true;
        }

        // Atomic: an outer transaction wraps both single writers (each nests via a savepoint). If the order
        // fails, the dispensation — committed to its savepoint moments earlier — is rolled back with it, so no
        // partial "cannabis taken but bar not charged" state is ever visible. Dispensation first: it is the
        // compliance boundary (eligibility, carencia, gram limits), so the whole settle stops there if blocked,
        // before any bar stock or cash moves.
        return DB::transaction(function () use ($member, $location, $dispensationLines, $orderLines, $dispOptions, $orderOptions, $shared): array {
            $dispensation = (new CommitDispensation)->handle($member, $location, $dispensationLines, array_merge($shared, $dispOptions));
            $order = (new CommitOrder)->handle($location, $orderLines, array_merge($shared, ['member_id' => $member->id], $orderOptions));

            return ['dispensation' => $dispensation, 'order' => $order];
        });
    }
}
