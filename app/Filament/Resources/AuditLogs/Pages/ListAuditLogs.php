<?php

namespace App\Filament\Resources\AuditLogs\Pages;

use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListAuditLogs extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    /** Surface the (deliberately longer) audit retention so it is understood, not buried. */
    public function getSubheading(): ?string
    {
        $days = (int) Settings::get('audit_retention_days', 3650);

        return __('Registro inalterable (solo lectura). Retención: :days días — más larga que la de datos de socio (:member días).', [
            'days' => $days,
            'member' => (int) Settings::get('data_retention_days', 1825),
        ]);
    }

    /** No create action (append-only) — only the CSV export of the current view. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label(__('Exportar CSV'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    /** Stream the currently filtered/searched entries as a locale-aware CSV. */
    public function exportCsv(): StreamedResponse
    {
        $query = $this->getFilteredTableQuery() ?? AuditLogResource::getEloquentQuery();

        $delimiter = app()->getLocale() === 'es' ? ';' : ',';
        $writer = Writer::createFromString();
        $writer->setDelimiter($delimiter);
        $writer->insertOne([
            __('Fecha'), __('Actor'), __('Acción'), __('Tipo de sujeto'), __('ID del sujeto'), __('IP'), __('Agente'),
        ]);

        $query->with('actor')->lazyById()->each(function (Model $entry) use ($writer): void {
            $type = $entry->getAttribute('auditable_type');
            $writer->insertOne([
                (string) $entry->getAttribute('created_at'),
                (string) (data_get($entry, 'actor.name') ?? __('Sistema')),
                (string) $entry->getAttribute('action'),
                $type !== null ? class_basename((string) $type) : '',
                (string) ($entry->getAttribute('auditable_id') ?? ''),
                (string) ($entry->getAttribute('ip') ?? ''),
                (string) ($entry->getAttribute('user_agent') ?? ''),
            ]);
        });

        $content = "\xEF\xBB\xBF".$writer->toString();
        $filename = 'registro-auditoria-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(fn () => print ($content), $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
