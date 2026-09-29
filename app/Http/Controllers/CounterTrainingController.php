<?php

namespace App\Http\Controllers;

use App\Actions\Counter\StartTrainingMode;
use App\Models\Location;
use App\Support\CounterOperator;
use App\Support\TrainingMode;
use DomainException;
use Illuminate\Http\RedirectResponse;

/** Prompt 324 — enter and leave *Modo formación*. Both reload the screen, so every part of it re-renders in the new mode. */
class CounterTrainingController extends Controller
{
    public function start(): RedirectResponse
    {
        $locationId = session('counter.location_id');
        $location = is_string($locationId) ? Location::query()->find($locationId) : null;

        try {
            (new StartTrainingMode)->handle(CounterOperator::current(), $location);
        } catch (DomainException $e) {
            return back()->with('counter_training_refused', $e->getMessage());
        }

        return back();
    }

    public function leave(): RedirectResponse
    {
        TrainingMode::end('left');

        return back();
    }
}
