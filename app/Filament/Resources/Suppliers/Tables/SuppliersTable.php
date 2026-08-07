<?php

namespace App\Filament\Resources\Suppliers\Tables;

use App\Models\Supplier;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            // Balance owing = Σ(amount − paid) across the supplier's purchases, aggregated
            // in one query (no N+1). Soft-deleted purchases are excluded by the relation scope.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withSum('purchases', 'amount_cents')
                ->withSum('purchases', 'paid_cents'))
            ->columns([
                TextColumn::make('name')->label(__('Nombre'))->searchable()->sortable(),
                TextColumn::make('contact')->label(__('Contacto'))->searchable()->placeholder('—'),
                TextColumn::make('tax_id')->label(__('CIF/NIF'))->searchable()->toggleable()->placeholder('—'),
                TextColumn::make('owing')
                    ->label(__('Saldo pendiente'))
                    ->state(fn (Supplier $record): int => (int) $record->getAttribute('purchases_sum_amount_cents')
                        - (int) $record->getAttribute('purchases_sum_paid_cents'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                IconColumn::make('active')->label(__('Activo'))->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // Day one of a real club, EVERY one of these tables is empty; a framework shrug is the
            // first thing a new owner sees (admin audit, Phase C). Say what the screen is for and
            // what to do first.
            ->emptyStateHeading(__('Sin proveedores'))
            ->emptyStateDescription(__('Da de alta a un proveedor para poder registrar compras a su nombre.'));
    }
}
