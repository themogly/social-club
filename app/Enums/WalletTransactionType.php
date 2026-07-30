<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WalletTransactionType: string implements HasLabel
{
    case TOPUP = 'TOPUP';
    case CONTRIBUTION = 'CONTRIBUTION';   // cannabis aportación (dispensary)
    case PURCHASE = 'PURCHASE';           // bar / merch sale paid from the wallet
    case FEE = 'FEE';
    case REFUND = 'REFUND';
    case ADJUSTMENT = 'ADJUSTMENT';
    case TRANSFER_IN = 'TRANSFER_IN';
    case TRANSFER_OUT = 'TRANSFER_OUT';

    public function label(): string
    {
        return match ($this) {
            self::TOPUP => __('Recarga'),
            self::CONTRIBUTION => __('Aportación'),
            self::PURCHASE => __('Compra'),
            self::FEE => __('Cuota'),
            self::REFUND => __('Reembolso'),
            self::ADJUSTMENT => __('Ajuste'),
            self::TRANSFER_IN => __('Transferencia entrante'),
            self::TRANSFER_OUT => __('Transferencia saliente'),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
