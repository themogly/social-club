<?php

namespace App\Filament\Resources\MembershipTiers;

use App\Filament\Resources\MembershipTiers\Pages\CreateMembershipTier;
use App\Filament\Resources\MembershipTiers\Pages\EditMembershipTier;
use App\Filament\Resources\MembershipTiers\Pages\ListMembershipTiers;
use App\Filament\Resources\MembershipTiers\Schemas\MembershipTierForm;
use App\Filament\Resources\MembershipTiers\Tables\MembershipTiersTable;
use App\Models\MembershipTier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MembershipTierResource extends Resource
{
    protected static ?string $model = MembershipTier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    public static function getNavigationLabel(): string
    {
        return __('Tarifas');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Administración');
    }

    public static function getModelLabel(): string
    {
        return __('tarifa');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tarifas');
    }

    public static function form(Schema $schema): Schema
    {
        return MembershipTierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MembershipTiersTable::configure($table);
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
            'index' => ListMembershipTiers::route('/'),
            'create' => CreateMembershipTier::route('/create'),
            'edit' => EditMembershipTier::route('/{record}/edit'),
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
