<?php

namespace App\Support\Alerts;

use App\Enums\AlertType;
use App\Enums\LocationKind;
use App\Filament\Pages\Reports\LossesReportPage;
use App\Filament\Pages\Reports\TillReportPage;
use App\Models\Article;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Organisation;
use App\Models\TillSession;
use App\Support\Period;
use App\Support\Settings;
use App\Support\StockCover;
use App\ViewModels\Reports\LossesReport;
use App\ViewModels\SystemHealth;

/**
 * Prompt 311 — every alert condition that holds RIGHT NOW for one organisation, read through the helpers that already
 * define each thing (no second formula for "low"):
 *
 *  · strains — {@see StockCover::attentionAt()} per SEDE; *Reponer desde el almacén* when a store holds movable stock of
 *    the strain ({@see Batch::scopeMovable()}, the *Trasladar* button's rule), else *Se acaba*. Stores never raise "low"
 *    themselves: nothing is dispensed there, so cover days do not apply;
 *  · products — `Article::lowStock()`, active ones;
 *  · batches with stock left expiring within `alerts_expiry_days` (the date is part of the subject: a changed date clears);
 *  · tills open longer than `alerts_till_open_hours`;
 *  · prompt 366 — the sede's closes this week beyond the tolerance with no note (one alert per sede and week, its count
 *    refreshed each run), linking to Informes → Cajas on «Solo con diferencia»;
 *  · prompt 375 — the people at the sede over that threshold of their own takings this week (a count, never a name);
 *  · prompt 367 — the sede's losses yesterday (Informes → Pérdidas) over its `losses_alert_threshold_pct` of the day's
 *    takings (one alert per sede and day), linking to the report on that day;
 *  · the system — any heartbeat *Salud del sistema* grades red ({@see SystemHealth::heartbeats()}).
 *
 * `detail` carries names, quantities, sede names and times — never member data.
 */
final class CurrentAlerts
{
    /** @return list<array{type: AlertType, subject: string, location_id: ?string, detail: array<string, mixed>}> */
    public static function for(Organisation $organisation): array
    {
        return [
            ...self::strains($organisation),
            ...self::products($organisation),
            ...self::expiring($organisation),
            ...self::tills($organisation),
            ...self::unexplainedCloses($organisation),
            ...self::losses($organisation),
            ...self::system(),
        ];
    }

    /** @return list<array{type: AlertType, subject: string, location_id: ?string, detail: array<string, mixed>}> */
    private static function strains(Organisation $organisation): array
    {
        $locations = Location::query()->withoutGlobalScopes()->where('organisation_id', $organisation->id)->where('active', true)->get();
        $stores = $locations->where('kind', LocationKind::ALMACEN)->keyBy('id');
        $alerts = [];

        foreach ($locations->where('kind', LocationKind::SEDE) as $sede) {
            $genetics = StockCover::sellableWithPricesAt($sede)->keyBy('id');
            $attention = StockCover::attentionAt($genetics->values(), $sede->id);
            $inStores = self::storeStock($organisation, array_keys($attention), $stores->keys()->all());

            foreach ($attention as $geneticId => $figures) {
                /** @var Genetic $genetic */
                $genetic = $genetics->get($geneticId);
                $store = $inStores[$geneticId] ?? null;
                $alerts[] = [
                    'type' => $store !== null ? AlertType::RESTOCK_FROM_STORE : AlertType::RUNNING_OUT,
                    'subject' => 'genetic:'.$geneticId,
                    'location_id' => $sede->id,
                    'detail' => [
                        'name' => $genetic->name,
                        'unit' => $genetic->isUnitType(),
                        'on_hand' => $genetic->isUnitType() && (int) $genetic->grams_per_unit_cg > 0
                            ? intdiv($figures['on_hand_cg'], (int) $genetic->grams_per_unit_cg) : $figures['on_hand_cg'],
                        'days' => $figures['days'],
                        'out' => $figures['out'],
                        'store' => $store === null ? null : [
                            'quantity' => $genetic->isUnitType() ? $store['units'] : $store['cg'],
                            'names' => array_map(fn (string $id): string => (string) $stores->get($id)?->name, $store['locations']),
                            'location_id' => $store['locations'][0],
                        ],
                    ],
                ];
            }
        }

        return $alerts;
    }

