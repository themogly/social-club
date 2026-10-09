<?php

namespace App\Livewire\Counter\Concerns;

/**
 * Prompt 374 — a success that tells staff which box the cash goes in («Pon 4.00 € en el bote de comestibles») waits for the
 * next action instead of fading after 234's six seconds: it is read while handing over the product and counting change.
 * Every screen's `flash()` clears it; the host sets it after a success with a box sentence.
 */
trait KeepsBoxInstruction
{
    public bool $flashKeeps = false;

    protected function keepFlashFor(?string $boxes): void
    {
        $this->flashKeeps = $boxes !== null;
    }
}
