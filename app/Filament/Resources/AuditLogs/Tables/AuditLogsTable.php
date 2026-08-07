<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use App\Support\ActiveScope;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Fecha'))->dateTime()->sortable(),
                TextColumn::make('actor.name')
                    ->label(__('Actor'))
                    ->placeholder(__('Sistema'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('action')->label(__('Acción'))->badge()->searchable()->sortable(),
                TextColumn::make('auditable_type')
                    ->label(__('Tipo de sujeto'))
                    ->formatStateUsing(fn (?string $state): string => $state !== null ? class_basename($state) : '—')
                    ->placeholder('—'),
                TextColumn::make('auditable_id')
                    ->label(__('ID del sujeto'))
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('ip')->label(__('IP'))->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('actor_id')
                    ->label(__('Actor'))
                    ->relationship('actor', 'name'),
                SelectFilter::make('action')
                    ->label(__('Acción'))
                    ->options(fn (): array => self::distinct('action')),
                SelectFilter::make('auditable_type')
                    ->label(__('Tipo de sujeto'))
                    ->options(fn (): array => self::subjectTypes()),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label(__('Desde')),
                        DatePicker::make('until')->label(__('Hasta')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date),
                        )
                        ->when(
                            $data['until'] ?? null,
                            fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date),
                        )),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            // Day one of a real club, EVERY one of these tables is empty; a framework shrug is the
            // first thing a new owner sees (admin audit, Phase C). Say what the screen is for and
            // what to do first.
            ->emptyStateHeading(__('Sin registros de auditoría'))
            ->emptyStateDescription(__('Aquí queda constancia de las acciones sensibles: excepciones autorizadas, cambios de estado, accesos a documentos. Se irá llenando solo.'));
    }

    /**
     * Distinct values of a column within the active organisation, as value => value options.
     *
     * @return array<string, string>
     */
    private static function distinct(string $column): array
    {
        return AuditLog::query()
            ->where('organisation_id', app(ActiveScope::class)->organisationId())
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->all();
    }

    /**
     * Distinct subject types as fqcn => short-name options.
     *
     * @return array<string, string>
     */
    private static function subjectTypes(): array
    {
        return collect(self::distinct('auditable_type'))
            ->mapWithKeys(fn (string $type): array => [$type => class_basename($type)])
            ->all();
    }
}
