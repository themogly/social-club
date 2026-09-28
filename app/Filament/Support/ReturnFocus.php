<?php

namespace App\Filament\Support;

/**
 * Prompt 295 — "Volver y corregir" puts the cursor back in the field to fix. Filament's modal cancel button keeps its own
 * `x-on:click="close()"`, so the button only NAMES the field (`data-below-cost-back`) and the modal window, which sees
 * the click first, focuses it once the modal has gone.
 */
final class ReturnFocus
{
    /** @return array<string, string> for the cancel button */
    public static function to(string $field): array
    {
        return ['data-below-cost-back' => $field];
    }

    /** @return array<string, string> for the modal window */
    public static function listener(): array
    {
        return ['x-on:click.capture' => 'const back = $event.target.closest(\'[data-below-cost-back]\'); '
            .'if (back) setTimeout(() => document.querySelector(`[id$=\'.${back.dataset.belowCostBack}\']`)?.focus(), 450)'];
    }
}
