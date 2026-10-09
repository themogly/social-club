<?php

namespace App\ViewModels;

use App\Enums\CashPot;
use App\Enums\DispensationStatus;
use App\Enums\FeePaymentMethod;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Dispensation;
use App\Models\DispensationLine;
use App\Models\Location;
use App\Models\Member;
use App\Models\MembershipFeePayment;
use App\Models\Order;
use App\Models\TillSession;
use App\Support\Period;
use App\Support\Settings;
use App\Support\TillSummary;
use App\Support\Weight;
use Carbon\CarbonInterface;

/**
 * Prompt 371 — the counter's day sheet (Liam: "a sheet-equivalent rundown of the day's transactions… to double-check sheet
 * vs iPad"): every sale at the sede today, oldest first, and the totals a paper sheet has.
 *
 * **"Today" is the panel's own definition**, so the sheet's count equals the home's «Operaciones»: the sede's business day
 * ({@see Period::today()}, its time zone and cutoff), COMPLETED dispensations by `dispensed_at` and COMPLETED bar orders by
 * `created_at` — exactly {@see Dashboard::transactionCount()}. Voided sales are listed (struck through, with the reason) and
 * never counted. A partial refund leaves the sale COMPLETED, so it stays counted, as on the panel, and is marked.
 *
 * Everything is summed in PHP from the rows (no SQL arithmetic, prompt 370). Live, never cached. {@see TillSummary} for the boxes.
 */
class CounterDaySheet
{
    /** @var list<array<string, mixed>>|null */
    private ?array $all = null;

    public function __construct(
        public readonly Location $location,
        private readonly string $source = 'all',
        private readonly ?string $operatorId = null,
        private readonly string $search = '',
    ) {}

    public function period(): Period
    {
        return Period::today($this->location);
    }

    /** @return list<array<string, mixed>> the filtered rows, oldest first */
    public function rows(): array
    {
        $needle = mb_strtolower(trim($this->search));

        return array_values(array_filter($this->all(), fn (array $row): bool => ($this->source === 'all' || $row['kind'] === $this->source)
            && ($this->operatorId === null || $row['operator_id'] === $this->operatorId)
            && ($needle === '' || str_contains(mb_strtolower($row['member_no'].' '.$row['member_name']), $needle))));
    }

    /**
     * The totals a paper sheet has. **The two ledgers stay apart** (prompt 374, CLAUDE.md): `dispensary` is the contributions,
     * and equals the home panel's «Aportaciones» for the same sede and day; `bar` is *Barra y tienda*. No figure adds them.
     * `boxes` is the cash by box when a session today has boxes of its own (373), else null.
     *
     * @return array{count: int, dispensations: int, orders: int, strains: list<array{name: string, grams_cg: int, charged_cg: int}>,
     *               units: list<array{name: string, units: int}>, bar_items: int,
     *               money: array{dispensary: array{total: int, cash: int, wallet: int, tab: int}, bar: array{total: int, cash: int, wallet: int, tab: int}},
     *               boxes: array<string, int>|null}
     */
    public function totals(): array
    {
        $counted = array_filter($this->rows(), fn (array $row): bool => ! $row['voided']);
        $strains = [];
        $units = [];
        $barItems = 0;
        $zero = ['total' => 0, 'cash' => 0, 'wallet' => 0, 'tab' => 0];
        $money = ['dispensary' => $zero, 'bar' => $zero];

        foreach ($counted as $row) {
            foreach ($row['money'] as $key => $cents) {
                $money[$row['kind'] === 'bar' ? 'bar' : 'dispensary'][$key] += $cents;
            }
            foreach ($row['lines'] as $line) {
                if ($row['kind'] === 'bar') {
                    $barItems += $line['qty'];
                } elseif ($line['units'] !== null) {
                    $units[$line['name']] = ($units[$line['name']] ?? 0) + $line['units'];
                } else {
                    $strains[$line['name']] ??= ['name' => $line['name'], 'grams_cg' => 0, 'charged_cg' => 0];
                    $strains[$line['name']]['grams_cg'] += $line['grams_cg'];
                    $strains[$line['name']]['charged_cg'] += $line['charged_cg'];
                }
            }
        }
        ksort($strains);
        ksort($units);

        return [
            'count' => count($counted),
            'dispensations' => count(array_filter($counted, fn (array $row): bool => $row['kind'] === 'dispensary')),
            'orders' => count(array_filter($counted, fn (array $row): bool => $row['kind'] === 'bar')),
            'strains' => array_values($strains),
            'units' => array_map(fn (string $name, int $n): array => ['name' => $name, 'units' => $n], array_keys($units), array_values($units)),
            'bar_items' => $barItems,
            'money' => $money,
            'boxes' => $this->boxes($counted),
        ];
    }

