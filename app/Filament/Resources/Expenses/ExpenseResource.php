<?php

namespace App\Filament\Resources\Expenses;

use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\Expenses\Tables\ExpensesTable;
use App\Models\Expense;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

/**
 * Treasurer-facing expenses. The index lists every CONCRETE expense (petty cash +
 * overheads); create/edit is for OVERHEADS ONLY — petty cash is recorded at the
 * counter (TillSession) so it hits the drawer reconciliation. The create/edit flow
 * is therefore gated on `expenses.overheads` (owner/treasurer), NOT the policy's
 * `create` (which staff hold for counter petty cash): a manager or staff hitting the
 * create page gets a 403. viewAny stays broad (ExpensePolicy) so managers can read.
 */
class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function getNavigationLabel(): string
    {
        return __('Gastos');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Caja');
    }

    public static function getModelLabel(): string
    {
        return __('gasto');
    }

    public static function getPluralModelLabel(): string
    {
        return __('gastos');
    }

    public static function form(Schema $schema): Schema
    {
        return ExpenseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpensesTable::configure($table);
    }

    /** Recording an overhead is treasurer-only — refused to managers and staff. */
    public static function canCreate(): bool
    {
        return Auth::user()?->can('expenses.overheads') ?? false;
    }

    /** Editing is overheads-only too; petty-cash rows are never edited here. */
    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->can('expenses.overheads') ?? false;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
