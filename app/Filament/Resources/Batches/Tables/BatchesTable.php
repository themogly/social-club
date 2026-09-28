<?php

namespace App\Filament\Resources\Batches\Tables;

use App\Actions\Pricing\SetBatchPrice;
use App\Actions\Stock\RecordStockMovement;
use App\Actions\Stock\TransferBatch;
use App\Enums\BatchStatus;
use App\Enums\StockMovementType;
use App\Filament\Support\ReturnFocus;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\User;
use App\Support\BelowCost;
use App\Support\Money;
use App\Support\Spreadsheet\ReportExport;
use App\Support\Weight;
use App\ViewModels\BatchRecall;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Prompt 298 — WHAT the batch is first: the strain in bold, the club's name (282) or the automatic
                // description under it. One search box finds it by strain, name or lote number; it sorts by strain,
                // then by its lote's place in the strain.
                TextColumn::make('lote')
                    ->label(__('Lote'))
                    ->state(fn (Batch $record): string => $record->displayTitle())
                    ->weight(FontWeight::SemiBold)
                    ->description(fn (Batch $record): string => $record->displaySubtitle())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q): Builder => $q
                        ->where('batches.label', 'like', "%{$search}%")
                        ->orWhere('batches.batch_no', 'like', "%{$search}%")
                        ->orWhereHas('genetic', fn (Builder $g): Builder => $g->where('name', 'like', "%{$search}%"))))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy(Genetic::query()->withoutGlobalScopes()->select('name')->whereColumn('genetics.id', 'batches.genetic_id'), $direction)
                        ->orderBy('batches.lote_seq', $direction)),
                // The lote number is traceability, not how people find a batch: there when switched on.
                TextColumn::make('batch_no')->label(__('Nº lote'))->sortable()->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('genetic.product_type')->label(__('Tipo'))->badge()->toggleable(),
                // Where the stock IS (prompt 148). Shown only when the org has more than one active sede — a
                // column that reads the same on every row in a single-sede club is noise; it is essential the
                // moment there are two.
                TextColumn::make('location.name')
                    ->label(__('Sede'))
                    ->searchable()
                    ->sortable()
                    ->visible(fn (): bool => Location::query()->active()->count() > 1),
                TextColumn::make('remaining')
                    ->label(__('Restante'))
                    ->state(function (Batch $record): string {
                        if ($record->isUnitType()) {
                            $units = (int) ($record->remaining_units ?? 0);

                            return $units.' '.__('uds').' ('.number_format($record->onHandCg() / 100, 2).' g)';
                        }

                        return number_format($record->remaining_cg->centigrams / 100, 2).' g';
                    }),
                // Prompt 278 — the batch's own sale price ("—" = none yet: the strain's sede price applies, if any).
                TextColumn::make('sale_price')
                    ->label(__('Precio'))
                    ->state(fn (Batch $record): string => ($rate = $record->isUnitType() ? $record->price_per_unit_cents : $record->price_per_gram_cents) !== null
                        ? Money::fromCents((int) $rate)->formatted().($record->isUnitType() ? __('/ud') : __('/g'))
                        : '—'),
                TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->color(fn (BatchStatus $state): string => match ($state) {
                        BatchStatus::OPEN => 'success',
                        BatchStatus::QUARANTINED => 'warning',
                        BatchStatus::CLOSED => 'gray',
                    }),
                // ->placeholder: an empty cell in a clickable Filament row is still an <a>, and one with no
                // text is a link a screen reader cannot name (a11y audit). A batch with no expiry is common.
                TextColumn::make('expires_on')->label(__('Caduca'))->date()->sortable()->placeholder('—'),
            ])
            ->filters([
                // Which sede's stock (prompt 238). Only when there is more than one — the same reason the
                // location COLUMN is conditional: a filter that offers one option on every row is noise.
                SelectFilter::make('location_id')
                    ->label(__('Sede'))
                    ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible(fn (): bool => Location::query()->count() > 1),
                TrashedFilter::make(),
            ])
            // The worst offender: four labelled buttons, a 335px actions column — a third of the whole
            // table. Retirada, Ajuste and Merma are all destructive or rare, which is exactly what
            // belongs behind a trigger (prompt 170).
            ->recordActions([
                ActionGroup::make([
                    self::recallAction(),
                    self::transferAction(),
                    self::priceAction(),
                    self::adjustAction(),
                    self::mermaAction(),
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ])
            // Day one of a real club, EVERY one of these tables is empty; a framework shrug is the
            // first thing a new owner sees (admin audit, Phase C). Say what the screen is for and
            // what to do first.
            ->emptyStateHeading(__('Sin lotes'))
            ->emptyStateDescription(__('Un lote es stock real de una genética en una sede. Registra una compra o una cosecha para tener algo que dispensar.'));
    }

    /**
     * Retirada (recall) — who received product from this batch, how much and when, for a health recall
     * (prompt 86). Read-only; opens the affected-member list and downloads it as CSV. Article 9 data (who
     * consumed what), so gated on `reports.view` — the same role that sees consumption reports, not everyone
     * who can view a batch. The batch's status is shown prominently so nobody recalls one still being sold.
     */
    protected static function recallAction(): Action
    {
        return Action::make('recall')
            ->label(__('Retirada'))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('danger')
            ->visible(fn (): bool => Auth::user()?->can('reports.view') ?? false)
            ->modalHeading(fn (Batch $record): string => __('Retirada de lote :batch', ['batch' => $record->referenceName()]))
            ->modalDescription(fn (Batch $record): string => self::recallSummary($record))
            ->modalContent(fn (Batch $record) => view('filament.batch-recall', ['recall' => new BatchRecall($record)]))
            ->modalSubmitActionLabel(__('Descargar CSV'))
            ->action(fn (Batch $record): StreamedResponse => response()->streamDownload(
                fn () => print ReportExport::csv((new BatchRecall($record))->table()),
                'recall-'.$record->batch_no.'.csv',
                ['Content-Type' => 'text/csv; charset=UTF-8'],
            ));
    }

    private static function recallSummary(Batch $batch): string
    {
        $t = (new BatchRecall($batch))->totals();
        $range = $t['first'] !== null && $t['last'] !== null
            ? $t['first']->format('d/m/Y').' – '.$t['last']->format('d/m/Y')
            : '—';

        return __(':members socios · :grams · :range · Estado del lote: :status', [
            'members' => $t['members'],
            'grams' => Weight::fromCentigrams($t['grams_cg'])->formatted(),
            'range' => $range,
            'status' => $batch->status->label(),
        ]);
    }

    /** Ajuste — a signed correction recorded through the stock ledger, in the batch's own unit. */
    /**
     * Trasladar / Asignar a sede (prompt 277, Ben's 270) — move some or all of this batch to another location through
     * the one writer, `TransferBatch`. On a batch at the grow / central store it reads "Asignar a sede". Gated on
     * `stock.transfer`; the destinations are the locations this person may write to (stores included).
     */
    protected static function transferAction(): Action
    {
        return Action::make('transfer')
            ->label(fn (Batch $record): string => $record->location?->isStore() ? __('Asignar a sede') : __('Trasladar'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn (): bool => Auth::user()?->can('stock.transfer') ?? false)
            ->modalHeading(fn (Batch $record): string => __('Trasladar :batch', ['batch' => $record->displayName()]))
            ->schema([
                Select::make('to_location_id')
                    ->label(__('Destino'))
                    ->options(fn (Batch $record): array => array_diff_key(Location::assignableOptions(includeStores: true), [$record->location_id => true]))
                    ->required(),
                Toggle::make('all')
                    ->label(__('Todo lo que queda'))
                    ->live(),
                TextInput::make('quantity')
                    ->label(fn (Batch $record): string => $record->isUnitType() ? __('Cantidad (uds)') : __('Cantidad (g)'))
                    ->helperText(fn (Batch $record): string => __('Quedan :left.', ['left' => $record->isUnitType()
                        ? (int) $record->remaining_units.' '.__('uds')
                        : $record->remaining_cg->formatted()]))
                    ->required(fn (Get $get): bool => ! $get('all'))
                    ->hidden(fn (Get $get): bool => (bool) $get('all')),
            ])
            ->modalSubmitActionLabel(__('Trasladar'))
            ->action(function (Batch $record, array $data): void {
                $actor = Auth::user();
                $to = Location::query()->withoutGlobalScopes()->find($data['to_location_id'] ?? null);

                try {
                    abort_unless($actor instanceof User && $to instanceof Location, 403);
                    $quantity = ! empty($data['all'])
                        ? ($record->isUnitType() ? (int) $record->remaining_units : $record->remaining_cg->centigrams)
                        : ($record->isUnitType() ? (int) $data['quantity'] : Weight::fromGrams((string) $data['quantity'])->centigrams);

                    (new TransferBatch)->handle($record, $to, $quantity, $actor);

                    Notification::make()->title(__('Stock trasladado a :to', ['to' => $to->name]))->success()->send();
                } catch (InvalidArgumentException|RuntimeException|AuthorizationException $e) {
                    Notification::make()->title(__('No se pudo trasladar'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** Precio (prompt 278) — change THIS batch's sale price; audited, gated on prices.manage, future sales only. */
    protected static function priceAction(): Action
    {
        return Action::make('price')
            ->label(__('Precio'))
            ->icon(Heroicon::OutlinedCurrencyEuro)
            ->visible(fn (): bool => Auth::user()?->can('prices.manage') ?? false)
            ->fillForm(fn (Batch $record): array => [
                'rate_eur' => ($record->isUnitType() ? $record->price_per_unit_cents : $record->price_per_gram_cents) !== null
                    ? Money::fromCents((int) ($record->isUnitType() ? $record->price_per_unit_cents : $record->price_per_gram_cents))->euros() : null,
                'eighth_eur' => $record->price_per_eighth_cents !== null ? Money::fromCents((int) $record->price_per_eighth_cents)->euros() : null,
            ])
            ->schema([
                TextInput::make('rate_eur')
                    ->label(fn (Batch $record): string => $record->isUnitType() ? __('Precio por unidad (€)') : __('Precio por gramo (€)'))
                    ->numeric()->minValue(0)->required(),
                TextInput::make('eighth_eur')
                    ->label(__('Precio por octavo — 3,5 g (€)'))
                    ->helperText(__('Opcional.'))
                    ->numeric()->minValue(0)
                    ->hidden(fn (Batch $record): bool => $record->isUnitType()),
            ])
            ->modalDescription(__('Cambia el precio de este lote. Solo afecta a las aportaciones a partir de ahora; queda en la auditoría.'))
            ->modalSubmitActionLabel(__('Guardar precio'))
            // Prompt 295 — below the batch's cost, ask first: *Continuar* saves (and closes both), *Volver y corregir*
            // returns to this form with nothing written. The same rule as the intake forms (`BelowCost`).
            ->registerModalActions([self::belowCostAction()])
            ->action(function (Batch $record, array $data, Action $action): void {
                $rate = Money::fromEuros((string) $data['rate_eur'])->cents;
                $eighth = filled($data['eighth_eur'] ?? null) ? Money::fromEuros((string) $data['eighth_eur'])->cents : null;
                $unit = $record->isUnitType();
                $offences = BelowCost::offences(
                    $record->cost_per_gram_cents, $unit ? null : $rate, $unit ? $rate : null, $unit ? null : $eighth,
                    $record->genetic?->grams_per_unit_cg !== null ? (int) $record->genetic->grams_per_unit_cg : null,
                );

                if ($offences !== []) {
                    $action->getLivewire()->mountAction('belowCost', [
                        'batch' => $record->getKey(), 'rate' => $rate, 'eighth' => $eighth,
                        'lines' => array_column($offences, 'line'),
                        'field' => $offences[0]['field'] === 'per_eighth' ? 'eighth_eur' : 'rate_eur',
                    ]);
                    $action->halt();
                }

                self::savePrice($record, $rate, $eighth);
            });
    }

    private static function belowCostAction(): Action
    {
        return Action::make('belowCost')
            ->requiresConfirmation()
            ->color('warning')
            ->modalHeading(__('El precio de venta es menor que el coste'))
            ->modalDescription(function (array $arguments): HtmlString {
                $lines = is_array($arguments['lines'] ?? null) ? array_filter($arguments['lines'], 'is_string') : [];

                return new HtmlString(implode('', array_map(fn (string $line): string => '<span class="block">'.e($line).'</span>', $lines)));
            })
            ->modalSubmitActionLabel(__('Continuar'))
            ->modalCancelAction(fn (Action $action): Action => $action
                ->label(__('Volver y corregir'))
                ->extraAttributes(fn (array $arguments): array => ReturnFocus::to($arguments['field'] ?? 'rate_eur')))
            ->extraModalWindowAttributes(ReturnFocus::listener())
            ->modalAutofocus(false)
            ->cancelParentActions()
            ->action(function (array $arguments): void {
                self::savePrice(Batch::query()->findOrFail($arguments['batch']), (int) $arguments['rate'], $arguments['eighth'] !== null ? (int) $arguments['eighth'] : null);
            });
    }

    private static function savePrice(Batch $batch, int $rate, ?int $eighth): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        (new SetBatchPrice)->handle($batch, $rate, $eighth, $actor);
        Notification::make()->title(__('Precio actualizado'))->success()->send();
    }

    protected static function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label(__('Ajuste'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->schema([
                TextInput::make('quantity')
                    ->label(fn (Batch $record): string => $record->isUnitType() ? __('Ajuste (uds)') : __('Ajuste (g)'))
                    ->numeric()
                    ->required()
                    ->helperText(__('Usa un valor negativo para restar.')),
                Textarea::make('reason')
                    ->label(__('Motivo'))
                    ->required(),
            ])
            ->action(function (Batch $record, array $data): void {
                try {
                    (new RecordStockMovement)->handle(
                        $record,
                        StockMovementType::ADJUSTMENT,
                        self::signedDelta($record, (float) $data['quantity']),
                        ['reason' => (string) $data['reason'], 'operator_id' => self::operatorId()],
                    );

                    Notification::make()->title(__('Ajuste registrado'))->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('Stock insuficiente'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** Merma — a loss (spillage, waste, seizure). Gated on stock.merma; always a reduction. */
    protected static function mermaAction(): Action
    {
        return Action::make('merma')
            ->label(__('Merma'))
            ->icon(Heroicon::OutlinedFire)
            ->color('danger')
            ->visible(fn (): bool => Auth::user()?->can('stock.merma') ?? false)
            ->requiresConfirmation()   // a loss mutates compliance-relevant stock — confirm first
            ->schema([
                TextInput::make('quantity')
                    ->label(fn (Batch $record): string => $record->isUnitType() ? __('Merma (uds)') : __('Merma (g)'))
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                Textarea::make('reason')
                    ->label(__('Motivo'))
                    ->required(),
            ])
            ->action(function (Batch $record, array $data): void {
                try {
                    (new RecordStockMovement)->handle(
                        $record,
                        StockMovementType::MERMA,
                        -abs(self::signedDelta($record, (float) $data['quantity'])),
                        ['reason' => (string) $data['reason'], 'operator_id' => self::operatorId(), 'actor' => Auth::user()],
                    );

                    Notification::make()->title(__('Merma registrada'))->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('Stock insuficiente'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** A stock delta in the batch's own unit: whole units for UNIT batches, centigrams for WEIGHT. */
    protected static function signedDelta(Batch $batch, float $quantity): int
    {
        return $batch->isUnitType() ? (int) $quantity : (int) round_half_up($quantity * 100);
    }

    protected static function operatorId(): ?string
    {
        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }
}
