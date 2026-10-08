<?php

namespace App\Livewire\Counter;

use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Livewire\Counter\Concerns\ResolvesCounterLocation;
use App\Models\Location;
use App\ViewModels\CounterDaySheet;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Prompt 371 — «Hoy»: the day's sheet at the counter (Liam: "we just click on Today and we get a sheet-equivalent rundown of
 * the day's transactions"). Every sale at this sede today, oldest first, dispensary and bar together, with the totals a
 * paper sheet has ({@see CounterDaySheet}). Read-only: nothing here writes, so training mode and the idle lock need nothing
 * extra, and a handover renders only its surface ({@see IdentifiesOperator}).
 *
 * Opens for anyone who dispenses or serves (`pos.use` or `pos.bar`). THE MONEY follows the home panel's rule: amounts and
 * the € totals only for `reports.view` (staff check what was handed to whom, in grams and units). CSV for `reports.export`.
 */
#[Layout('components.layouts.counter')]
class TodaySheet extends Component
{
    use IdentifiesOperator, ResolvesCounterLocation;

    public ?string $locationId = null;

    public bool $noLocation = false;

    /** all | dispensary | bar */
    public string $source = 'all';

    /** «Solo lo mío»: the PIN operator's own sales. */
    public bool $mine = false;

    /** Someone else who worked today (their id), or null for everyone. */
    public ?string $operatorFilter = null;

    /** Member number or name — a filter over the day's rows, not a member search (OneMemberLookupTest). */
    public string $memberFilter = '';

    public ?string $flashMessage = null;

    public string $flashType = 'success';

    public int $flashSeq = 0;

    public function mount(): void
    {
        abort_unless($this->deviceCan('pos.use') || $this->deviceCan('pos.bar'), 403);
        $this->resolveCounterLocation();
    }

    public function setSource(string $source): void
    {
        $this->source = in_array($source, ['all', 'dispensary', 'bar'], true) ? $source : 'all';
    }

    public function showOperator(?string $operatorId): void
    {
        $this->mine = false;
        $this->operatorFilter = $operatorId;
    }

    public function sheet(): CounterDaySheet
    {
        $operator = $this->mine ? $this->counterActor()?->id : $this->operatorFilter;

        return new CounterDaySheet($this->resolveLocation() ?? new Location, $this->source, $operator === null ? null : (string) $operator, $this->memberFilter);
    }

    /** The home panel's money rule (prompt 205): € figures only for reports.view. */
    public function showMoney(): bool
    {
        return $this->userCan('reports.view');
    }

    private function resolveLocation(): ?Location
    {
        return $this->locationId !== null ? Location::query()->find($this->locationId) : null;
    }

    protected function flash(string $message, string $type): void
    {
        $this->flashSeq++;
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    public function render(): View
    {
        $this->applyCounterScope();
        $sheet = $this->sheet();
        $location = $this->resolveLocation();

        return view('livewire.counter.today-sheet', [
            'rows' => $location !== null ? $sheet->rows() : [],
            'totals' => $location !== null ? $sheet->totals() : null,
            'operators' => $location !== null ? $sheet->operators() : [],
            'location' => $location,
            'day' => $location !== null ? $sheet->period()->start->setTimezone($location->timezone ?: 'Europe/Madrid') : null,
            'showMoney' => $this->showMoney(),
            'canExport' => $this->userCan('reports.export'),
        ]);
    }
}
