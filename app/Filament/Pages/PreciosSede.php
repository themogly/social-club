<?php

namespace App\Filament\Pages;

use App\Actions\Pricing\SaveSedePrices;
use App\Enums\PriceList;
use App\Filament\Resources\Batches\BatchResource;
use App\Models\Batch;
use App\Models\Location;
use App\Models\User;
use App\Support\ActiveScope;
use App\Support\LocationSwitcher;
use App\Support\TypedNumber;
use App\ViewModels\SedePriceSheet;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Prompt 382 — *Precios de la sede*: price a whole sede in one go. One row per open batch with stock, the Estándar, Local and
 * Personal prices (per gram and 3.5 g, or per unit) as cells; a blank Local / Personal cell shows its default in grey, a cell
 * below cost turns amber (a warning, never a block). Quick fills only fill the cells; «Guardar todo» writes the changed
 * batches in one transaction ({@see SaveSedePrices}). `prices.manage` at the viewer's sedes (a manager: theirs; the owner:
 * all); staff are refused. Thin: the rows come from {@see SedePriceSheet}.
 */
class PreciosSede extends Page
{
    protected string $view = 'filament.pages.precios-sede';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $slug = 'precios';

    protected static ?int $navigationSort = 21; // after Lotes

    #[Url]
    public ?string $sede = null;

    /** @var array<string, array<string, string>> batch id → column → euros as typed */
    public array $prices = [];

    /** @var list<string> the rows a quick fill applies to (none = every row shown) */
    public array $selected = [];

    public string $search = ''; // a strain filter, not a member search (OneMemberLookupTest)

    #[Url(as: 'sin_precio')]
    public bool $onlyUnpriced = false; // the Sedes badge opens it filtered (382)

    public bool $onlyBelowCost = false;

    public string $fillPct = '10';

    public string $copyFrom = '';

    /** The sheet for this request (rebuilt when the sede changes); never carried between requests. */
    private ?SedePriceSheet $sheet = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        // The batches it prices are Lotes' (stock.manage); prices.manage alone is also a counter permission (356's link).
        return $user instanceof User && $user->can('prices.manage') && $user->can('stock.manage') && $user->can('panel.access');
    }

    public static function getNavigationLabel(): string
    {
        return __('Precios');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Dispensario');
    }

    public function getTitle(): string
    {
        return __('Precios de la sede');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $options = $this->sedeOptions();
        if ($this->sede === null || ! isset($options[$this->sede])) {
            $active = app(ActiveScope::class)->locationId();
            $this->sede = $active !== null && isset($options[$active]) ? $active : (array_key_first($options) ?? null);
        }
        $this->load();
    }

    public function updatedSede(): void
    {
        abort_unless($this->sede !== null && isset($this->sedeOptions()[$this->sede]), 403);
        $this->selected = [];
        $this->load();
    }

    /** @return array<string, string> the sedes (and stores) the viewer may price, id → name */
    public function sedeOptions(): array
    {
        $user = Auth::user();

        return $user instanceof User
            ? app(LocationSwitcher::class)->available($user, includeStores: true)->pluck('name', 'id')->map(fn (mixed $n): string => (string) $n)->all()
            : [];
    }

    public function location(): ?Location
    {
        $user = Auth::user();
        if ($this->sede === null || ! $user instanceof User || ! app(LocationSwitcher::class)->canAccess($user, $this->sede)) {
            return null;
        }

        return Location::query()->withoutGlobalScopes()->find($this->sede);
    }

    public function sheet(): ?SedePriceSheet
    {
        if ($this->sheet?->locationId() !== $this->sede) {
            $location = $this->location();
            $this->sheet = $location === null ? null : new SedePriceSheet($location);
        }

        return $this->sheet;
    }

    /** @return list<array<string, mixed>> the rows after the search and the filters */
    public function visibleRows(): array
    {
        return $this->sheet()?->visibleRows($this->prices, $this->search, $this->onlyUnpriced, $this->onlyBelowCost) ?? [];
    }

    /** @return array<string, string> the other sedes to copy from */
    public function copyOptions(): array
    {
        return array_diff_key($this->sedeOptions(), [(string) $this->sede => true]);
    }

    /** Quick fill «Local = Estándar −__ %» / «Personal = Estándar −__ %»: fills the cells, saves nothing. */
    public function fillFromStandard(string $list): void
    {
        $priceList = PriceList::tryFrom($list);
        $percent = TypedNumber::canonical($this->fillPct);
        if ($priceList === null || $priceList === PriceList::STANDARD || $percent === null || (float) $percent > 100) {
            $this->addError('fillPct', __('Escribe un porcentaje entre 0 y 100.'));

            return;
        }
        $this->prices = $this->sheet()?->filledFromStandard($this->prices, $this->targets(), $priceList, (float) $percent) ?? $this->prices;
    }

    /** Quick fill «Copiar precios de otra sede»: for the same strain, that sede's current batch's prices. Fills, saves nothing. */
    public function copyFromSede(): void
    {
        $user = Auth::user();
        $other = isset($this->copyOptions()[$this->copyFrom]) && $user instanceof User && app(LocationSwitcher::class)->canAccess($user, $this->copyFrom)
            ? Location::query()->withoutGlobalScopes()->find($this->copyFrom) : null;
        $sheet = $this->sheet();
        if ($other === null || $sheet === null) {
            $this->addError('copyFrom', __('Elige la sede de la que copiar.'));

            return;
        }
        [$this->prices, $copied] = $sheet->copiedFrom($this->prices, $this->targets(), $other);
        Notification::make()->title(trans_choice(':count lote rellenado desde :sede (sin guardar)|:count lotes rellenados desde :sede (sin guardar)', $copied, ['count' => $copied, 'sede' => $other->name]))->info()->send();
    }

    /** Quick fill «Vaciar Local / Personal»: back to the defaults. Fills, saves nothing. */
    public function clearLists(): void
    {
        $this->prices = $this->sheet()?->cleared($this->prices, $this->targets()) ?? $this->prices;
    }

    /** «Guardar todo» — the changed batches, in one transaction, an audit row each. */
    public function save(): void
    {
        $location = $this->location();
        $user = Auth::user();
        abort_unless($location instanceof Location && $user instanceof User, 403);

        $updated = (new SaveSedePrices)->handle($location, $this->prices, $user);
        $this->load();

        Notification::make()
            ->title($updated === 0 ? __('No había cambios') : trans_choice(':count lote actualizado|:count lotes actualizados', $updated, ['count' => $updated]))
            ->success()->send();
    }

    /** The prices typed differ from the saved ones (the «Guardar todo» button says so). */
    public function isDirty(): bool
    {
        return $this->prices !== ($this->sheet()?->stored() ?? []);
    }

    /** @return list<string> the selected visible batches, or every visible one when none is selected */
    private function targets(): array
    {
        $shown = array_column($this->visibleRows(), 'id');
        $chosen = array_values(array_intersect($shown, $this->selected));

        return $chosen === [] ? $shown : $chosen;
    }

    private function load(): void
    {
        $this->prices = $this->sheet()?->stored() ?? [];
        $this->resetErrorBag();
    }

    /** The Lotes link back for a row. */
    public function batchUrl(Batch $batch): string
    {
        return BatchResource::getUrl('edit', ['record' => $batch]);
    }
}
