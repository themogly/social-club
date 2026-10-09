<?php

namespace App\Support;

/**
 * Prompt 347 — the sale just recorded, kept for the counter HOME when the sede goes back there after recording
 * (`after_recording` = home). For two minutes, or until the next sale or a lock, the hub shows 300's «Última: … ·
 * Opciones» line — the receipt and the void — so a mistake can still be voided at once. Session-only: ids and a time.
 */
class CounterLastSale
{
    private const KEY = 'counter.last_sale';

    public const SECONDS = 120;

    /** @param  ?string  $boxes  Prompt 374 — «Pon 4.00 € en el bote de comestibles.» ({@see CashBoxes::sentence()}), or null */
    public static function remember(string $locationId, ?string $dispensationId, ?string $orderId, ?string $boxes = null): void
    {
        session([self::KEY => ['location_id' => $locationId, 'dispensation_id' => $dispensationId, 'order_id' => $orderId, 'boxes' => $boxes, 'at' => now()->getTimestamp()]]);
    }

    /** @return array{location_id: string, dispensation_id: ?string, order_id: ?string, boxes?: ?string, at: int}|null */
    public static function current(?string $locationId): ?array
    {
        $sale = session(self::KEY);
        if (! is_array($sale) || $locationId === null || ($sale['location_id'] ?? null) !== $locationId) {
            return null;
        }
        if (now()->getTimestamp() - (int) ($sale['at'] ?? 0) > self::SECONDS) {
            self::forget();

            return null;
        }

        return $sale;
    }

    public static function forget(): void
    {
        session()->forget(self::KEY);
    }
}
