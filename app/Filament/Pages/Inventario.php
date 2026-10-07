<?php

namespace App\Filament\Pages;

use App\Actions\Stock\CancelStockCount;
use App\Actions\Stock\CommitStockTake;
use App\Actions\Stock\RecordStockCountLine;
use App\Actions\Stock\StartStockCount;
use App\Enums\StockCountReason;
use App\Enums\StockTakeStatus;
use App\Models\Location;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\User;
use App\Support\LocationSwitcher;
use App\Support\OrganisationIdentity;
use App\ViewModels\StockCountSheet;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Prompt 318 — *Inventario*: a full stock count for one sede (stores included), on the existing stock_takes. Three
 * views of one page: the list of counts, the count itself (grouped by type, searchable, blind by default, each line
 * saved as it is entered), and the review (differences by size, reasons above the tolerance, *Aplicar ajustes*).
 * Everything that writes goes through the Actions; the figures come from {@see StockCountSheet}.
 *
 * The counter keeps trading during a count — nothing is locked — which is why each line snapshots the system quantity
 * when it is counted ({@see RecordStockCountLine}). The till's own closing recount is a different kind of take and is
 * not listed here.
 */
class Inventario extends Page
{
    protected string $view = 'filament.pages.inventario';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $slug = 'inventario';

    protected static ?int $navigationSort = 25; // after Lotes

    #[Url]
    public ?string $count = null;

    #[Url]
    public string $mode = 'count';

    public string $lineFilter = ''; // a catalogue filter, not a member search (OneMemberLookupTest)

    public bool $pendingOnly = false;

    /** @var array<string, string> line id → what was typed */
    public array $entries = [];

    /** @var array<string, string> line id → the sealed reserve typed (prompt 360; weight batches) */
    public array $reserveEntries = [];

    /** Prompt 360 — «Usar un motivo para todas las diferencias»: one reason for every row with none of its own. */
    public bool $useSharedReason = false;

    public string $sharedReason = StockCountReason::RESERVE_REGULARISATION->value;

    /** @var array<string, string> line id → why it was not counted */
    public array $notCounted = [];

