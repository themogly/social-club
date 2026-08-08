<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FeePaymentMethod: string implements HasLabel
{
    case CASH = 'CASH';
    case WALLET = 'WALLET';
    case BANK = 'BANK';
    case CARD = 'CARD';

    /**
     * The club charged the fee and decided to forgo it (prompt 219).
     *
     * **A payment-shaped event, not an edit to the fee.** The tier charged €20 and the club chose not to take
     * it; that is a different truth from "the fee was €0", and the register should hold the first one. Writing
     * it as a row against the same `amount_cents` sum means the debt clears everywhere at once — the door
     * notice, the `unpaid_fee` verdict, the fee panels, renewal — with no second write path and no consumer
     * changed. And it clears with an AUTHOR: the operator, a required reason, and an audit entry. A fee that
     * quietly became €0 is invisible at the assembly; a waiver line is governance.
     */
    case WAIVED = 'WAIVED';

    public function label(): string
    {
        return match ($this) {
            self::CASH => __('Efectivo'),
            self::WALLET => __('Monedero'),
            self::BANK => __('Banco'),
            self::CARD => __('Tarjeta'),
            self::WAIVED => __('Condonada'),
        };
    }

    /**
     * Is this method MONEY the club received?
     *
     * The one place that question is answered, because prompt 219 added a method that is not. A waiver enters
     * no revenue, cash or wallet figure — it is forgone income, which is a real governance fact and gets its
     * own line where fees are reported, never a share of one that says "we took this".
     */
    public function isRevenue(): bool
    {
        return match ($this) {
            self::CASH, self::WALLET, self::BANK, self::CARD => true,
            self::WAIVED => false,
        };
    }

    /**
     * The methods that ARE money — for report queries that must exclude a waiver.
     *
     * @return list<string>
     */
    public static function revenueValues(): array
    {
        return array_values(array_map(
            fn (self $m): string => $m->value,
            array_filter(self::cases(), fn (self $m): bool => $m->isRevenue()),
        ));
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