    /**
     * Movable stock of each strain across the stores — the same rule the *Trasladar* button uses.
     *
     * @param  list<string>  $geneticIds
     * @param  list<string>  $storeIds
     * @return array<string, array{cg: int, units: int, locations: list<string>}>
     */
    private static function storeStock(Organisation $organisation, array $geneticIds, array $storeIds): array
    {
        if ($geneticIds === [] || $storeIds === []) {
            return [];
        }

        $stock = [];
        $rows = Batch::query()->withoutGlobalScopes()
            ->where('batches.organisation_id', $organisation->id)
            ->whereIn('batches.genetic_id', $geneticIds)
            ->whereIn('batches.location_id', $storeIds)
            ->whereNull('batches.deleted_at')
            ->movable()
            ->groupBy('batches.genetic_id', 'batches.location_id')
            ->selectRaw('batches.genetic_id, batches.location_id, SUM(COALESCE(batches.remaining_cg, 0)) as cg, SUM(COALESCE(batches.remaining_units, 0)) as units')
            ->toBase()->get();
        foreach ($rows as $row) {
            $entry = $stock[$row->genetic_id] ?? ['cg' => 0, 'units' => 0, 'locations' => []];
            $entry['cg'] += (int) $row->cg;
            $entry['units'] += (int) $row->units;
            $entry['locations'][] = (string) $row->location_id;
            $stock[$row->genetic_id] = $entry;
        }

        return $stock;
    }

    /** @return list<array{type: AlertType, subject: string, location_id: ?string, detail: array<string, mixed>}> */
    private static function products(Organisation $organisation): array
    {
        return Article::query()->withoutGlobalScopes()
            ->where('organisation_id', $organisation->id)->where('active', true)->whereNull('deleted_at')
            ->lowStock()->orderBy('name')->get()
            ->map(fn (Article $article): array => [
                'type' => AlertType::PRODUCTS_LOW,
                'subject' => 'article:'.$article->id,
                'location_id' => $article->location_id,
                'detail' => ['name' => $article->name, 'stock' => (int) $article->stock, 'threshold' => (int) $article->low_stock_threshold],
            ])->values()->all();
    }

    /** @return list<array{type: AlertType, subject: string, location_id: ?string, detail: array<string, mixed>}> */
    private static function expiring(Organisation $organisation): array
    {
        $until = now()->addDays(max(1, (int) Settings::get('alerts_expiry_days', 14)))->toDateString();

        return Batch::query()->withoutGlobalScopes()
            ->where('batches.organisation_id', $organisation->id)->whereNull('batches.deleted_at')
            ->inStock()->whereNotNull('batches.expires_on')->whereDate('batches.expires_on', '<=', $until)
            ->orderBy('batches.expires_on')->get()
            ->map(fn (Batch $batch): array => [
                'type' => AlertType::BATCH_EXPIRING,
                'subject' => 'batch:'.$batch->id.':'.$batch->expires_on?->toDateString(),
                'location_id' => $batch->location_id,
                'detail' => [
                    'name' => $batch->displayTitle(),
                    'expires_on' => $batch->expires_on?->toDateString(),
                    'remaining' => $batch->isUnitType() ? (int) $batch->remaining_units : $batch->remaining_cg->centigrams,
                    'unit' => $batch->isUnitType(),
                ],
            ])->values()->all();
    }

