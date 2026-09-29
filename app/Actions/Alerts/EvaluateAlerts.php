<?php

namespace App\Actions\Alerts;

use App\Models\Organisation;
use App\Models\OwnerAlertState;
use App\Support\Alerts\CurrentAlerts;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Prompt 311 — compare what holds now ({@see CurrentAlerts}) with what was already over the line: a condition that is
 * NEW opens a state (and is returned, to be announced once); one still holding keeps its state (its figures refreshed);
 * one that no longer holds is cleared. So an item that stays low for three days is one message, and an item that recovers
 * and drops again is a second one. A strain moving between *Reponer desde el almacén* and *Se acaba* is a different
 * type, so the old one clears and the new one fires once.
 */
class EvaluateAlerts
{
    /** @return Collection<int, OwnerAlertState> the alerts that are new this run */
    public function handle(Organisation $organisation): Collection
    {
        return DB::transaction(function () use ($organisation): Collection {
            $current = collect(CurrentAlerts::for($organisation))
                ->keyBy(fn (array $alert): string => OwnerAlertState::keyOf($alert['type'], $alert['subject'], $alert['location_id']));
            $active = OwnerAlertState::query()->withoutGlobalScopes()->where('organisation_id', $organisation->id)->active()
                ->lockForUpdate()->get()->keyBy(fn (OwnerAlertState $state): string => $state->key());

            $new = new EloquentCollection;
            foreach ($current as $key => $alert) {
                $state = $active->get($key);
                if ($state !== null) {
                    $state->update(['detail' => $alert['detail']]);

                    continue;
                }
                $new->push(OwnerAlertState::query()->withoutGlobalScopes()->create([
                    'organisation_id' => $organisation->id,
                    'type' => $alert['type'],
                    'subject' => $alert['subject'],
                    'location_id' => $alert['location_id'],
                    'detail' => $alert['detail'],
                    'active_since' => now(),
                ]));
            }

            foreach ($active as $key => $state) {
                if (! $current->has($key)) {
                    $state->update(['cleared_at' => now()]);
                }
            }

            return $new;
        });
    }
}