    /**
     * Prompt 374 — the day's cash by where it goes, when a session today keeps boxes of its own: each sale's cash split the way
     * {@see TillSummary} splits it (the edibles' cash fixed at commit, the bar's cash, the rest to the till), plus cash fees when
     * the sheet is unfiltered (they are no sale, so a filter on sales leaves them out). The float is not the day's money.
     *
     * @param  array<int, array<string, mixed>>  $counted
     * @return array<string, int>|null pot => cents: the till first, then each box the sessions keep
     */
    private function boxes(array $counted): ?array
    {
        $ids = array_values(array_unique(array_filter(array_column($counted, 'till_session_id'))));
        [$start, $end] = $this->period()->bounds();
        $unfiltered = $this->source === 'all' && $this->operatorId === null && trim($this->search) === '';
        $fees = $unfiltered ? MembershipFeePayment::query()->where('method', FeePaymentMethod::CASH->value)
            ->whereHas('tillSession', fn ($q) => $q->withoutGlobalScopes()->where('location_id', $this->location->id))
            ->where('paid_at', '>=', $start)->where('paid_at', '<', $end)->get(['till_session_id', 'amount_cents']) : collect();
        $sessions = TillSession::query()->withoutGlobalScopes()->whereIn('id', [...$ids, ...$fees->pluck('till_session_id')->filter()->all()])->get()->keyBy('id');
        $own = $sessions->flatMap(fn (TillSession $s): array => $s->ownBoxes())->unique()->values();
        if ($own->isEmpty()) {
            return null;
        }

        $boxes = [CashPot::DISPENSARY->value => 0];
        foreach (CashPot::optional() as $pot) {
            if ($own->contains($pot)) {
                $boxes[$pot->value] = 0;
            }
        }
        $put = function (?string $sessionId, CashPot $pot, int $cents) use (&$boxes, $sessions): void {
            $session = $sessions->get($sessionId);
            $boxes[$session !== null && $session->hasOwnBox($pot) ? $pot->value : CashPot::DISPENSARY->value] += $cents;
        };
        foreach ($counted as $row) {
            if ($row['kind'] === 'bar') {
                $put($row['till_session_id'], CashPot::BAR, (int) $row['money']['cash']);
            } else {
                $put($row['till_session_id'], CashPot::EDIBLES, (int) $row['edibles_cash']);
                $put($row['till_session_id'], CashPot::DISPENSARY, (int) $row['money']['cash'] - (int) $row['edibles_cash']);
            }
        }
        foreach ($fees as $fee) {
            $put($fee->till_session_id, CashPot::FEES, $fee->amount_cents->cents);
        }

        return $boxes;
    }

    /** @return list<array{id: string, name: string}> who served at least one sale today (for «Solo lo de…») */
    public function operators(): array
    {
        return collect($this->all())->filter(fn (array $row): bool => $row['operator_id'] !== null)
            ->unique('operator_id')->sortBy('operator_name')
            ->map(fn (array $row): array => ['id' => (string) $row['operator_id'], 'name' => (string) $row['operator_name']])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function all(): array
    {
        if ($this->all !== null) {
            return $this->all;
        }
        [$start, $end] = $this->period()->bounds();

        $dispensations = Dispensation::query()->withoutGlobalScopes()->with(['lines.genetic', 'member', 'operator'])->withSum('refunds', 'amount_cents')
            ->where('location_id', $this->location->id)
            ->whereIn('status', [DispensationStatus::COMPLETED->value, DispensationStatus::VOIDED->value, DispensationStatus::CORRECTED->value])
            ->where('dispensed_at', '>=', $start)->where('dispensed_at', '<', $end)->get();
        $orders = Order::query()->withoutGlobalScopes()->with(['member', 'operator'])
            ->where('location_id', $this->location->id)
            ->whereIn('status', [OrderStatus::COMPLETED->value, OrderStatus::VOIDED->value])
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)->get();
        $tabs = $this->tabParts($start, $end);
        $barReceipts = (bool) Settings::get('bar_receipt_enabled', false, $this->location->id);

        $rows = [
            ...$dispensations->map(fn (Dispensation $d): array => $this->dispensationRow($d, $tabs[$d->id] ?? 0))->all(),
            ...$orders->map(fn (Order $o): array => $this->orderRow($o, $tabs[$o->id] ?? 0, $barReceipts))->all(),
        ];
        usort($rows, fn (array $a, array $b): int => [$a['sort'], $a['id']] <=> [$b['sort'], $b['id']]);

        return $this->all = $rows;
    }

