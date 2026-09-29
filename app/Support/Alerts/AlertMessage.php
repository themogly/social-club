<?php

namespace App\Support\Alerts;

use App\Enums\AlertType;
use App\Filament\Resources\Batches\BatchResource;
use App\Models\OwnerAlertState;
use App\Support\StockCover;
use App\Support\Weight;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Prompt 311 — how an alert reads, in the language that is current when it is composed (the caller pins the recipient's
 * locale). Plain text, neutral and operational: stock names and quantities, sede names and times — never prices, never
 * member information. Sections follow {@see AlertType}'s order (restock first, running out second), one per type and sede.
 */
final class AlertMessage
{
    /** @param  Collection<int, OwnerAlertState>  $states */
    public static function text(Collection $states): string
    {
        return self::sections($states)
            ->map(fn (array $section): string => $section['heading']."\n".implode("\n", $section['lines']))
            ->implode("\n\n");
    }

    /**
     * The same content, structured — for the morning email.
     *
     * @param  Collection<int, OwnerAlertState>  $states
     * @return Collection<int, array{heading: string, lines: list<string>}>
     */
    public static function sections(Collection $states): Collection
    {
        $order = array_flip(array_map(fn (AlertType $type): string => $type->value, AlertType::cases()));

        return $states
            ->sortBy(fn (OwnerAlertState $state): string => str_pad((string) $order[$state->type->value], 2, '0', STR_PAD_LEFT).'|'.($state->location_id === null ? '' : $state->location->name))
            ->groupBy(fn (OwnerAlertState $state): string => $state->type->value.'|'.($state->location_id ?? '-'))
            ->map(function (Collection $group): array {
                /** @var OwnerAlertState $first */
                $first = $group->first();
                $heading = $first->type->mark().' '.$first->type->label().($first->location_id !== null ? ' — '.$first->location->name : '');
                $lines = $group->map(fn (OwnerAlertState $state): string => '• '.self::line($state))->values()->all();
                if ($first->type === AlertType::RESTOCK_FROM_STORE && ($link = self::transferLink($group)) !== null) {
                    $lines[] = (string) __('Trasladar: :url', ['url' => $link]);
                }

                return ['heading' => $heading, 'lines' => array_values($lines)];
            })->values();
    }

    private static function line(OwnerAlertState $state): string
    {
        $d = $state->detail ?? [];

        return match ($state->type) {
            AlertType::RESTOCK_FROM_STORE, AlertType::RUNNING_OUT => self::strainLine($d),
            AlertType::PRODUCTS_LOW => __(':name: :stock uds (umbral :threshold)', ['name' => $d['name'] ?? '', 'stock' => $d['stock'] ?? 0, 'threshold' => $d['threshold'] ?? 0]),
            AlertType::BATCH_EXPIRING => __(':name: caduca el :date (quedan :left)', [
                'name' => $d['name'] ?? '',
                'date' => isset($d['expires_on']) ? CarbonImmutable::parse($d['expires_on'])->translatedFormat('j M') : '—',
                'left' => self::quantity((int) ($d['remaining'] ?? 0), (bool) ($d['unit'] ?? false)),
            ]),
            AlertType::TILL_OPEN_TOO_LONG => __('Caja :terminal abierta desde :since', [
                'terminal' => $d['terminal'] ?? '',
                'since' => isset($d['opened_at']) ? CarbonImmutable::parse($d['opened_at'])->setTimezone(self::timezone($state))->translatedFormat('j M H:i') : '—',
            ]),
            AlertType::SYSTEM => __(':component no se ha ejecutado desde :since', [
                'component' => self::componentLabel((string) ($d['component'] ?? '')),
                'since' => isset($d['last_at']) ? CarbonImmutable::parse($d['last_at'])->setTimezone((string) config('app.timezone'))->translatedFormat('j M H:i') : __('nunca'),
            ]),
        };
    }

    /** @param  array<string, mixed>  $d */
    private static function strainLine(array $d): string
    {
        $unit = (bool) ($d['unit'] ?? false);
        $cover = ($d['out'] ?? false) ? __('agotada') : (StockCover::label(isset($d['days']) ? (float) $d['days'] : null) ?? __('bajo'));
        $line = ($d['name'] ?? '').': '.$cover.' ('.self::quantity((int) ($d['on_hand'] ?? 0), $unit).')';

        if (is_array($d['store'] ?? null)) {
            $line .= ' · '.__(':quantity en :stores', [
                'quantity' => self::quantity((int) ($d['store']['quantity'] ?? 0), $unit),
                'stores' => implode(', ', (array) ($d['store']['names'] ?? [])),
            ]);
        }

        return $line;
    }

    private static function quantity(int $amount, bool $unit): string
    {
        return $unit ? $amount.' '.__('uds') : Weight::fromCentigrams($amount)->formatted();
    }

    /**
     * The strain's batches at the store, where *Asignar a sede* is (302). One strain: that strain; several: the store.
     *
     * @param  Collection<int, OwnerAlertState>  $group
     */
    private static function transferLink(Collection $group): ?string
    {
        /** @var OwnerAlertState $first */
        $first = $group->first();
        $store = data_get($first->detail, 'store.location_id');
        if (! is_string($store)) {
            return null;
        }

        return BatchResource::getUrl('index', array_filter([
            'search' => $group->count() === 1 ? (string) data_get($first->detail, 'name') : null,
            'filters' => ['location_id' => ['value' => $store], 'stock' => ['value' => 'in_stock']],
        ]));
    }

    private static function timezone(OwnerAlertState $state): string
    {
        return (string) (($state->location_id === null ? null : $state->location->timezone) ?: config('app.timezone'));
    }

    private static function componentLabel(string $component): string
    {
        return match ($component) {
            'scheduler' => __('El programador de tareas'),
            'memberships-sweep' => __('La revisión de membresías caducadas'),
            'temporary-sweep' => __('La baja de socios temporales'),
            'audit-retention-sweep' => __('La limpieza del registro de auditoría'),
            'message-retention-sweep' => __('La limpieza de mensajes'),
            'import-staging-sweep' => __('La limpieza de importaciones'),
            default => $component,
        };
    }
}
