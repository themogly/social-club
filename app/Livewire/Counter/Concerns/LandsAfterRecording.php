<?php

namespace App\Livewire\Counter\Concerns;

use App\Support\CashBoxes;
use App\Support\CounterLastSale;
use App\Support\Settings;

/**
 * Prompt 347 (Liam: "Landing page after dispensing to return to the main page rather than stay on dispensing to that
 * customer") — what the counter does once a sale is recorded, per sede (`after_recording`):
 *  - home (the default): the green confirmation shows for ~1.5 s, then the counter home, where the sale's «Última: …
 *    · Opciones» line stays for two minutes ({@see CounterLastSale}) — with the «which box» instruction, prompt 374;
 *  - new_member: stay on this screen with nobody selected;
 *  - stay: the behaviour before 347.
 * The basket is cleared by the caller as after any commit; nothing is lost.
 */
trait LandsAfterRecording
{
    use KeepsBoxInstruction;

    /**
     * @param  ?string  $boxes  Prompt 374 — the sale's «Pon … en el bote …» sentence ({@see CashBoxes::sentence()}), or null:
     *                          the hub's last-sale line carries it until the next sale; on this screen it waits for the next
     *                          action.
     */
    protected function landAfterRecording(?string $dispensationId, ?string $orderId, ?string $boxes = null): void
    {
        $location = $this->resolveLocation();
        CounterLastSale::forget(); // the next sale replaces the last one on the hub, whatever the mode
        $this->keepFlashFor($boxes);

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

        CounterLastSale::remember((string) $location->getKey(), $dispensationId, $orderId, $boxes);
        $this->js('setTimeout(() => window.location.assign('.json_encode(route('counter.home'), JSON_UNESCAPED_SLASHES).'), 1500)');
    }

    /** Let go of the member just served (each screen knows how). */
    abstract protected function releaseMemberAfterRecording(): void;
}
