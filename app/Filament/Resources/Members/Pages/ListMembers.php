<?php

namespace App\Filament\Resources\Members\Pages;

use App\Actions\Members\ImportMembers;
use App\Filament\Resources\Members\MemberResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            $this->importAction(),
        ];
    }

    /**
     * CSV bulk import of an existing member list, gated on members.import. Runs the ImportMembers
     * action, which validates + skips duplicates per row (idempotent on re-run) and audits the run;
     * the result summary is surfaced so the operator sees created / skipped / errored counts.
     */
    private function importAction(): Action
    {
        return Action::make('import')
            ->label(__('Importar CSV'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn (): bool => Auth::user()?->can('members.import') ?? false)
            ->schema([
                FileUpload::make('csv')
                    ->label(__('Archivo CSV'))
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->storeFiles(false)
                    ->required()
                    ->helperText(__('Columnas: first_name, last_name, email, phone, date_of_birth, document_type, document_number, declared_monthly_g.')),
            ])
            ->action(function (array $data): void {
                $file = $data['csv'];
                $file = is_array($file) ? reset($file) : $file;

                if (! $file instanceof TemporaryUploadedFile) {
                    Notification::make()->title(__('No se pudo leer el archivo.'))->danger()->send();

                    return;
                }

                $result = (new ImportMembers)->import($file->getRealPath());
                $errorCount = count($result['errors']);

                Notification::make()
                    ->title(__('Importación completada'))
                    ->body(__(':created creados · :skipped omitidos (duplicados) · :errors con errores.', [
                        'created' => $result['created'],
                        'skipped' => $result['skipped'],
                        'errors' => $errorCount,
                    ]))
                    ->{$errorCount > 0 ? 'warning' : 'success'}()
                    ->persistent()
                    ->send();
            });
    }
}