    /** @var array<string, array{reason?: ?string, note?: ?string}> line id → reason and note for its difference */
    public array $reasons = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('stock.take') && $user->can('panel.stock_count');
    }

    public static function getNavigationLabel(): string
    {
        return __('Inventario');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Dispensario');
    }

    public function getTitle(): string
    {
        return $this->count === null ? __('Inventario') : __('Inventario — :sede', ['sede' => (string) $this->take()->location?->name]);
    }

    public function mount(): void
    {
        if ($this->count !== null) {
            $this->take(); // refuses a count outside the viewer's sedes
            $this->loadEntries();
        }
        $this->mode = in_array($this->mode, ['count', 'review'], true) ? $this->mode : 'count';
    }

    /** The open count, or 403 when it is not an Inventario at a sede the viewer may reach. */
    public function take(): StockTake
    {
        $take = StockTake::query()->withoutGlobalScopes()->inventories()->find($this->count);
        $user = Auth::user();
        abort_unless($take instanceof StockTake && $user instanceof User && app(LocationSwitcher::class)->canAccess($user, $take->location_id), 403);

        return $take;
    }

    public function sheet(): StockCountSheet
    {
        return new StockCountSheet($this->take());
    }

    /** @return array<string, string> the sedes (and stores) the viewer may count, id → name */
    public function sedeOptions(): array
    {
        $user = Auth::user();

        return $user instanceof User
            ? app(LocationSwitcher::class)->available($user, includeStores: true)->pluck('name', 'id')->all()
            : [];
    }

    /** @return list<array{take: StockTake, totals: array<string, mixed>}> the counts at the viewer's sedes, newest first */
    public function counts(): array
    {
        return StockTake::query()->withoutGlobalScopes()->inventories()
            ->whereIn('location_id', array_keys($this->sedeOptions()))
            ->latest('opened_at')->limit(50)->get()
            ->map(fn (StockTake $take): array => ['take' => $take, 'totals' => (new StockCountSheet($take))->totals()])->all();
    }

    /** @return list<array<string, mixed>> the count's lines after the search and the pending filter, still grouped */
    public function visibleGroups(): array
    {
        $needle = mb_strtolower(trim($this->lineFilter));
        $groups = [];
        foreach ($this->sheet()->groups() as $group => $rows) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => ($needle === '' || str_contains(mb_strtolower($row['name']), $needle))
                && (! $this->pendingOnly || ! $row['settled'])));
            if ($rows !== []) {
                $groups[] = ['name' => $group, 'rows' => $rows];
            }
        }

        return $groups;
    }

    /** @return array<string, string> */
    public function reasonOptions(): array
    {
        return collect(StockCountReason::cases())->mapWithKeys(fn (StockCountReason $r): array => [$r->value => $r->label()])->all();
    }

    protected function getHeaderActions(): array
    {
        return $this->count === null ? [$this->startAction()] : [$this->backAction(), $this->cancelAction()];
    }

    public function startAction(): Action
    {
        return Action::make('start')->label(__('Nuevo inventario'))
            ->schema([
                Select::make('location_id')->label(__('Sede'))->options(fn (): array => $this->sedeOptions())->required()
                    ->default(array_key_first($this->sedeOptions()))->live()
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set('include_zero', self::suggestsZero($state))),
                // Prompt 360 — the go-live cleanup: batches the old evening reweigh zeroed while their bags sat in the back.
                Toggle::make('include_zero')->label(__('Incluir lotes a cero'))
                    ->helperText(__('Lista también los lotes de flor que el sistema da por vacíos, para poder darles su bote y su reserva. Los que dejes en blanco no se tocan.'))
                    ->default(fn (): bool => self::suggestsZero(array_key_first($this->sedeOptions()))),
            ])
            ->modalDescription(__('Se crea una línea por cada lote con existencias (en el bote o en reserva) y cada producto activo de la sede. La barra sigue funcionando mientras se cuenta. Si ya hay un inventario abierto en esa sede, se abre ese.'))
            ->action(function (array $data): void {
                $location = Location::query()->withoutGlobalScopes()->findOrFail($data['location_id']);
                abort_unless(array_key_exists($location->id, $this->sedeOptions()), 403);
                /** @var User $user */
                $user = Auth::user();
                $take = (new StartStockCount)->handle($location, $user, includeZero: (bool) ($data['include_zero'] ?? false));
                $this->redirect(self::getUrl(['count' => $take->id]));
            });
    }

    private static function suggestsZero(?string $locationId): bool
    {
        $location = $locationId === null ? null : Location::query()->withoutGlobalScopes()->find($locationId);

        return $location !== null && StartStockCount::suggestsZeroBatches($location);
    }

    public function backAction(): Action
    {
        return Action::make('back')->label(__('Volver a inventarios'))->color('gray')->icon(Heroicon::OutlinedArrowLeft)
            ->url(self::getUrl());
    }

    public function cancelAction(): Action
    {
        return Action::make('cancel')->label(__('Cancelar inventario'))->color('danger')
            ->visible(fn (): bool => $this->count !== null && $this->take()->isOpen())
            ->requiresConfirmation()
            ->modalDescription(__('Se descarta el conteo. No se toca ninguna existencia; queda registrado quién lo canceló.'))
            ->action(function (): void {
                /** @var User $user */
                $user = Auth::user();
                (new CancelStockCount)->handle($this->take(), $user);
                Notification::make()->title(__('Inventario cancelado'))->success()->send();
                $this->redirect(self::getUrl());
            });
    }

    public function applyAction(): Action
    {
        return Action::make('apply')->label(__('Aplicar ajustes'))
            ->visible(fn (): bool => $this->count !== null && $this->take()->isOpen())
            ->requiresConfirmation()
            ->modalDescription(fn (): string => trim(__('Se registrará un ajuste por cada línea con diferencia (:count), con su motivo. Diferencia neta: :value.', [
                'count' => $this->sheet()->totals()['differences'], 'value' => $this->sheet()->totals()['net_value'],
            ]).' '.$this->sheet()->ceilingWarning()))
            ->action(function (): void {
                /** @var User $user */
                $user = Auth::user();
                try {
                    (new CommitStockTake)->applyCount($this->take(), $user, $this->reasons,
                        $this->useSharedReason ? StockCountReason::tryFrom($this->sharedReason) : null);
                } catch (DomainException|RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()->title(__('Ajustes aplicados'))->success()->send();
                $this->redirect(self::getUrl(['count' => $this->count, 'mode' => 'review']));
            });
    }

    public function saveLine(string $lineId): void
    {
        $this->record($lineId, fn (StockTakeLine $line, User $user) => (new RecordStockCountLine)->handle($line, $this->entries[$lineId] ?? '', $user,
            reserve: $this->reserveEntries[$lineId] ?? ''));
    }

    public function markNotCounted(string $lineId): void
    {
        $this->record($lineId, fn (StockTakeLine $line, User $user) => (new RecordStockCountLine)->handle($line, null, $user, notCountedReason: $this->notCounted[$lineId] ?? ''));
    }

    /** A reason or note typed in the review is kept on the line (so a review can be left and resumed). */
    public function updatedReasons(mixed $value, string $key): void
    {
        [$lineId] = explode('.', $key);
        $line = $this->line($lineId);
        if ($line === null || ! $this->take()->isOpen()) {
            return;
        }
        $line->forceFill([
            'adjustment_reason' => StockCountReason::tryFrom((string) ($this->reasons[$lineId]['reason'] ?? '')),
            'adjustment_note' => trim((string) ($this->reasons[$lineId]['note'] ?? '')) ?: null,
        ])->save();
    }

    public function downloadReport(string $id): StreamedResponse
    {
        $this->count ??= $id;
        abort_unless($this->count === $id, 403);
        $take = $this->take();
        $content = Pdf::loadHTML(self::reportView($take)->render())->output();

        return response()->streamDownload(fn () => print ($content), 'inventario-'.$take->opened_at?->format('Y-m-d').'.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** The printable report (the PDF's source): every line, who counted it and when, the differences and the totals. */
    public static function reportView(StockTake $take): View
    {
        $sheet = new StockCountSheet($take);

        return view('documents.inventario', [
            'identity' => OrganisationIdentity::current(),
            'take' => $take,
            'sheet' => $sheet,
            'rows' => $sheet->rows(),
            'totals' => $sheet->totals(),
            'generatedAt' => now(),
        ]);
    }

    private function record(string $lineId, callable $write): void
    {
        $line = $this->line($lineId);
        $user = Auth::user();
        if ($line === null || ! $user instanceof User) {
            return;
        }
        try {
            $write($line, $user);
        } catch (DomainException|AuthorizationException $e) {
            $this->addError('entries.'.$lineId, $e->getMessage());

            return;
        }
        $this->resetErrorBag('entries.'.$lineId);
        $this->loadEntries();
    }

    private function line(string $lineId): ?StockTakeLine
    {
        return $this->take()->lines()->whereKey($lineId)->first();
    }

    private function loadEntries(): void
    {
        foreach ($this->sheet()->rows() as $row) {
            $this->entries[$row['id']] = $row['counted_input'];
            $this->reserveEntries[$row['id']] = $row['counted_reserve_input'];
            $this->notCounted[$row['id']] = (string) $row['not_counted_reason'];
            $this->reasons[$row['id']] = ['reason' => $row['reason'], 'note' => (string) $row['note']];
        }
    }

    public function isOpen(): bool
    {
        return $this->count !== null && $this->take()->status === StockTakeStatus::OPEN;
    }
}
