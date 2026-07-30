<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Filament\Resources\AuditLogs\Schemas\AuditLogInfolist;
use App\Filament\Resources\AuditLogs\Tables\AuditLogsTable;
use App\Models\AuditLog;
use App\Support\ActiveScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Registro de auditoría — the append-only audit trail, read-only in the panel. Entries are
 * written by App\Actions\RecordAuditLog and are immutable (the model throws on update/
 * delete), so there is NO create/edit/delete here: only the list, filters, a CSV export and
 * a View page with the before/after diff. Gated by AuditLogPolicy on `audit.view` (owner).
 *
 * Retention is deliberately LONGER than member data (audit_retention_days 3650 > data_
 * retention_days 1825) — surfaced on the list page so it is understood, not buried.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 40;

    public static function getNavigationLabel(): string
    {
        return __('Registro de auditoría');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sistema');
    }

    public static function getModelLabel(): string
    {
        return __('entrada de auditoría');
    }

    public static function getPluralModelLabel(): string
    {
        return __('registro de auditoría');
    }

    /** Append-only: entries are written by RecordAuditLog, never created from the panel. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return AuditLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AuditLogsTable::configure($table);
    }

    /** Scope to the active organisation (the audit trail carries no global org scope). */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organisation_id', app(ActiveScope::class)->organisationId())
            ->with('actor');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }
}
