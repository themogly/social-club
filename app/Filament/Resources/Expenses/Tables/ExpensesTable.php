<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Actions\Expenses\ApproveExpense;
use App\Enums\ExpenseKind;
use App\Enums\ExpensePaidFrom;
use App\Models\Expense;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('incurred_on', 'desc')
            // Concrete outgoings only — recurrence TEMPLATES are schedules, not real spend.
            ->modifyQueryUsing(self::concreteOnly(...))
            ->columns([
                TextColumn::make('incurred_on')->label(__('Fecha'))->date()->sortable(),
                TextColumn::make('category.name')->label(__('Categoría'))->searchable()->sortable(),
                TextColumn::make('kind')
                    ->label(__('Tipo'))
                    ->badge()
                    ->formatStateUsing(fn (ExpenseKind $state): string => $state === ExpenseKind::TILL ? __('Caja') : __('Gasto general'))
                    ->color(fn (ExpenseKind $state): string => $state === ExpenseKind::TILL ? 'warning' : 'info'),
                TextColumn::make('amount_cents')
                    ->label(__('Importe'))
                    ->state(fn (Expense $record): int => $record->amount_cents->cents)
                    ->money('EUR', divideBy: 100)
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('paid_from')
                    ->label(__('Pagado desde'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (ExpensePaidFrom $state): string => $state->label()),
                TextColumn::make('location.name')->label(__('Sede'))->placeholder('—')->toggleable(),
                TextColumn::make('approval')
                    ->label(__('Aprobación'))
                    ->badge()
                    ->state(function (Expense $record): string {
                        if ($record->approved_at !== null) {
                            return __('Aprobado');
                        }

                        return $record->requiresApproval() ? __('Requiere aprobación') : __('No requiere');
                    })
                    ->icon(fn (Expense $record): ?Heroicon => $record->approved_at !== null ? Heroicon::OutlinedCheckCircle : null)
                    ->color(fn (Expense $record): string => match (true) {
                        $record->approved_at !== null => 'success',
                        $record->requiresApproval() => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('Tipo'))
                    ->options([
                        ExpenseKind::TILL->value => __('Caja'),
                        ExpenseKind::OVERHEAD->value => __('Gasto general'),
                    ]),
                SelectFilter::make('category')
                    ->label(__('Categoría'))
                    ->relationship('category', 'name'),
                TernaryFilter::make('approved')
                    ->label(__('Aprobación'))
                    ->placeholder(__('Todos'))
                    ->trueLabel(__('Aprobados'))
                    ->falseLabel(__('Pendientes'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('approved_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('approved_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Filter::make('incurred_on')
                    ->schema([
                        DatePicker::make('incurred_from')->label(__('Desde')),
                        DatePicker::make('incurred_until')->label(__('Hasta')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['incurred_from'] ?? null,
                            fn (Builder $q, string $date): Builder => $q->whereDate('incurred_on', '>=', $date),
                        )
                        ->when(
                            $data['incurred_until'] ?? null,
                            fn (Builder $q, string $date): Builder => $q->whereDate('incurred_on', '<=', $date),
                        )),
            ])
            ->recordActions([
                self::approveAction(),
                // Editing is overheads-only — petty-cash rows belong to a till reconciliation.
                EditAction::make()
                    ->visible(fn (Expense $record): bool => $record->kind === ExpenseKind::OVERHEAD),
            ]);
    }

    /**
     * Concrete expenses only (excludes recurrence templates) — via the model scope,
     * the single source of truth for what counts as a real outgoing.
     *
     * @param  Builder<Expense>  $query
     * @return Builder<Expense>
     */
    protected static function concreteOnly(Builder $query): Builder
    {
        return $query->concrete();
    }

    /**
     * Approve — a recorded action (approver + timestamp), visible only while the row
     * still needs approval AND the actor holds expenses.approve. Routes through the
     * ApproveExpense action so it is audited, never a silent status flip.
     */
    protected static function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('Aprobar'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Expense $record): bool => $record->requiresApproval()
                && (Auth::user()?->can('expenses.approve') ?? false))
            ->action(function (Expense $record): void {
                $user = Auth::user();

                if (! $user instanceof User) {
                    return;
                }

                (new ApproveExpense)->handle($record, $user);

                Notification::make()->title(__('Gasto aprobado'))->success()->send();
            });
    }
}