    /** @return list<array{type: AlertType, subject: string, location_id: ?string, detail: array<string, mixed>}> */
    private static function tills(Organisation $organisation): array
    {
        $hours = max(1, (int) Settings::get('alerts_till_open_hours', 16));

        return TillSession::query()->withoutGlobalScopes()
            ->where('organisation_id', $organisation->id)->open()
            ->where('opened_at', '<', now()->subHours($hours))->orderBy('opened_at')->get()
            ->map(fn (TillSession $session): array => [
                'type' => AlertType::TILL_OPEN_TOO_LONG,
                'subject' => 'till:'.$session->id,
                'location_id' => $session->location_id,
                'detail' => ['terminal' => (string) $session->terminal, 'opened_at' => $session->opened_at?->toIso8601String()],
            ])->values()->all();
    }

    /** @return list<array{type: AlertType, subject: string, location_id: ?string, detail: array<string, mixed>}> */
    private static function unexplainedCloses(Organisation $organisation): array
    {
        $alerts = [];
        $sedes = Location::query()->withoutGlobalScopes()->where('organisation_id', $organisation->id)->where('active', true)
            ->where('kind', LocationKind::SEDE)->get();
        foreach ($sedes as $sede) {
            $count = TillSession::unexplainedClosesThisWeek($sede);
            if ($count > 0) {
                $alerts[] = [
                    'type' => AlertType::TILL_CLOSES_UNEXPLAINED,
                    'subject' => 'till-closes:'.Period::thisWeek($sede)->start->toDateString(),
                    'location_id' => $sede->id,
                    'detail' => ['count' => $count, 'url' => TillReportPage::getUrl(['diferencia' => 1, 'period' => 'week'])],
                ];
            }
        }

        return $alerts;
    }

    /** @return list<array{type: AlertType, subject: string, location_id: ?string, detail: array<string, mixed>}> */
    private static function losses(Organisation $organisation): array
    {
        $alerts = [];
        $sedes = Location::query()->withoutGlobalScopes()->where('organisation_id', $organisation->id)->where('active', true)
            ->where('kind', LocationKind::SEDE)->get();
        foreach ($sedes as $sede) {
            $day = LossesReport::yesterdayAboveThreshold($sede);
            if ($day !== null) {
                $alerts[] = [
                    'type' => AlertType::LOSSES_ABOVE_THRESHOLD,
                    'subject' => 'losses:'.$day['period']->start->toDateString(),
                    'location_id' => $sede->id,
                    'detail' => ['cents' => $day['total'], 'pct' => $day['pct'], 'url' => LossesReportPage::getUrl(['period' => 'yesterday', 'scope' => $sede->id])],
                ];
            }
            // Prompt 375 — the people over the threshold of their own takings this week: a count and the highest share, never a
            // name (a name beside a figure is a judgement about a person on a third-party server; names are in the report).
            $people = LossesReport::peopleAboveThreshold($sede);
            if ($people['count'] > 0) {
                $alerts[] = [
                    'type' => AlertType::LOSSES_PEOPLE_ABOVE_THRESHOLD,
                    'subject' => 'losses-people:'.Period::today($sede)->firstDay()->toDateString(),
                    'location_id' => $sede->id,
                    'detail' => ['count' => $people['count'], 'pct' => $people['pct'], 'url' => LossesReport::peopleUrl((string) $sede->id, $people['people'])], // 377
                ];
            }
        }

        return $alerts;
    }

    /** @return list<array{type: AlertType, subject: string, location_id: ?string, detail: array<string, mixed>}> */
    private static function system(): array
    {
        $alerts = [];
        foreach ((new SystemHealth)->heartbeats() as $component => $beat) {
            if ($beat['stale']) {
                $alerts[] = [
                    'type' => AlertType::SYSTEM,
                    'subject' => 'component:'.$component,
                    'location_id' => null,
                    'detail' => ['component' => $component, 'last_at' => $beat['last_at']?->toIso8601String()],
                ];
            }
        }

        return $alerts;
    }
}
