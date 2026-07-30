<?php

namespace App\Filament\Resources\TillSessions;

use App\Filament\Resources\TillSessions\Pages\ListTillSessions;
use App\Filament\Resources\TillSessions\Pages\ViewTillSession;
use App\Filament\Resources\TillSessions\Schemas\TillSessionInfolist;
use App\Filament\Resources\TillSessions\Tables\TillSessionsTable;
use App\Models\TillSession;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Oversight of till sessions (arqueos). Read-only in the panel: sessions are opened and
 * closed at the counter, so there is no create/edit here — only the list and a View page
 * showing the immutable Z-report. Authorisation flows through TillSessionPolicy.
 */
class TillSessionResource extends Resource
{
    protected static ?string $model = TillSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('Cajas');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Caja');
    }

    public static function getModelLabel(): string
    {
        return __('caja');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cajas');
    }

    /** Sessions are opened/closed at the counter — never created from the panel. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return TillSessionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TillSessionsTable::configure($table);
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
            'index' => ListTillSessions::route('/'),
            'view' => ViewTillSession::route('/{record}'),
        ];
    }
}
