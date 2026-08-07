<?php

namespace App\Filament\Resources\DataRequests\Tables;

use App\Enums\DataRequestType;
use App\Filament\Resources\DataRequests\DataRequestResource;
use App\Models\DataRequest;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DataRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('requested_at', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->label(__('Tipo'))
                    ->badge()
                    ->formatStateUsing(fn (DataRequestType $state): string => DataRequestResource::typeLabel($state)),
                TextColumn::make('member')
                    ->label(__('Socio'))
                    ->state(fn (DataRequest $record): string => trim($record->member->first_name.' '.$record->member->last_name) ?: '—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('member', fn (Builder $q): Builder => $q->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")->orWhere('member_no', 'like', "%{$search}%"))),
                TextColumn::make('requested_at')->label(__('Recibida'))->dateTime()->sortable(),
                TextColumn::make('completed_at')->label(__('Atendida'))->dateTime()->placeholder(__('Pendiente'))->sortable(),
                IconColumn::make('done')
                    ->label(__('Estado'))
                    ->state(fn (DataRequest $record): bool => $record->completed_at !== null)
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('warning'),
                TextColumn::make('handledBy.name')->label(__('Atendida por'))->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('Tipo'))
                    ->options(fn (): array => DataRequestResource::typeOptions()),
                Filter::make('pending')
                    ->label(__('Solo pendientes'))
                    ->query(fn (Builder $query): Builder => $query->whereNull('completed_at')),
            ])
            ->recordActions([
                DataRequestResource::fulfilExportAction(),
                DataRequestResource::fulfilErasureAction(),
                DataRequestResource::markResolvedAction(),
            ])
            // Day one of a real club, EVERY one of these tables is empty; a framework shrug is the
            // first thing a new owner sees (admin audit, Phase C). Say what the screen is for and
            // what to do first.
            ->emptyStateHeading(__('Sin solicitudes RGPD'))
            ->emptyStateDescription(__('Cuando un socio ejerza sus derechos —acceso, rectificación, supresión, portabilidad— regístralo aquí. La ficha es la prueba de que respondiste en plazo.'));
    }
}
