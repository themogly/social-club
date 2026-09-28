<?php

namespace App\Filament\Resources\Batches;

use App\Filament\Resources\Batches\Pages\CreateBatch;
use App\Filament\Resources\Batches\Pages\EditBatch;
use App\Filament\Resources\Batches\Pages\ListBatches;
use App\Filament\Resources\Batches\Schemas\BatchForm;
use App\Filament\Resources\Batches\Tables\BatchesTable;
use App\Models\Batch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BatchResource extends Resource
{
    protected static ?string $model = Batch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('Lotes');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Dispensario');
    }

    public static function getModelLabel(): string
    {
        return __('lote');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lotes');
    }

    /** A batch is named by its display name (prompt 282) — strain, the club's name, the lote number — in titles and breadcrumbs. */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record instanceof Batch ? $record->displayName(withGenetic: true) : parent::getRecordTitle($record);
    }

    public static function hasRecordTitle(): bool
    {
        return true;
    }

    public static function form(Schema $schema): Schema
    {
        return BatchForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BatchesTable::configure($table);
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
            'index' => ListBatches::route('/'),
            'create' => CreateBatch::route('/create'),
            'edit' => EditBatch::route('/{record}/edit'),
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
