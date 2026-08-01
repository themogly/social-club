<?php

namespace App\Filament\Resources\Convocatorias;

use App\Actions\Governance\IssueConvocatoria;
use App\Filament\Concerns\GuardsStatutoryDocuments;
use App\Filament\Resources\Convocatorias\Pages\CreateConvocatoria;
use App\Filament\Resources\Convocatorias\Pages\EditConvocatoria;
use App\Filament\Resources\Convocatorias\Pages\ListConvocatorias;
use App\Filament\Resources\Convocatorias\Pages\ViewConvocatoria;
use App\Filament\Resources\Convocatorias\RelationManagers\RecipientsRelationManager;
use App\Filament\Resources\Convocatorias\Schemas\ConvocatoriaForm;
use App\Filament\Resources\Convocatorias\Schemas\ConvocatoriaInfolist;
use App\Filament\Resources\Convocatorias\Tables\ConvocatoriasTable;
use App\Models\Convocatoria;
use App\Models\User;
use App\Support\OrganisationIdentity;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Convocatorias — convening a general assembly and emailing the membership (prompt 88). This resource only
 * WRAPS the domain: issuing routes through App\Actions\Governance\IssueConvocatoria, which freezes the
 * recipient roll and queues one email per member. A DRAFT can be edited; an ISSUED one is immutable and
 * shows its frozen roll. Gated by ConvocatoriaPolicy on `minutes.manage` (governance).
 */
class ConvocatoriaResource extends Resource
{
    use GuardsStatutoryDocuments;

    protected static ?string $model = Convocatoria::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?int $navigationSort = 25;

    public static function getNavigationLabel(): string
    {
        return __('Convocatorias');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Documentos');
    }

    public static function getModelLabel(): string
    {
        return __('convocatoria');
    }

    public static function getPluralModelLabel(): string
    {
        return __('convocatorias');
    }

    public static function form(Schema $schema): Schema
    {
        return ConvocatoriaForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ConvocatoriaInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConvocatoriasTable::configure($table);
    }

    /**
     * Emitir — freeze the roll and send the notice through IssueConvocatoria. Only while a draft, and only
     * for a user who can manage minutes. Irreversible: it makes the convocatoria immutable and emails the
     * whole membership, so it confirms first and states plainly what it will do.
     */
    public static function issueAction(): Action
    {
        return Action::make('issue')
            ->label(__('Emitir convocatoria'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('Emitir convocatoria'))
            ->modalDescription(__('Se fijará la lista de socios convocados (a día de hoy) y se enviará un email a cada socio con dirección. Una vez emitida, la convocatoria no podrá modificarse.'))
            ->modalSubmitActionLabel(__('Emitir y notificar'))
            ->visible(fn (Convocatoria $record): bool => ! $record->isIssued() && (Auth::user()?->can('minutes.manage') ?? false))
            ->action(function (Convocatoria $record): void {
                /** @var User $actor */
                $actor = Auth::user();
                try {
                    $issued = (new IssueConvocatoria)->handle($record, $actor);
                    Notification::make()
                        ->title(__('Convocatoria emitida'))
                        ->body(__(':notified socios notificados · :no_email sin email', [
                            'notified' => $issued->recipients()->whereNotNull('notified_at')->count(),
                            'no_email' => $issued->recipients()->whereNull('notified_at')->count(),
                        ]))
                        ->success()
                        ->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title(__('No se pudo emitir'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** Exportar PDF — the formal notice document (order of the day, quorum, first/second call). */
    public static function pdfAction(): Action
    {
        return Action::make('pdf')
            ->label(__('PDF'))
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->action(function (Convocatoria $record): ?StreamedResponse {
                if (! self::hasStatutoryIdentity()) {
                    return null;
                }

                $content = Pdf::loadView('documents.convocatoria', self::pdfData($record))->output();

                return response()->streamDownload(fn () => print $content, 'convocatoria-'.$record->id.'.pdf', [
                    'Content-Type' => 'application/pdf',
                ]);
            });
    }

    /** @return array<string, mixed> */
    public static function pdfData(Convocatoria $record): array
    {
        return [
            'convocatoria' => $record,
            'identity' => OrganisationIdentity::current(),
        ];
    }

    public static function getRelations(): array
    {
        return [
            RecipientsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConvocatorias::route('/'),
            'create' => CreateConvocatoria::route('/create'),
            'view' => ViewConvocatoria::route('/{record}'),
            'edit' => EditConvocatoria::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
