<?php

namespace App\Actions\Counter;

use App\Models\Location;
use App\Models\User;
use App\Support\CounterBasket;
use App\Support\Settings;
use App\Support\TrainingMode;
use DomainException;

/**
 * Prompt 324 — *Modo formación*, entered by the operator at the PIN, at a sede that allows it (*Permitir modo
 * formación*, on by default). Refused mid-visit: a basket with items, or a socio held on the dispensary or the bar —
 * starting would silently discard real work. Audited `counter.training.started` (it runs outside any training
 * rollback: training is off when this is called).
 */
class StartTrainingMode
{
    /** @throws DomainException with the reason it can't start */
    public function handle(?User $operator, ?Location $location): void
    {
        if ($operator === null) {
            throw new DomainException(__('Identifícate con tu PIN antes de entrar en modo formación.'));
        }
        if ($location === null) {
            throw new DomainException(__('Elige una sede antes de entrar en modo formación.'));
        }
        if (! (bool) Settings::get('counter_training_enabled', true, $location->id)) {
            throw new DomainException(__('El modo formación no está permitido en esta sede.'));
        }
        if (TrainingMode::active()) {
            return;
        }
        if (CounterBasket::inProgress($location->id)) {
            throw new DomainException(__('Termina o vacía la visita en curso antes de entrar en modo formación.'));
        }

        TrainingMode::start($operator->id, $location->id);
    }
}
