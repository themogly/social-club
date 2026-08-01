<?php

namespace App\Filament\Resources\Batches\Tables;

use App\Actions\Stock\RecordStockMovement;
use App\Enums\BatchStatus;
use App\Enums\StockMovementType;
use App\Models\Batch;
use App\Support\Spreadsheet\ReportExport;
use App\Support\Weight;
use App\ViewModels\BatchRecall;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('batch_no')->label(__('Nº lote'))->searchable()->sortable(),
                TextColumn::make('genetic.name')->label(__('Genética'))->searchable()->sortable(),
                TextColumn::make('genetic.product_type')->label(__('Tipo'))->badge()->toggleable(),
                TextColumn::make('remaining')
                    ->label(__('Restante'))
                    ->state(function (Batch $record): string {
                        if ($record->isUnitType()) {
                            $units = (int) ($record->remaining_units ?? 0);

                            return $units.' '.__('uds').' ('.number_format($record->onHandCg() / 100, 2).' g)';
                        }

                        return number_format($record->remaining_cg->centigrams / 100, 2).' g';
                    }),
                TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->color(fn (BatchStatus $state): string => match ($state) {
                        BatchStatus::OPEN => 'success',
                        BatchStatus::QUARANTINED => 'warning',
                        BatchStatus::CLOSED => 'gray',
                    }),
                TextColumn::make('expires_on')->label(__('Caduca'))->date()->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                self::recallAction(),
                self::adjustAction(),
                self::mermaAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
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
            ->modalHeading(fn (Batch $record): string => __('Retirada de lote :batch', ['batch' => $record->batch_no]))
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
