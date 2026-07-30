<?php

namespace App\Filament\Resources\DataRequests;

use App\Actions\Members\AnonymiseMember;
use App\Actions\Members\ExportMemberData;
use App\Actions\RecordAuditLog;
use App\Enums\DataRequestType;
use App\Filament\Resources\DataRequests\Pages\CreateDataRequest;
use App\Filament\Resources\DataRequests\Pages\ListDataRequests;
use App\Filament\Resources\DataRequests\Schemas\DataRequestForm;
use App\Filament\Resources\DataRequests\Tables\DataRequestsTable;
use App\Models\DataRequest;
use App\Models\Member;
use App\Models\User;
use App\Support\ActiveScope;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Solicitudes RGPD — the subject-rights workflow (RGPD Arts. 15–22). Each request is
 * logged with its type, requester, received date and (once handled) completion date, so
 * the club can evidence it responded in time. Gated by DataRequestPolicy on
 * `data.request.handle` (owner).
 *
 * Fulfilment WRAPS the domain actions, never reimplements them:
 *  - access / portability  → ExportMemberData (the same data pack the member self-export
 *                            produces) streamed as JSON;
 *  - erasure               → AnonymiseMember (anonymise-not-delete; the ledger is kept),
 *                            additionally gated on `data.erase`.
 * Every fulfilment stamps `completed_at` + `handled_by` and writes a RecordAuditLog entry
 * carrying the requester and completion date.
 */
class DataRequestResource extends Resource
{
    protected static ?string $model = DataRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 50;

    public static function getNavigationLabel(): string
    {
        return __('Solicitudes RGPD');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sistema');
    }

    public static function getModelLabel(): string
    {
        return __('solicitud RGPD');
    }

    public static function getPluralModelLabel(): string
    {
        return __('solicitudes RGPD');
    }

    public static function form(Schema $schema): Schema
    {
        return DataRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DataRequestsTable::configure($table);
    }

    /** Scope to the active organisation (no global org scope on this model). */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('organisation_id', app(ActiveScope::class)->organisationId())
            ->with(['member', 'handledBy']);
    }

    // --- Fulfilment core (testable seams; the actions below are thin wrappers) ---------

    /**
     * Access / portability: assemble the member's complete data pack (via ExportMemberData)
     * and record completion. Gated on `data.request.handle`.
     *
     * @return array<string, mixed>
     */
    public static function generateDataPack(DataRequest $record, User $actor): array
    {
        abort_unless($actor->can('data.request.handle'), 403);
        $member = self::subject($record);

        $pack = (new ExportMemberData)->handle($member);

        self::complete($record, $actor);

        return $pack;
    }

    /**
     * Erasure: anonymise-not-delete (via AnonymiseMember — the financial + consumption
     * ledger is kept intact), then record completion. Additionally gated on `data.erase`.
     */
    public static function eraseSubject(DataRequest $record, User $actor): void
    {
        abort_unless($actor->can('data.request.handle') && $actor->can('data.erase'), 403);
        $member = self::subject($record);

        (new AnonymiseMember)->handle($member);

        self::complete($record, $actor);
    }

    /** Rectification / objection / restriction: no automated transform — record completion. */
    public static function markResolved(DataRequest $record, User $actor, ?string $notes = null): void
    {
        abort_unless($actor->can('data.request.handle'), 403);

        if (filled($notes)) {
            $record->notes = $notes;
        }

        self::complete($record, $actor);
    }

    private static function subject(DataRequest $record): Member
    {
        $member = $record->member;
        abort_if($member === null, 404);

        return $member;
    }

    /** Stamp completion + handler and write the audit evidence (requester + completion date). */
    private static function complete(DataRequest $record, User $actor): void
    {
        $record->forceFill([
            'completed_at' => now(),
            'handled_by' => $actor->id,
        ])->save();

        (new RecordAuditLog)->handle('data_request.fulfilled', $record, null, [
            'type' => $record->type->value,
            'requester_member_id' => $record->member_id,
            'completed_at' => $record->completed_at?->toIso8601String(),
        ]);
    }

    // --- Actions (list + view headers) -------------------------------------------------

    /**
     * The fulfilment actions available on a single request (view page header + table row).
     *
     * @return array<int, Action>
     */
    public static function recordActions(): array
    {
        return [
            self::fulfilExportAction(),
            self::fulfilErasureAction(),
            self::markResolvedAction(),
        ];
    }

    /** Access / portability — generate the data pack and stream it as JSON. */
    public static function fulfilExportAction(): Action
    {
        return Action::make('fulfilExport')
            ->label(__('Generar datos'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (DataRequest $record): bool => $record->completed_at === null
                && in_array($record->type, [DataRequestType::ACCESS, DataRequestType::PORTABILITY], true))
            ->action(function (DataRequest $record): StreamedResponse {
                /** @var User $actor */
                $actor = Auth::user();
                $pack = self::generateDataPack($record, $actor);
                $memberNo = $record->member->member_no ?? $record->member_id;

                return response()->streamDownload(
                    fn () => print ((string) json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
                    'solicitud-datos-'.$memberNo.'.json',
                    ['Content-Type' => 'application/json; charset=UTF-8'],
                );
            });
    }

    /** Erasure — anonymise the subject (irreversible), additionally gated on `data.erase`. */
    public static function fulfilErasureAction(): Action
    {
        return Action::make('fulfilErasure')
            ->label(__('Anonimizar socio'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Atender solicitud de supresión'))
            ->modalDescription(__('Anonimiza los datos personales del socio de forma irreversible. El libro contable y de consumo se conserva intacto y atribuido al registro anonimizado.'))
            ->visible(fn (DataRequest $record): bool => $record->completed_at === null
                && $record->type === DataRequestType::ERASE
                && (Auth::user()?->can('data.erase') ?? false))
            ->action(function (DataRequest $record): void {
                /** @var User $actor */
                $actor = Auth::user();
                self::eraseSubject($record, $actor);

                Notification::make()->title(__('Solicitud de supresión atendida'))->success()->send();
            });
    }

    /** Rectification / objection / restriction — record completion with optional notes. */
    public static function markResolvedAction(): Action
    {
        return Action::make('markResolved')
            ->label(__('Marcar atendida'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (DataRequest $record): bool => $record->completed_at === null
                && in_array($record->type, [DataRequestType::RECTIFY, DataRequestType::OBJECT, DataRequestType::RESTRICT], true))
            ->schema([
                Textarea::make('notes')->label(__('Notas de resolución'))->rows(3),
            ])
            ->action(function (DataRequest $record, array $data): void {
                /** @var User $actor */
                $actor = Auth::user();
                $notes = is_string($data['notes'] ?? null) ? $data['notes'] : null;
                self::markResolved($record, $actor, $notes);

                Notification::make()->title(__('Solicitud marcada como atendida'))->success()->send();
            });
    }

    public static function typeLabel(DataRequestType $type): string
    {
        return match ($type) {
            DataRequestType::ACCESS => __('Acceso'),
            DataRequestType::RECTIFY => __('Rectificación'),
            DataRequestType::ERASE => __('Supresión'),
            DataRequestType::PORTABILITY => __('Portabilidad'),
            DataRequestType::OBJECT => __('Oposición'),
            DataRequestType::RESTRICT => __('Limitación'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return collect(DataRequestType::cases())
            ->mapWithKeys(fn (DataRequestType $type): array => [$type->value => self::typeLabel($type)])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDataRequests::route('/'),
            'create' => CreateDataRequest::route('/create'),
        ];
    }
}
