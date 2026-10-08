<?php

namespace App\Support;

use App\Enums\CashPot;
use App\Models\Location;
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
        'FEES' => 'cash_box_fees',
        'EDIBLES' => 'cash_box_edibles',
    ];

    /** The count-every-night switch behind each optional pot. */
    public const COUNT_NIGHTLY = [
        'BAR' => 'count_bar_nightly',
        'FEES' => 'count_fees_nightly',
        'EDIBLES' => 'count_edibles_nightly',
    ];

    /** The one-tap presets on *Sedes → Cajas*. */
    public const PRESETS = [
        'all_till' => ['EDIBLES' => 'till', 'BAR' => 'till', 'FEES' => 'till'],
        'fees_apart' => ['EDIBLES' => 'till', 'BAR' => 'till', 'FEES' => 'own'],
        'all_apart' => ['EDIBLES' => 'own', 'BAR' => 'own', 'FEES' => 'own'],
    ];

    /** @return list<string> the pots this sede keeps in their own box, in CashPot::optional() order */
    public static function ownBoxesFor(?string $locationId): array
    {
        return array_values(array_map(fn (CashPot $pot): string => $pot->value, array_filter(CashPot::optional(),
            fn (CashPot $pot): bool => Settings::get(self::SETTINGS[$pot->value], 'till', $locationId) === 'own')));
    }

    /**
     * «Al cerrar se cuenta la caja (dispensario y barra) y el bote de cuotas.» — the sentence under the rows, so the owner
     * can check they set what they meant.
     *
     * @param  array<string, string>  $choices  pot => 'till' | 'own'
     */
    public static function summary(array $choices): string
    {
        $names = ['EDIBLES' => __('comestibles'), 'BAR' => __('barra'), 'FEES' => __('cuotas')];
        $inTill = [__('dispensario')];
        $boxes = [];
        foreach (['EDIBLES', 'BAR', 'FEES'] as $pot) {
            if (($choices[$pot] ?? 'till') === 'own') {
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
     * boxes only. Null when nothing goes in a separate box (no line, as before).
     *
     * @param  array<string, int>  $cashByPot  pot => cents
     */
    public static function sentence(TillSession $session, array $cashByPot): ?string
    {
        $parts = [];
        foreach ([CashPot::EDIBLES, CashPot::BAR, CashPot::FEES] as $pot) { // the order a visit is paid in
            $cents = (int) ($cashByPot[$pot->value] ?? 0);
            if ($cents > 0 && $session->hasOwnBox($pot)) {
                $parts[] = $pot->boxPhrase(Money::fromCents($cents)->formatted());
            }
        }

        return $parts === [] ? null : __('Pon :parts.', ['parts' => self::list($parts)]);
    }

    /**
     * Prompt 373 — on *Sedes → Cajas*, a box switched to «En la caja» while it still holds money at the sede's last close:
     * «El bote de la barra tiene 45.00 € sin contar desde el 3/10. Al abrir la próxima caja se sumará a la caja: vacía el bote
     * en la caja.» Null when there is nothing in it.
     */
    public static function mergeWarning(Location $location, CashPot $pot): ?string
    {
        $last = TillSession::query()->withoutGlobalScopes()->where('location_id', $location->id)->whereNotNull('closed_at')
            ->orderByDesc('closed_at')->orderByDesc('id')->first();
        if ($last === null || ! $last->hasOwnBox($pot)) {
            return null;
        }
        $counted = $last->getRawOriginal($pot->column().'_counted_cents');
        $held = (int) ($counted ?? $last->getRawOriginal($pot->column().'_expected_cents') ?? 0);
        if ($held <= 0) {
            return null;
        }
        $since = TillSummary::uncountedSince($last, $pot) ?? $last->closed_at;
        $date = local_datetime($since, 'j/n', $location);

        return ($counted === null
            ? __(':box tiene :amount sin contar desde el :date.', ['box' => ucfirst($pot->boxName()), 'amount' => Money::fromCents($held)->formatted(), 'date' => $date])
            : __(':box tiene :amount (contado el :date).', ['box' => ucfirst($pot->boxName()), 'amount' => Money::fromCents($held)->formatted(), 'date' => $date]))
            .' '.__('Al abrir la próxima caja se sumará a la caja: vacía el bote en la caja.');
    }

    /** @param  list<string>  $items  «a», «a y b», «a, b y c» */
    private static function list(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' '.__('y').' '.$last;
    }
}
