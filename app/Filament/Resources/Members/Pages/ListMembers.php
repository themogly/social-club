<?php

namespace App\Filament\Resources\Members\Pages;

use App\Actions\Members\ImportMembers;
use App\Filament\Resources\Members\MemberResource;
use App\Support\Weight;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;

    /**
     * The dry-run result of the staged CSV, held between the upload step and the commit step so the operator
     * sees the consequence — above all the resulting stock ceiling — BEFORE anything is written (prompt 131).
     *
     * @var array{created: int, skipped: int, errors: array<int, array<int, string>>, consent_pending: int, ceilings: array<string, array{location: string, added_active: int, active_members: int, ceiling_cg: int, current_active: int, current_ceiling_cg: int}>}|null
     */
    public ?array $importPreview = null;

    /** Absolute path to the staged upload awaiting confirmation (server-side; cleared on commit/cancel). */
    public ?string $importStashPath = null;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            $this->importAction(),
            $this->confirmImportAction(),
            $this->cancelImportAction(),
        ];
    }

    /**
     * Step 1 — upload + dry run. Gated on members.import. Stages the file server-side and runs ImportMembers
     * `preview()` (which writes nothing): per-row validation, duplicate + member-number-clash detection, and the
     * resulting active membership per sede with the stock ceiling it implies. Nothing is created here — the
     * operator reviews the numbers and confirms in step 2.
     */
    private function importAction(): Action
    {
        return Action::make('import')
            ->label(__('Importar CSV'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->visible(fn (): bool => ($this->importPreview === null) && (Auth::user()?->can('members.import') ?? false))
            ->schema([
                FileUpload::make('csv')
                    ->label(__('Archivo CSV'))
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->storeFiles(false)
                    ->required()
                    ->helperText(__('Columnas (todas opcionales salvo el nombre): first_name, last_name, email, phone, date_of_birth, document_type, document_number, declared_monthly_g, member_no, joined_at, left_at, status, location, tier, membership_start, consent_date, consent_text_version.')),
            ])
            ->action(function (array $data): void {
                $file = $data['csv'];
                $file = is_array($file) ? reset($file) : $file;

                if (! $file instanceof TemporaryUploadedFile) {
                    Notification::make()->title(__('No se pudo leer el archivo.'))->danger()->send();

                    return;
                }

                // Stage the upload to a stable server path so it survives into the confirm request.
                $dir = storage_path('app/member-imports');
                if (! is_dir($dir)) {
                    mkdir($dir, 0700, true);
                }
                $path = $dir.DIRECTORY_SEPARATOR.Str::ulid().'.csv';
                copy($file->getRealPath(), $path);

                $this->importStashPath = $path;
                $this->importPreview = (new ImportMembers)->preview($path);

                Notification::make()
                    ->title(__('Revisa antes de confirmar'))
                    ->body(__(':created a crear · :skipped duplicados · :errors con errores · :pending sin consentimiento.', [
                        'created' => $this->importPreview['created'],
                        'skipped' => $this->importPreview['skipped'],
                        'errors' => count($this->importPreview['errors']),
                        'pending' => $this->importPreview['consent_pending'],
                    ]))
                    ->info()
                    ->send();
            });
    }

    /**
     * Step 2 — commit. Only appears once a preview is staged; its modal shows the stock-ceiling consequence per
     * sede (the number that stops a club manufacturing headroom it is not entitled to) before the operator
     * commits. Runs the real, atomic, audited import, then clears the staged file.
     */
    private function confirmImportAction(): Action
    {
        return Action::make('confirmImport')
            ->label(fn (): string => __('Confirmar importación (:count)', ['count' => $this->importPreview === null ? 0 : $this->importPreview['created']]))
            ->icon(Heroicon::OutlinedCheck)
            ->color('success')
            ->visible(fn (): bool => $this->importPreview !== null && $this->importPreview['created'] > 0)
            ->requiresConfirmation()
            ->modalHeading(__('Confirmar importación'))
            ->modalDescription(fn (): string => $this->previewSummary())
            ->action(function (): void {
                if ($this->importStashPath === null || ! is_file($this->importStashPath)) {
                    $this->resetImport();
                    Notification::make()->title(__('La previsualización caducó. Vuelve a subir el archivo.'))->warning()->send();

                    return;
                }

                $result = (new ImportMembers)->import($this->importStashPath);
                $errorCount = count($result['errors']);

                Notification::make()
                    ->title(__('Importación completada'))
                    ->body(__(':created creados · :skipped omitidos (duplicados) · :errors con errores · :pending sin consentimiento.', [
                        'created' => $result['created'],
                        'skipped' => $result['skipped'],
                        'errors' => $errorCount,
                        'pending' => $result['consent_pending'],
                    ]))
                    ->{$errorCount > 0 ? 'warning' : 'success'}()
                    ->persistent()
                    ->send();

                $this->resetImport();
            });
    }

    /** Discard a staged preview without importing. */
    private function cancelImportAction(): Action
    {
        return Action::make('cancelImport')
            ->label(__('Cancelar'))
            ->color('gray')
            ->link()
            ->visible(fn (): bool => $this->importPreview !== null)
            ->action(fn () => $this->resetImport());
    }

    /** The human-readable consequence shown in the confirm modal: what will be created, and the resulting ceiling. */
    private function previewSummary(): string
    {
        $preview = $this->importPreview ?? [];
        $lines = [];

        $lines[] = __(':created socios se crearán. :pending llegan sin consentimiento y quedarán marcados como pendientes.', [
            'created' => $preview['created'] ?? 0,
            'pending' => $preview['consent_pending'] ?? 0,
        ]);

        $errorCount = count($preview['errors'] ?? []);
        if ($errorCount > 0) {
            $lines[] = __(':errors filas con errores (p. ej. número de socio repetido) NO se importarán.', ['errors' => $errorCount]);
        }

        foreach ($preview['ceilings'] ?? [] as $ceiling) {
            $lines[] = __('Sede :location: :members socios activos → techo de stock :ceiling.', [
                'location' => $ceiling['location'],
                'members' => $ceiling['active_members'],
                'ceiling' => Weight::fromCentigrams($ceiling['ceiling_cg'])->formatted(),
            ]);
        }

        return implode("\n", $lines);
    }

    private function resetImport(): void
    {
        if ($this->importStashPath !== null && is_file($this->importStashPath)) {
            @unlink($this->importStashPath);
        }
        $this->importStashPath = null;
        $this->importPreview = null;
    }
}
