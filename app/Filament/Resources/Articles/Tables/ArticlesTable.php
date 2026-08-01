<?php

namespace App\Filament\Resources\Articles\Tables;

use App\Actions\Stock\RecordStockMovement;
use App\Enums\StockMovementType;
use App\Models\Article;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ArticlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Nombre'))->searchable()->sortable(),
                TextColumn::make('category.name')->label(__('Categoría'))->sortable()->toggleable(),
                TextColumn::make('price_cents')
                    ->label(__('Precio'))
                    ->state(fn (Article $record): int => $record->price_cents->cents)
                    ->money('EUR', divideBy: 100)
                    ->sortable(),
                TextColumn::make('stock')->label(__('Existencias'))->numeric()->sortable(),
                TextColumn::make('low_stock_threshold')->label(__('Umbral'))->numeric()->toggleable(),
                IconColumn::make('active')->label(__('Activo'))->boolean(),
            ])
            ->filters([
                Filter::make('low_stock')
                    ->label(__('Stock bajo'))
                    // Mirrors Article::scopeLowStock(): at or below the configured threshold.
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('low_stock_threshold')
                        ->whereColumn('stock', '<=', 'low_stock_threshold')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                self::restockAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    /** Reponer — add units to stock through the ledger (an ADJUSTMENT movement). */
    protected static function restockAction(): Action
    {
        return Action::make('restock')
            ->label(__('Reponer'))
            ->icon(Heroicon::OutlinedArrowUturnUp)
            ->schema([
                TextInput::make('units')
                    ->label(__('Unidades'))
                    ->numeric()
                    ->minValue(1)
                    ->required(),
            ])
            ->action(function (Article $record, array $data): void {
                // A restock is goods ARRIVING — an INTAKE, not an ADJUSTMENT (prompt 104). ADJUSTMENT/MERMA
                // are audited as corrections; filing a delivery as one destroys the "someone corrected a
                // miscount" signal. A genuine count correction is a separate, ADJUSTMENT-typed action.
                (new RecordStockMovement)->handle(
                    $record,
                    StockMovementType::INTAKE,
                    (int) $data['units'],
                    ['reason' => __('Reposición'), 'operator_id' => self::operatorId()],
                );

                Notification::make()->title(__('Stock repuesto'))->success()->send();
            });
    }

    protected static function operatorId(): ?string
    {
        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }
}
