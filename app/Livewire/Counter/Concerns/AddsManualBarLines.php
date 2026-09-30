<?php

namespace App\Livewire\Counter\Concerns;

/**
 * The manual bar line (*línea manual*) — one set of rules for the Bar screen and the Dispensario's *Barra* tab (prompt
 * 331). A line outside the catalogue: a description, an amount above zero and a REQUIRED reason (126 made the reason one
 * tap), taken by the PIN operator's `pos.bar` (266). It is a bar/shop line only: a dispensation must name a batch and
 * grams, so there is no manual cannabis amount anywhere.
 *
 * The host puts the returned line in its own bar basket; `CommitOrder` stores it with no `article_id`, which is how the
 * receipt, the till, the Z report and 291's *Líneas manuales* recognise it. Never member-discounted. The modal is the one
 * partial `livewire.counter.partials.manual-line-modal`.
 */
trait AddsManualBarLines
{
    public string $miscDescription = '';

    /** Euros at the edge; parsed to integer cents before it ever leaves the component. */
    public string $miscAmount = '';

    /** A manual line REQUIRES a reason (CommitOrder enforces it; so do we). */
    public string $miscReference = '';

    /**
     * The line, validated — or null after saying why. On success the modal's fields are cleared and it is told to close;
     * a refusal keeps it open with the operator's input intact.
     *
     * @return array{description: string, unit_price_cents: int, reference: string, qty: int}|null
     */
    protected function takeManualLine(): ?array
    {
        // Prompt 266 — selling at the bar is the PIN operator's permission (255), checked where it runs.
        if ($this->hasOperator() && ! $this->userCan('pos.bar')) {
            $this->flash(__('Tu usuario no puede vender en la barra.'), 'error');

            return null;
        }

        $description = trim($this->miscDescription);
        $reference = trim($this->miscReference);
        $cents = $this->parseCents($this->miscAmount);

        if ($description === '') {
            $this->flash(__('Indica una descripción para la línea manual.'), 'error');

            return null;
        }

        if ($cents === null || $cents <= 0) {
            $this->flash(__('Introduce un importe válido.'), 'error');

            return null;
        }

        // Not in the catalogue, so a reason is still required — but the modal makes it one tap (prompt 126), so
        // satisfying it no longer pushes staff to type "x" or take cash off book.
        if ($reference === '') {
            $this->flash(__('Indica un motivo para la línea manual.'), 'error');

            return null;
        }

        $this->reset(['miscDescription', 'miscAmount', 'miscReference']);
        $this->dispatch('misc-added');

        return ['description' => $description, 'unit_price_cents' => $cents, 'reference' => $reference, 'qty' => 1];
    }
}
