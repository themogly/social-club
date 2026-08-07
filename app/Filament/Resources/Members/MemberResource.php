<?php

namespace App\Filament\Resources\Members;

use App\Actions\Documents\GenerateMemberDocument;
use App\Actions\Members\CancelMembership;
use App\Actions\Members\ExportMemberData;
use App\Actions\Members\ManageTemporaryMember;
use App\Actions\Members\SendMemberCard;
use App\Actions\Members\SetMemberLimits;
use App\Actions\Members\TransitionMemberStatus;
use App\Actions\Members\UpdateDeclaredForecast;
use App\Actions\Members\WaiveCarencia;
use App\Enums\DataRequestType;
use App\Enums\MemberDocumentType;
use App\Enums\MemberStatus;
use App\Filament\Resources\DataRequests\DataRequestResource;
use App\Filament\Resources\Members\Pages\CreateMember;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Filament\Resources\Members\RelationManagers\AvaladosRelationManager;
use App\Filament\Resources\Members\RelationManagers\ConsentsRelationManager;
use App\Filament\Resources\Members\RelationManagers\ConsumptionRelationManager;
use App\Filament\Resources\Members\RelationManagers\DiscountsRelationManager;
use App\Filament\Resources\Members\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Members\RelationManagers\MembershipsRelationManager;
use App\Filament\Resources\Members\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Members\RelationManagers\RefundsRelationManager;
use App\Filament\Resources\Members\RelationManagers\SanctionsRelationManager;
use App\Filament\Resources\Members\RelationManagers\VisitsRelationManager;
use App\Filament\Resources\Members\RelationManagers\WalletTransactionsRelationManager;
use App\Filament\Resources\Members\Schemas\MemberForm;
use App\Filament\Resources\Members\Schemas\MemberInfolist;
use App\Filament\Resources\Members\Tables\MembersTable;
use App\Models\DataRequest;
use App\Models\Member;
use App\Models\User;
use App\Support\Settings;
use App\Support\Weight;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('Socios');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Socios');
    }

    public static function getModelLabel(): string
    {
        return __('socio');
    }

    public static function getPluralModelLabel(): string
    {
        return __('socios');
    }

    public static function form(Schema $schema): Schema
    {
        return MemberForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MemberInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MembersTable::configure($table);
    }

    /**
     * Lifecycle actions available on a single member (view/edit page headers).
     * Every status change routes through the audited domain actions — never a
     * raw column write from the UI.
     *
     * @return array<int, Action>
     */
    public static function recordActions(): array
    {
        return [
            self::resendQrAction(),
            self::generateDocumentAction(),
            self::updateDeclaredForecastAction(),
            self::waiveCarenciaAction(),
            self::setLimitsAction(),
            self::recordBajaAction(),
            self::suspendAction(),
            self::expelAction(),
            self::reactivateAction(),
            self::convertTemporaryAction(),
            self::extendTemporaryAction(),
            self::makeTemporaryAction(),
            self::exportDataAction(),
            self::requestErasureAction(),
        ];
    }

    /**
     * Generar documento — produce an immutable, versioned member document (Solicitud de
     * alta / Previsión de consumo / Acta de sanción) through the domain action, which
     * renders the PDF to the private disk and freezes a snapshot. Gated on
     * `documents.generate`.
     */
    public static function generateDocumentAction(): Action
    {
        return Action::make('generateDocument')
            ->label(__('Generar documento'))
            ->icon(Heroicon::OutlinedDocumentPlus)
            ->visible(fn (): bool => Auth::user()?->can('documents.generate') ?? false)
            ->schema([
                Select::make('type')
                    ->label(__('Tipo de documento'))
                    ->options([
                        MemberDocumentType::REGISTRATION_FORM->value => __('Solicitud de alta'),
                        MemberDocumentType::DECLARATION->value => __('Previsión de consumo'),
                        MemberDocumentType::SANCTION_ACT->value => __('Acta de sanción'),
                    ])
                    ->required(),
            ])
            ->action(function (Member $record, array $data): void {
                /** @var User $actor */
                $actor = Auth::user();
                (new GenerateMemberDocument)->handle($record, MemberDocumentType::from((string) $data['type']), $actor);

                Notification::make()->title(__('Documento generado'))->success()->send();
            });
    }

    public static function resendQrAction(): Action
    {
        return Action::make('resendQr')
            ->label(__('Reenviar carné QR'))
            ->icon(Heroicon::OutlinedQrCode)
            ->requiresConfirmation()
            ->visible(fn (Member $record): bool => filled($record->email))
            ->action(function (Member $record): void {
                // Prompt 85: through the single, QUEUED SendMemberCard path (was a synchronous Mail::send()).
                (new SendMemberCard)->handle($record);

                Notification::make()
                    ->title(__('Carné QR reenviado'))
                    ->success()
                    ->send();
            });
    }

    public static function suspendAction(): Action
    {
        return Action::make('suspend')
            ->label(__('Suspender'))
            ->icon(Heroicon::OutlinedPauseCircle)
            ->color('warning')
            ->visible(fn (): bool => Auth::user()?->can('member.sanction') ?? false)
            ->schema([
                Textarea::make('reason')
                    ->label(__('Motivo'))
                    ->required(),
            ])
            ->action(function (Member $record, array $data): void {
                (new TransitionMemberStatus)->handle($record, MemberStatus::SUSPENDED, $data['reason'] ?? null);

                Notification::make()
                    ->title(__('Socio suspendido'))
                    ->success()
                    ->send();
            });
    }

    public static function expelAction(): Action
    {
        return Action::make('expel')
            ->label(__('Expulsar'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (): bool => Auth::user()?->can('member.sanction') ?? false)
            ->schema([
                Textarea::make('reason')
                    ->label(__('Motivo'))
                    ->required(),
            ])
            ->action(function (Member $record, array $data): void {
                (new TransitionMemberStatus)->handle($record, MemberStatus::EXPELLED, $data['reason'] ?? null);

                Notification::make()
                    ->title(__('Socio expulsado'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Levantar carencia — end the member's waiting period early (manager+, `carencia.waive`), reasoned
     * and audited. Only offered while the member is actually IN carencia; wires up the previously
     * caller-less WaiveCarencia action (prompt 51).
     */
    /**
     * Límites personalizados — set/clear this member's per-member gram override (prompt 81), the caps at the
     * TOP of the precedence chain (member → tier → location → org). Gated on member.limits.set; grams at the
     * edge, centigrams stored; the SetMemberLimits action is the single writer and audits the change.
     */
    public static function setLimitsAction(): Action
    {
        return Action::make('setLimits')
            ->label(__('Límites personalizados'))
            ->icon(Heroicon::OutlinedScale)
            ->visible(fn (): bool => Auth::user()?->can('member.limits.set') ?? false)
            ->fillForm(fn (Member $record): array => [
                'daily_limit_g' => $record->daily_limit_cg !== null ? $record->daily_limit_cg / 100 : null,
                'monthly_limit_g' => $record->monthly_limit_cg !== null ? $record->monthly_limit_cg / 100 : null,
            ])
            ->schema([
                TextInput::make('daily_limit_g')
                    ->label(__('Límite diario (g)'))
                    ->numeric()->minValue(0)->step('0.01')
                    ->helperText(__('Vacío = usar el límite de la cuota, la sede o la organización.')),
                TextInput::make('monthly_limit_g')
                    ->label(__('Límite mensual (g)'))
                    ->numeric()->minValue(0)->step('0.01'),
                Textarea::make('reason')
                    ->label(__('Motivo'))
                    ->required()
                    ->helperText(__('Queda registrado en la auditoría.')),
            ])
            ->action(function (Member $record, array $data): void {
                $actor = Auth::user();
                if (! $actor instanceof User) {
                    return;
                }

                $toCg = fn ($g): ?int => ($g === null || $g === '') ? null : (int) round_half_up((float) $g * 100);

                (new SetMemberLimits)->handle(
                    $record,
                    $actor,
                    $toCg($data['daily_limit_g'] ?? null),
                    $toCg($data['monthly_limit_g'] ?? null),
                    (string) ($data['reason'] ?? ''),
                );

                Notification::make()->title(__('Límites del socio actualizados'))->success()->send();
            });
    }

    /**
     * Registrar baja — a member's VOLUNTARY departure (prompt 80). Cancels their active membership(s) and
     * records the baja (INACTIVE + left_at) via CancelMembership, so the libro de socios shows a leave date.
     * Distinct from Suspender/Expulsar (punitive sanctions); gated on members.edit, not member.sanction, and
     * hidden once the member has already left.
     */
    public static function recordBajaAction(): Action
    {
        return Action::make('recordBaja')
            ->label(__('Registrar baja'))
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->visible(fn (Member $record): bool => (Auth::user()?->can('members.edit') ?? false)
                && $record->left_at === null
                && ! in_array($record->status, [MemberStatus::INACTIVE, MemberStatus::EXPIRED, MemberStatus::EXPELLED], true))
            ->requiresConfirmation()
            ->schema([
                Textarea::make('reason')
                    ->label(__('Motivo de la baja'))
                    ->required()
                    ->helperText(__('Queda registrado en la auditoría.')),
            ])
            ->action(function (Member $record, array $data): void {
                $actor = Auth::user();
                if (! $actor instanceof User) {
                    return;
                }

                (new CancelMembership)->handle($record, $actor, (string) ($data['reason'] ?? ''));

                Notification::make()->title(__('Baja registrada'))->success()->send();
            });
    }

    public static function waiveCarenciaAction(): Action
    {
        return Action::make('waiveCarencia')
            ->label(__('Levantar carencia'))
            ->icon(Heroicon::OutlinedClock)
            ->color('warning')
            ->visible(fn (Member $record): bool => (Auth::user()?->can('carencia.waive') ?? false)
                && $record->carencia_ends_at !== null && $record->carencia_ends_at->isFuture())
            ->requiresConfirmation()
            ->schema([
                Textarea::make('reason')
                    ->label(__('Motivo'))
                    ->required()
                    ->helperText(__('Queda registrado en la auditoría.')),
            ])
            ->action(function (Member $record, array $data): void {
                $actor = Auth::user();
                if (! $actor instanceof User) {
                    return;
                }

                (new WaiveCarencia)->handle($record, $actor, $data['reason'] ?? null);

                Notification::make()->title(__('Carencia levantada'))->success()->send();
            });
    }

    /**
     * Actualizar previsión declarada — the SINGLE writer for `declared_monthly_cg` (prompt 72). A declared
     * legal figure, not a contact detail, so it is edited here (audited `member.forecast.updated` via the
     * previously caller-less UpdateDeclaredForecast) rather than inline on the member form. Changing it may
     * leave a signed declaration out of date — the drift is then flagged on the record + documents tab.
     */
    public static function updateDeclaredForecastAction(): Action
    {
        return Action::make('updateDeclaredForecast')
            ->label(__('Actualizar previsión declarada'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('primary')
            ->visible(fn (Member $record): bool => Auth::user()?->can('update', $record) ?? false)
            ->schema([
                TextInput::make('declared_monthly_g')
                    ->label(__('Previsión mensual (g)'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->suffix(__('g'))
                    ->required()
                    ->default(fn (Member $record): ?string => filled($record->declared_monthly_cg)
                        ? number_format((int) $record->declared_monthly_cg / 100, 2, '.', '')
                        : null)
                    ->helperText(__('Al cambiarla, una declaración firmada existente quedará desactualizada y deberá regenerarse y volver a firmarse.')),
            ])
            ->action(function (Member $record, array $data): void {
                (new UpdateDeclaredForecast)->handle($record, Weight::fromGrams((string) $data['declared_monthly_g'])->centigrams);

                Notification::make()->title(__('Previsión declarada actualizada'))->success()->send();
            });
    }

    public static function reactivateAction(): Action
    {
        return Action::make('reactivate')
            ->label(__('Reactivar'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Member $record): bool => $record->status !== MemberStatus::ACTIVE)
            ->action(function (Member $record): void {
                (new TransitionMemberStatus)->handle($record, MemberStatus::ACTIVE);

                Notification::make()
                    ->title(__('Socio reactivado'))
                    ->success()
                    ->send();
            });
    }

    public static function exportDataAction(): Action
    {
        return Action::make('exportData')
            ->label(__('Exportar datos (RGPD)'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(function (Member $record): StreamedResponse {
                $data = (new ExportMemberData)->handle($record);

                return response()->streamDownload(
                    fn () => print ((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
                    'member-'.$record->member_no.'.json',
                );
            });
    }

    /**
     * Supresión (RGPD Art. 17) — reachable FROM the member, which is the whole point (admin audit, Phase C).
     *
     * Erasure already existed and already worked, in `DataRequestResource::eraseSubject()` → `AnonymiseMember`
     * (anonymise-not-delete: the financial and consumption ledger survives, which is what makes the club's
     * books still add up afterwards). What did not exist was any way to GET there from the person you are
     * looking at. The member record offered `Eliminar`, which soft-deletes — the name, DNI, email, phone,
     * photo and ID scan all remain in the database and on the encrypted disk — and an owner told *"erase this
     * person"* would reasonably press it and believe they had complied. That is an Article 17 misreading with
     * legal consequences, and the fix is a signpost, not a second writer.
     *
     * So this creates the ERASE request and sends the operator to it. It deliberately does NOT anonymise here:
     * the DataRequest record IS the evidence that the club received a request and answered it in time, which
     * is itself an obligation, and fulfilment stays behind `data.erase` on the one screen that owns it.
     */
    public static function requestErasureAction(): Action
    {
        return Action::make('requestErasure')
            ->label(__('Solicitar supresión (RGPD)'))
            ->icon(Heroicon::OutlinedShieldExclamation)
            ->color('danger')
            ->visible(fn (): bool => Auth::user()?->can('data.request.handle') ?? false)
            ->requiresConfirmation()
            ->modalHeading(__('Registrar una solicitud de supresión'))
            ->modalDescription(__('Se registra la solicitud y se abre para tramitarla. La supresión anonimiza al socio: el libro contable y de consumo se conservan, como exige la normativa. «Eliminar» NO es una supresión — solo oculta la ficha.'))
            ->modalSubmitActionLabel(__('Registrar solicitud'))
            ->action(function (Member $record): void {
                $request = DataRequest::create([
                    'organisation_id' => $record->organisation_id,
                    'member_id' => $record->id,
                    'type' => DataRequestType::ERASE,
                    'requested_at' => now(),
                ]);

                Notification::make()
                    ->title(__('Solicitud de supresión registrada'))
                    ->body(__('Trámitala en Solicitudes RGPD.'))
                    ->success()
                    ->send();

                // The resource has index + create pages only; the fulfilment actions live on the list row.
                redirect(DataRequestResource::getUrl('index', ['tableSearch' => $record->member_no]));
            });
    }

    /** Manager action: make a temporary member permanent — reuses the record, audited. */
    public static function convertTemporaryAction(): Action
    {
        return Action::make('convertTemporary')
            ->label(__('Convertir a socio estándar'))
            ->icon(Heroicon::OutlinedArrowUpCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Member $record): bool => $record->isTemporary() && (Auth::user()?->can('members.create') ?? false))
            ->action(function (Member $record): void {
                (new ManageTemporaryMember)->convertToStandard($record);

                Notification::make()->title(__('Socio convertido a estándar'))->success()->send();
            });
    }

    /**
     * Manager action: make a standard member temporary (prompt 165) — an ACTION with a reason, not a
     * form toggle. Converting a member's kind schedules their automatic anonymisation, which is at least
     * as consequential as a status change, and nothing that consequential should be flippable while
     * someone is editing a phone number. Gated additionally on `temporary_members_enabled`, unlike the
     * two actions below: a club that has switched the feature OFF must still be able to rescue the
     * temporary members it already has, but must not be able to create new ones.
     */
    public static function makeTemporaryAction(): Action
    {
        return Action::make('makeTemporary')
            ->label(__('Convertir a socio temporal'))
            // Mirrors the up-circle on "convertir a estándar" — the two directions read as a pair.
            ->icon(Heroicon::OutlinedArrowDownCircle)
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(__('La estancia empieza hoy, no en la fecha de alta. Al vencer, el socio se anonimiza automáticamente.'))
            ->visible(fn (Member $record): bool => ! $record->isTemporary()
                && (bool) Settings::get('temporary_members_enabled', false)
                && (Auth::user()?->can('members.create') ?? false))
            ->schema([
                TextInput::make('days')->label(__('Días de estancia'))->numeric()->minValue(1)->required()
                    ->default(fn (): int => (int) Settings::get('temporary_window_days', 30))
                    ->helperText(__('Se cuentan desde hoy. No relaja ninguna verificación (edad, aval, carencia, límites).')),
                Textarea::make('reason')->label(__('Motivo'))->required()->maxLength(500),
            ])
            ->action(function (Member $record, array $data): void {
                (new ManageTemporaryMember)->convertToTemporary($record, (string) $data['reason'], (int) $data['days']);

                Notification::make()->title(__('Socio convertido a temporal'))->success()->send();
            });
    }

    /** Manager action: push a temporary member's window out — audited. */
    public static function extendTemporaryAction(): Action
    {
        return Action::make('extendTemporary')
            ->label(__('Ampliar estancia temporal'))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->visible(fn (Member $record): bool => $record->isTemporary() && (Auth::user()?->can('members.create') ?? false))
            ->schema([
                TextInput::make('days')->label(__('Días adicionales'))->numeric()->minValue(1)->required()
                    ->default(fn (): int => (int) Settings::get('temporary_window_days', 30)),
            ])
            ->action(function (Member $record, array $data): void {
                (new ManageTemporaryMember)->extend($record, (int) $data['days']);

                Notification::make()->title(__('Estancia temporal ampliada'))->success()->send();
            });
    }

    public static function getRelations(): array
    {
        return [
            MembershipsRelationManager::class,
            ConsumptionRelationManager::class,
            VisitsRelationManager::class,
            WalletTransactionsRelationManager::class,
            OrdersRelationManager::class,
            RefundsRelationManager::class,
            DiscountsRelationManager::class,
            SanctionsRelationManager::class,
            AvaladosRelationManager::class,
            DocumentsRelationManager::class,
            ConsentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
            'create' => CreateMember::route('/create'),
            'view' => ViewMember::route('/{record}'),
            'edit' => EditMember::route('/{record}/edit'),
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
