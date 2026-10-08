<?php

namespace App\Enums;

/**
 * Prompt 311 — what the owner is alerted about. The order is the order a message reads in: the two strain alerts first
 * (restock from the store, then running out — the more urgent job), then products, expiry, the till and the system.
 * Prompt 366 adds the week's closes with an unexplained difference, after the open till.
 */
enum AlertType: string
{
    case RESTOCK_FROM_STORE = 'RESTOCK_FROM_STORE';
    case RUNNING_OUT = 'RUNNING_OUT';
    case PRODUCTS_LOW = 'PRODUCTS_LOW';
    case BATCH_EXPIRING = 'BATCH_EXPIRING';
    case TILL_OPEN_TOO_LONG = 'TILL_OPEN_TOO_LONG';
    case TILL_CLOSES_UNEXPLAINED = 'TILL_CLOSES_UNEXPLAINED';
    case SYSTEM = 'SYSTEM';

    public function label(): string
    {
        return match ($this) {
            self::RESTOCK_FROM_STORE => __('Reponer desde el almacén'),
            self::RUNNING_OUT => __('Se acaba: sin reserva en el almacén'),
            self::PRODUCTS_LOW => __('Existencias bajas: productos'),
            self::BATCH_EXPIRING => __('Lote a punto de caducar'),
            self::TILL_OPEN_TOO_LONG => __('Caja abierta demasiado tiempo'),
            self::TILL_CLOSES_UNEXPLAINED => __('Cierres de caja con diferencia sin explicar'),
            self::SYSTEM => __('Sistema'),
        };
    }

    /** The mark a message section starts with. */
    public function mark(): string
    {
        return match ($this) {
            self::RESTOCK_FROM_STORE => '📦',
            self::RUNNING_OUT => '⚠️',
            self::PRODUCTS_LOW => '🛒',
            self::BATCH_EXPIRING => '⏳',
            self::TILL_OPEN_TOO_LONG => '💶',
            self::TILL_CLOSES_UNEXPLAINED => '🧾',
            self::SYSTEM => '🛠️',
        };
    }
}