    /** @return array<string, mixed> */
    private function dispensationRow(Dispensation $d, int $tab): array
    {
        $at = $d->dispensed_at;
        $wallet = $d->wallet_cents->cents;

        return $this->row($d->id, 'dispensary', $at, $d->member, $d->operator_id, $d->operator?->name, $d->status !== DispensationStatus::COMPLETED, $d->void_reason, [
            'total' => $d->total_cents->cents, 'cash' => $d->cash_cents->cents, 'wallet' => max(0, $wallet - $tab), 'tab' => min($tab, $wallet),
        ], [$d->till_session_id, $d->edibles_cash_cents->cents], $d->lines->map(fn (DispensationLine $line): array => [
            'name' => (string) ($line->genetic_name_snapshot ?: $line->genetic?->name ?: '—'),
            'grams_cg' => $line->grams_cg->centigrams,
            'charged_cg' => $line->getRawOriginal('charged_cg') !== null ? (int) $line->getRawOriginal('charged_cg') : $line->grams_cg->centigrams,
            'units' => $line->units_dispensed !== null ? (int) $line->units_dispensed : null,
            'qty' => 1,
        ])->all(), route('counter.pos.receipt', $d->id), (int) ($d->getAttribute('refunds_sum_amount_cents') ?? 0));
    }

    /** @return array<string, mixed> */
    private function orderRow(Order $o, int $tab, bool $receipts): array
    {
        $wallet = $o->wallet_cents->cents;

        return $this->row($o->id, 'bar', $o->created_at, $o->member, $o->operator_id, $o->operator?->name, $o->status !== OrderStatus::COMPLETED, $o->void_reason, [
            'total' => $o->total_cents->cents, 'cash' => $o->cash_cents->cents, 'wallet' => max(0, $wallet - $tab), 'tab' => min($tab, $wallet),
        ], [$o->till_session_id, 0], collect((array) $o->items)->filter(fn (mixed $item): bool => is_array($item))->map(fn (array $item): array => [
            'name' => (string) ($item['name'] ?? '—'), 'qty' => max(1, (int) ($item['qty'] ?? 1)), 'grams_cg' => 0, 'charged_cg' => 0, 'units' => null,
        ])->values()->all(), $receipts ? route('counter.bar.receipt', $o->id) : null, 0);
    }

    /**
     * @param  array{total: int, cash: int, wallet: int, tab: int}  $money
     * @param  array{0: ?string, 1: int}  $till  the till session, and the cash that paid for edibles (373, fixed at commit)
     * @param  list<array{name: string, grams_cg: int, charged_cg: int, units: ?int, qty: int}>  $lines
     * @return array<string, mixed>
     */
    private function row(string $id, string $kind, ?CarbonInterface $at, ?Member $member, ?string $operatorId, ?string $operator, bool $voided, ?string $reason, array $money, array $till, array $lines, ?string $receipt, int $refunded): array
    {
        $local = $at?->copy()->setTimezone($this->location->timezone ?: 'Europe/Madrid');

        return [
            'id' => $id,
            'kind' => $kind,
            'sort' => $at?->getTimestamp() ?? 0,
            'time' => $local?->format('H:i') ?? '—',
            'member_no' => $member === null ? '' : (string) $member->member_no,
            'member_name' => $member?->fullName() ?? '—',
            'operator_id' => $operatorId,
            'operator_name' => $operator ?? '—',
            'voided' => $voided,
            'void_reason' => $reason,
            'refunded_cents' => $refunded,
            'money' => $money,
            'till_session_id' => $till[0],
            'edibles_cash' => $till[1],
            'lines' => $lines,
            'what' => array_map(fn (array $line): string => $this->lineText($kind, $line), $lines),
            'receipt_url' => $receipt,
        ];
    }

    /** @param  array{name: string, grams_cg: int, charged_cg: int, units: ?int, qty: int}  $line */
    private function lineText(string $kind, array $line): string
    {
        if ($kind === 'bar') {
            return $line['qty'].' × '.$line['name'];
        }
        if ($line['units'] !== null) {
            return $line['name'].' · '.trans_choice(':count ud|:count uds', $line['units'], ['count' => $line['units']]);
        }
        $text = $line['name'].' · '.Weight::fromCentigrams($line['grams_cg'])->formatted();

        // Prompt 355 — what was weighed AND, when the half-gram rounding changed it, what was charged.
        return $line['charged_cg'] !== $line['grams_cg']
            ? $text.' · '.__('se cobra :grams', ['grams' => Weight::fromCentigrams($line['charged_cg'])->formatted()])
            : $text;
    }

    /**
     * The part of each sale put on the member's tab (259): {@see SpendFromWallet} audits it as `wallet.tab.added`.
     *
     * @return array<string, int> sale id => cents
     */
    private function tabParts(CarbonInterface $start, CarbonInterface $end): array
    {
        $parts = [];
        AuditLog::query()->withoutGlobalScopes()->where('action', 'wallet.tab.added')
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)->get(['after'])
            ->each(function (AuditLog $log) use (&$parts): void {
                $after = (array) ($log->after ?? []);
                if (($after['location_id'] ?? null) === $this->location->id && is_string($after['sale_id'] ?? null)) {
                    $parts[$after['sale_id']] = ($parts[$after['sale_id']] ?? 0) + (int) ($after['added_cents'] ?? 0);
                }
            });

        return $parts;
    }
}
