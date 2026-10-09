<?php

namespace App\Support;

use App\Actions\Till\OpenTill;
use App\Enums\CashPot;
use App\Models\Location;
use App\Models\Order;
use App\Models\TillSession;

/**
 * Prompt 373 — "Where the cash goes": which kinds of money a sede keeps in a box of their own. The dispensary is ALWAYS the
 * till (it holds the float and is counted every night); edibles, bar & shop and membership fees each go in the till or in
 * their own box — `cash_box_edibles`, `cash_box_bar`, `cash_box_fees` ('till' | 'own'), per sede, owner-only. A till
 * session snapshots its boxes at opening (`till_sessions.own_boxes`), so its arithmetic never changes under it.
 *
 * Arron (Medicana, Dream Green): "Members, drinks and edibles all go in separate boxes" → «Todo aparte».
 * Liam (Greenhouse): "members money separate and all other transactions in one till" → «Cuotas aparte».
 */
final class CashBoxes
{
    /** The setting behind each optional pot, in {@see CashPot::optional()} order. */
    public const SETTINGS = [
        'BAR' => 'cash_box_bar',
        'SHOP' => 'cash_box_shop', // prompt 378 — 'with_bar' (the default) | 'till' | 'own'
        'FEES' => 'cash_box_fees',
        'EDIBLES' => 'cash_box_edibles',
    ];

    /** The count-every-night switch behind each optional pot. */
    public const COUNT_NIGHTLY = [
        'BAR' => 'count_bar_nightly',
        'SHOP' => 'count_shop_nightly',
        'FEES' => 'count_fees_nightly',
        'EDIBLES' => 'count_edibles_nightly',
    ];

    /** The one-tap presets on *Sedes → Cajas* (378: «Cuotas aparte» leaves the shop with the bar, i.e. in the till). */
    public const PRESETS = [
        'all_till' => ['EDIBLES' => 'till', 'BAR' => 'till', 'SHOP' => 'till', 'FEES' => 'till'],
        'fees_apart' => ['EDIBLES' => 'till', 'BAR' => 'till', 'SHOP' => 'with_bar', 'FEES' => 'own'],
        'all_apart' => ['EDIBLES' => 'own', 'BAR' => 'own', 'SHOP' => 'own', 'FEES' => 'own'],
    ];

    /**
     * The choices each row offers: the shop alone may also go «Con la barra» (prompt 378).
     *
     * @return list<string>
     */
    public static function choicesFor(string $pot): array
    {
        return $pot === CashPot::SHOP->value ? ['with_bar', 'till', 'own'] : ['till', 'own'];
    }

    /** The row's choice when none is stored: the till, and the shop with the bar (today's behaviour for every sede). */
    public static function defaultFor(string $pot): string
    {
        return $pot === CashPot::SHOP->value ? 'with_bar' : 'till';
    }

    /** The shop's choice at this sede ('with_bar' | 'till' | 'own'), snapshotted on each session at opening. */
    public static function shopChoiceFor(?string $locationId): string
    {
        $choice = (string) Settings::get(self::SETTINGS['SHOP'], 'with_bar', $locationId);

        return in_array($choice, self::choicesFor('SHOP'), true) ? $choice : 'with_bar';
    }

    /** @return list<string> the pots this sede keeps in their own box, in CashPot::optional() order */
    public static function ownBoxesFor(?string $locationId): array
    {
        return array_values(array_map(fn (CashPot $pot): string => $pot->value, array_filter(CashPot::optional(),
            fn (CashPot $pot): bool => Settings::get(self::SETTINGS[$pot->value], self::defaultFor($pot->value), $locationId) === 'own')));
    }

    /**
     * «Al cerrar se cuenta la caja (dispensario y barra) y el bote de cuotas.» — the sentence under the rows, so the owner
     * can check they set what they meant.
     *
     * @param  array<string, string>  $choices  pot => 'till' | 'own' (the shop also 'with_bar')
     */
    public static function summary(array $choices): string
    {
        $names = ['EDIBLES' => __('comestibles'), 'BAR' => __('barra'), 'SHOP' => __('tienda'), 'FEES' => __('cuotas')];
        // Prompt 378 — the shop «Con la barra» counts wherever the bar does: in the till with it, or in the bar's box.
        $shop = $choices['SHOP'] ?? 'with_bar';
        if ($shop === 'with_bar' && ($choices['BAR'] ?? 'till') === 'own') {
            $names['BAR'] = __('barra (con la tienda)');
        }
        $inTill = [__('dispensario')];
        $boxes = [];
        foreach (['EDIBLES', 'BAR', 'SHOP', 'FEES'] as $pot) {
            $choice = $pot === 'SHOP' ? ($shop === 'with_bar' ? ($choices['BAR'] ?? 'till') : $shop) : ($choices[$pot] ?? 'till');
            if ($pot === 'SHOP' && $shop === 'with_bar' && $choice === 'own') {
                continue; // already named with the bar's box
            }
            if ($choice === 'own') {
                $boxes[] = $names[$pot];
            } else {
                $inTill[] = $names[$pot];
            }
        }

        $sentence = __('Al cerrar se cuenta la caja (:till)', ['till' => self::list($inTill)]);
        if ($boxes !== []) {
            $sentence .= ' '.trans_choice('y el bote de :boxes|y los botes de :boxes', count($boxes), ['boxes' => self::list($boxes)]);
        }

        return $sentence.'.';
    }

