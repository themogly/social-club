<?php

namespace App\Filament\Resources\Genetics;

use App\Filament\Resources\Genetics\Pages\CreateGenetic;
use App\Filament\Resources\Genetics\Pages\EditGenetic;
use App\Filament\Resources\Genetics\Pages\ListGenetics;
use App\Filament\Resources\Genetics\Schemas\GeneticForm;
use App\Filament\Resources\Genetics\Tables\GeneticsTable;
use App\Models\Genetic;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class GeneticResource extends Resource
{
    protected static ?string $model = Genetic::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    public static function getNavigationLabel(): string
    {
        return __('Genéticas');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Catálogo');
    }

    public static function getModelLabel(): string
    {
        return __('genética');
    }

    public static function getPluralModelLabel(): string
    {
        return __('genéticas');
    }

    public static function form(Schema $schema): Schema
    {
        return GeneticForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GeneticsTable::configure($table);
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
            'index' => ListGenetics::route('/'),
            'create' => CreateGenetic::route('/create'),
            'edit' => EditGenetic::route('/{record}/edit'),
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
