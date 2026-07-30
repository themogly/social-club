<?php

namespace App\Actions\Till;

use App\Enums\TillSessionStatus;
use App\Exceptions\TillAlreadyOpenException;
use App\Models\Location;
use App\Models\TillSession;
use App\Support\CounterOperator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Open a till session with a float. One open session per terminal per location. */
class OpenTill
{
    /**
     * @param  array{operator_id?: ?string, notes?: ?string}  $options
     */
    public function handle(Location $location, string $terminal, int $floatCents, array $options = []): TillSession
    {
        return DB::transaction(function () use ($location, $terminal, $floatCents, $options): TillSession {
            $open = TillSession::query()->withoutGlobalScopes()
                ->where('location_id', $location->id)->where('terminal', $terminal)
                ->where('status', TillSessionStatus::OPEN->value)->lockForUpdate()->first();

            if ($open !== null) {
                throw new TillAlreadyOpenException("Terminal {$terminal} already has an open till session.");
            }

            return TillSession::create([
                'organisation_id' => $location->organisation_id,
                'location_id' => $location->id,
                'terminal' => $terminal,
                'opened_by' => $options['operator_id'] ?? CounterOperator::id() ?? Auth::id(),
                'opened_at' => now(),
                'float_cents' => $floatCents,
                'status' => TillSessionStatus::OPEN,
                'notes' => $options['notes'] ?? null,
            ]);
        });
    }
}
