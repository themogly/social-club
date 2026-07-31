<?php

namespace App\Filament\Resources\Members;

use App\Actions\Documents\GenerateMemberDocument;
use App\Actions\Members\ExportMemberData;
use App\Actions\Members\IssueMemberToken;
use App\Actions\Members\TransitionMemberStatus;
use App\Enums\MemberDocumentType;
use App\Enums\MemberStatus;
use App\Filament\Resources\Members\Pages\CreateMember;
use App\Filament\Resources\Members\Pages\EditMember;
use App\Filament\Resources\Members\Pages\ListMembers;
use App\Filament\Resources\Members\Pages\ViewMember;
use App\Filament\Resources\Members\RelationManagers\ConsentsRelationManager;
use App\Filament\Resources\Members\RelationManagers\DiscountsRelationManager;
use App\Filament\Resources\Members\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Members\RelationManagers\MembershipsRelationManager;
use App\Filament\Resources\Members\RelationManagers\WalletTransactionsRelationManager;
use App\Filament\Resources\Members\Schemas\MemberForm;
use App\Filament\Resources\Members\Schemas\MemberInfolist;
use App\Filament\Resources\Members\Tables\MembersTable;
use App\Mail\MemberCardMail;
use App\Models\Member;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
            self::suspendAction(),
            self::expelAction(),
            self::reactivateAction(),
            self::exportDataAction(),
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
                $token = (new IssueMemberToken)->handle($record);
                Mail::to($record->email)->send(new MemberCardMail($record, $token));

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

    public static function getRelations(): array
    {
        return [
            MembershipsRelationManager::class,
            DiscountsRelationManager::class,
            WalletTransactionsRelationManager::class,
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
