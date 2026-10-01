<?php

namespace App\Livewire\Counter\Concerns;

use App\Support\CounterLastSale;
use App\Support\Settings;

/**
 * Prompt 347 (Liam: "Landing page after dispensing to return to the main page rather than stay on dispensing to that
 * customer") — what the counter does once a sale is recorded, per sede (`after_recording`):
 *  - home (the default): the green confirmation shows for ~1.5 s, then the counter home, where the sale's «Última: …
 *    · Opciones» line stays for two minutes ({@see CounterLastSale});
 *  - new_member: stay on this screen with nobody selected;
 *  - stay: the behaviour before 347.
 * The basket is cleared by the caller as after any commit; nothing is lost.
 */
trait LandsAfterRecording
{
    protected function landAfterRecording(?string $dispensationId, ?string $orderId): void
    {
        $location = $this->resolveLocation();
        CounterLastSale::forget(); // the next sale replaces the last one on the hub, whatever the mode

        if ($location === null) {
            return;
        }

        $mode = (string) Settings::get('after_recording', 'home', (string) $location->getKey());

        if ($mode === 'stay') {
            return;
        }

        if ($mode === 'new_member') {
            $this->releaseMemberAfterRecording();

            return;
        }

        CounterLastSale::remember((string) $location->getKey(), $dispensationId, $orderId);
        $this->js('setTimeout(() => window.location.assign('.json_encode(route('counter.home'), JSON_UNESCAPED_SLASHES).'), 1500)');
    }

    /** Let go of the member just served (each screen knows how). */
    abstract protected function releaseMemberAfterRecording(): void;
}
