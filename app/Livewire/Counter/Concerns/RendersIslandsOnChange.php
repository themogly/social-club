<?php

namespace App\Livewire\Counter\Concerns;

use Livewire\Attributes\Locked;

/**
 * Prompt 293 — a part of a counter screen that rarely changes is re-sent only when what it SHOWS has changed.
 *
 * Every tap on the dispensary and the bar used to return the whole screen, catalogue included: 160–175 KB per basket
 * action at club size, growing with every genetic and article a club added. The catalogue pane (and the dispensary's
 * photo nag) are now Livewire islands — `@island('…', always: $this->islandChanged('…'))` — and this decides, per
 * render, whether each one goes.
 *
 * WHY `always:` AND NOT A TARGETED `renderIsland()`. Livewire 4.4.6 registers an island only on the component's
 * FIRST render, and a later render emits an unregistered island as an empty "skip" marker. The dispensary mounts
 * with no socio — the catalogue is not on the page at all until one is identified — so a plain island would arrive
 * EMPTY the moment the work screen appeared. `always:` is read on every render, so passing "this island changed"
 * renders it in place whenever it is new or different, and skips it (the browser keeps what it has) otherwise.
 *
 * "Changed" is a fingerprint of the island's own data — prices for THIS socio, stock, the sellable set, the labels
 * in this locale — so nothing is remembered that could go stale: a sale at another terminal, a price edit in the panel
 * or a new socio all change the data, and the island follows. An island that a render does not reach at all (a
 * blocking state replaced it) is forgotten, so its return always carries it in full.
 */
trait RendersIslandsOnChange
{
    /**
     * What each island last showed. #[Locked]: the client can neither force nor suppress a re-render.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $islandFingerprints = [];

    /** @var array<string, true> */
    private array $islandsReached = [];

    /** @var array<string, array<string, mixed>> */
    private array $islandMemo = [];

    /**
     * Everything one island renders. An island is a separate view that sees only public properties and `$this`, so
     * its data comes from here rather than from `render()` — and the fingerprint is taken over exactly this.
     *
     * @return array<string, mixed>
     */
    abstract protected function islandData(string $island): array;

    /** @return array<string, mixed> */
    public function islandView(string $island): array
    {
        return $this->islandMemo[$island] ??= $this->islandData($island);
    }

    /** Read by the island's `always:` on every render that reaches it: true when it is new or different. */
    public function islandChanged(string $island): bool
    {
        $this->islandsReached[$island] = true;
        $fingerprint = md5(serialize([app()->getLocale(), $this->islandView($island)]));
        $changed = ($this->islandFingerprints[$island] ?? null) !== $fingerprint;
        $this->islandFingerprints[$island] = $fingerprint;

        return $changed;
    }

    public function renderedRendersIslandsOnChange(): void
    {
        // An island this render did not reach is no longer on the page: forget it, so its return carries it in full.
        $this->islandFingerprints = array_intersect_key($this->islandFingerprints, $this->islandsReached);
        $this->islandsReached = [];
    }
}
