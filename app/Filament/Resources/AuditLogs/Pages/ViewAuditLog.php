<?php

namespace App\Filament\Resources\AuditLogs\Pages;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditLog extends ViewRecord
{
    protected static string $resource = AuditLogResource::class;

    /** No edit action — the audit trail is append-only and immutable. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