    /**
     * «Pon 10.00 € en el bote de comestibles y 5.00 € en el bote de la barra.» — what staff put where, for the session's own
     * boxes only. Null when nothing goes in a separate box (no line, as before). Prompt 378 — the cash is given by KIND
     * (edibles, shop, bar, fees) and told by the box it goes in ({@see TillSession::cashPotFor()}): the shop's money «Con la
     * barra» is added to the bar's box.
     *
     * @param  array<string, int>  $cashByPot  kind => cents
     */
    public static function sentence(TillSession $session, array $cashByPot): ?string
    {
        $byBox = [];
        foreach ([CashPot::EDIBLES, CashPot::SHOP, CashPot::BAR, CashPot::FEES] as $kind) { // the order a visit is paid in
            $box = $session->cashPotFor($kind);
            if ($box !== CashPot::DISPENSARY) {
                $byBox[$box->value] = ($byBox[$box->value] ?? 0) + (int) ($cashByPot[$kind->value] ?? 0);
            }
        }
        $parts = [];
        foreach ($byBox as $box => $cents) {
            if ($cents > 0) {
                $parts[] = CashPot::from($box)->boxPhrase(Money::fromCents($cents)->formatted());
            }
        }

        return $parts === [] ? null : __('Pon :parts.', ['parts' => self::list($parts)]);
    }

    /**
     * Prompt 373 — on *Sedes → Cajas*, a box switched to «En la caja» while it still holds money at a last close: «El bote de la
     * barra tiene 45.00 € sin contar desde el 3/10. Al abrir la próxima caja se sumará a la caja: vacía el bote en la caja.» Null
     * when there is nothing in it.
     *
     * Prompt 374 — **per terminal**, because {@see OpenTill} merges per terminal (`heldAtLastClose()`): each terminal's own last
     * close is read, so money left at POS-2 is not missed because POS-1 closed later. With more than one terminal each part is
     * named: «POS-2: el bote de la barra tiene 45.00 €…».
     */
    public static function mergeWarning(Location $location, CashPot $pot): ?string
    {
        $terminals = TillSession::query()->withoutGlobalScopes()->where('location_id', $location->id)->whereNotNull('closed_at')
            ->pluck('terminal')->map(fn ($t): string => (string) $t)->unique(fn (string $t): string => TerminalName::key($t))->values();
        $parts = [];
        foreach ($terminals as $terminal) {
            $last = OpenTill::lastCloseAt($location, TerminalName::key($terminal));
            if ($last === null || ! $last->hasOwnBox($pot)) {
                continue;
            }
            $counted = $last->getRawOriginal($pot->column().'_counted_cents');
            $held = (int) ($counted ?? $last->getRawOriginal($pot->column().'_expected_cents') ?? 0);
            if ($held <= 0) {
                continue;
            }
            $date = local_datetime(TillSummary::uncountedSince($last, $pot) ?? $last->closed_at, 'j/n', $location);
            $box = $terminals->count() > 1 ? $pot->boxName() : ucfirst($pot->boxName());
            $sentence = $counted === null
                ? __(':box tiene :amount sin contar desde el :date.', ['box' => $box, 'amount' => Money::fromCents($held)->formatted(), 'date' => $date])
                : __(':box tiene :amount (contado el :date).', ['box' => $box, 'amount' => Money::fromCents($held)->formatted(), 'date' => $date]);
            $parts[] = $terminals->count() > 1 ? __(':terminal: :sentence', ['terminal' => (string) $last->terminal, 'sentence' => $sentence]) : $sentence;
        }

        return $parts === [] ? null : implode(' ', $parts).' '.__('Al abrir la próxima caja se sumará a la caja: vacía el bote en la caja.');
    }

    /**
     * Prompt 378 — an order's cash by kind: the shop's part (fixed at commit) and the bar's (the rest), for `sentence()`.
     *
     * @return array<string, int>
     */
    public static function orderCash(Order $order): array
    {
        $shop = $order->shop_cash_cents->cents;

        return [CashPot::BAR->value => $order->cash_cents->cents - $shop, CashPot::SHOP->value => $shop];
    }

    /** @param  list<string>  $items  «a», «a y b», «a, b y c» */
    private static function list(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' '.__('y').' '.$last;
    }
}
