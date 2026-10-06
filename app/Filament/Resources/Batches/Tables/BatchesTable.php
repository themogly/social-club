<?php

namespace App\Filament\Resources\Batches\Tables;

use App\Actions\Stock\RecordStockMovement;
use App\Enums\BatchStatus;
use App\Enums\ProductType;
use App\Enums\StockMovementType;
use App\Filament\Forms\DecimalInput;
use App\Filament\Resources\Batches\BatchActions;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
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
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
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
                    // Prompt 340 — on a phone the Precio column is off-screen, so the price rides under the name there.
                    ->description(fn (Batch $record): HtmlString => new HtmlString(e($record->displaySubtitle())
                        .(($price = self::priceLabel($record)) !== null ? '<span class="sm:hidden" data-batch-row-price> · '.e($price).'</span>' : '')))
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
                // Through the batch's strain, a deleted one included (302): a dotted `genetic.product_type` column makes
                // Filament eager-load the relation WITHOUT deleted strains, and the badge went blank.
                TextColumn::make('product_type')->label(__('Tipo'))->badge()->toggleable()
                    ->state(fn (Batch $record): ?ProductType => $record->strain()?->product_type),
                // Where the stock IS (prompt 148). Shown only when the org has more than one active sede — a
                // column that reads the same on every row in a single-sede club is noise; it is essential the
                // moment there are two.
                TextColumn::make('location.name')
                    ->label(__('Sede'))
                    ->searchable()
                    ->sortable()
                    ->visible(fn (): bool => Location::query()->active()->count() > 1),
                // Prompt 357 (Ben: "date added field") — when the batch was ENTERED here: its own created_at, in the sede's
                // time. A part transferred from the store is a child batch created by the transfer, so it shows when it
                // arrived HERE. Sortable, so a sort by Lote that stuck in the session (persistSortInSession) is one click
                // from date order again.
                TextColumn::make('created_at')->label(__('Añadido'))
                    ->dateTime('d M Y, H:i')
                    ->timezone(fn (Batch $record): string => $record->location?->timezone ?: 'Europe/Madrid')
                    ->sortable(),
                // …and when it was RECEIVED or harvested (the date the default sort, 308, uses) — the subtitle already
                // says it, so hidden until switched on.
                TextColumn::make('acquired_or_harvested_on')->label(__('Recibido'))->date()->sortable()->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                // Prompt 359 — sealed top-ups of this batch at its sede, off the counter (Restante is the jar).
                TextColumn::make('reserve_cg')->label(__('Reserva'))
                    ->state(fn (Batch $record): ?string => $record->reserve_cg->centigrams > 0 ? $record->reserve_cg->formatted() : null)
                    ->placeholder('—')->toggleable(),
                TextColumn::make('remaining')
                    ->label(__('Restante'))
                    ->state(function (Batch $record): string {
                        if ($record->isUnitType()) {
                            $units = (int) ($record->remaining_units ?? 0);

                            return $units.' '.__('uds').' ('.Weight::fromCentigrams($record->onHandCg())->formatted().')';
                        }

                        return $record->remaining_cg->formatted(); // the one formatter: "300,01 g" in Spanish (prompt 306)
                    }),
                // Prompt 278 — the batch's own sale price ("—" = none yet: the strain's sede price applies, if any).
                TextColumn::make('sale_price')
                    ->label(__('Precio'))
                    ->state(fn (Batch $record): string => self::priceLabel($record) ?? '—'),
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
                // Prompt 308 — the working list is what is IN stock; empty batches are one choice away, never gone.
                SelectFilter::make('stock')
                    ->label(__('Existencias'))
                    ->options(['in_stock' => __('Con existencias'), 'empty' => __('Vacíos'), 'all' => __('Todos')])
                    ->default('in_stock')
                    ->selectablePlaceholder(false)
                    ->query(self::byStock(...)),
                TrashedFilter::make(),
            ])
            // Newest received first (prompt 308); a batch with no date counts from when it was entered. Any column
            // header still sorts by that column, and the choice — like the filters — sticks for the session.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('COALESCE(batches.acquired_or_harvested_on, batches.created_at) DESC')
                ->orderByDesc('batches.created_at'))
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->description(fn (ListBatches $livewire): ?HtmlString => self::hiddenEmptyHint($livewire))
            // The worst offender: four labelled buttons, a 335px actions column — a third of the whole
            // table. Retirada, Ajuste and Merma are all destructive or rare, which is exactly what
            // belongs behind a trigger (prompt 170).
            ->recordActions([
                // Prompt 340 — Precio first (the most used), the heavy ones last; and on a phone the panel is teleported
                // out of the table's scroll box (which clipped it) and never taller than the screen, scrolling inside.
                ActionGroup::make([
                    BatchActions::price(),
                    // Prompt 354 — *Trasladar* (302: a store batch's main job) is second, after the most used, no longer a
                    // button beside the ⋮: on a phone that button was pinned over the row.
                    BatchActions::transfer()->tooltip(fn (Batch $record): string => BatchActions::transferLabel($record)),
                    // Prompt 305 — the same lote at more locations, beside the *Trasladar* button.
                    BatchActions::addParts(),
                    BatchActions::recount(), // prompt 305 — set the part to what the scale says (beside Ajuste)
                    BatchActions::toReserve(), // prompt 359 — jar → sealed bags
                    BatchActions::topUp(), // prompt 359 — sealed bags → jar
                    EditAction::make(),
                    self::adjustAction(),
                    self::mermaAction(),
                    self::recallAction(), // destructive, last (344)
                ])->dropdownTeleport()->dropdownMaxHeight('min(24rem, calc(100dvh - 7rem))'),
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
     * The *Existencias* filter (prompt 308) — through the model's scopes, the one meaning of "in stock".
     *
     * @param  Builder<Batch>  $query
     * @param  array<string, mixed>  $data
     * @return Builder<Batch>
     */
    protected static function byStock(Builder $query, array $data): Builder
    {
        return match ($data['value'] ?? 'in_stock') {
            'empty' => $query->empty(),
            'all' => $query,
            default => $query->inStock(),
        };
    }

    /**
     * "12 empty batches hidden — show all" while the default filter hides any (prompt 308), so nobody thinks a batch
     * vanished. Counts within the chosen sede, as the list does.
     */
    private static function hiddenEmptyHint(ListBatches $livewire): ?HtmlString
    {
        if (($livewire->tableFilters['stock']['value'] ?? 'in_stock') !== 'in_stock') {
            return null;
        }
        $sede = $livewire->tableFilters['location_id']['value'] ?? null;
        $hidden = Batch::query()->empty()->when(filled($sede), fn (Builder $q): Builder => $q->where('batches.location_id', $sede))->count();
        if ($hidden === 0) {
            return null;
        }

        return new HtmlString(e(trans_choice('Se oculta :count lote vacío|Se ocultan :count lotes vacíos', $hidden, ['count' => $hidden])).' · '
            .Blade::render('<x-filament::link tag="button" data-show-empty-batches wire:click="$set(\'tableFilters.stock.value\', \'all\')">{{ $label }}</x-filament::link>', ['label' => __('Ver todos')]));
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

    /** Precio (prompt 278) — change THIS batch's sale price; audited, gated on prices.manage, future sales only. */
    /** Ajuste — a signed correction recorded through the stock ledger, in the batch's own unit. */
    protected static function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label(__('Ajuste'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->schema([
                DecimalInput::make('quantity')
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
                DecimalInput::make('quantity')
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

    /** "12.00 €/g" or "3.00 €/ud", or null when the batch has no price of its own — the column's and the phone line's. */
    private static function priceLabel(Batch $record): ?string
    {
        $rate = $record->isUnitType() ? $record->price_per_unit_cents : $record->price_per_gram_cents;

        return $rate !== null ? Money::fromCents((int) $rate)->formatted().($record->isUnitType() ? __('/ud') : __('/g')) : null;
    }
}
