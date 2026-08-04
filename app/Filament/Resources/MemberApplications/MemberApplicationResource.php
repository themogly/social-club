<?php

namespace App\Filament\Resources\MemberApplications;

use App\Actions\Members\ApproveApplication;
use App\Enums\ApplicationStatus;
use App\Filament\Resources\MemberApplications\Pages\CreateMemberApplication;
use App\Filament\Resources\MemberApplications\Pages\EditMemberApplication;
use App\Filament\Resources\MemberApplications\Pages\ListMemberApplications;
use App\Filament\Resources\MemberApplications\Pages\ViewMemberApplication;
use App\Filament\Resources\MemberApplications\Schemas\MemberApplicationForm;
use App\Filament\Resources\MemberApplications\Schemas\MemberApplicationInfolist;
use App\Filament\Resources\MemberApplications\Tables\MemberApplicationsTable;
use App\Mail\ApplicationApprovedMail;
use App\Mail\ApplicationRejectedMail;
use App\Models\MemberApplication;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class MemberApplicationResource extends Resource
{
    protected static ?string $model = MemberApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('Solicitudes');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Socios');
    }

    public static function getModelLabel(): string
    {
        return __('solicitud');
    }

    public static function getPluralModelLabel(): string
    {
        return __('solicitudes');
    }

    public static function form(Schema $schema): Schema
    {
        return MemberApplicationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MemberApplicationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MemberApplicationsTable::configure($table);
    }

    /**
     * Review-queue decisions. Only a SUBMITTED, PENDING application may be decided, and only by a reviewer
     * (`applications.review`). An unredeemed invitation (submitted_at = null) is not a decision — it is chased
     * (Reenviar / Copiar enlace) or killed (Anular), which is why those live on the invite, not here. Approval
     * routes through the audited domain action, which re-runs the submission + age gates server-side.
     *
     * @return array<int, Action>
     */
    public static function recordActions(): array
    {
        return [
            self::approveAction(),
            self::rejectAction(),
            self::waitingListAction(),
        ];
    }

    /**
     * A review DECISION is offered only once the applicant has actually SUBMITTED (submitted_at set). A PENDING
     * record with submitted_at = null is an outstanding invitation, not an application — approving it would run
     * the age gate on an empty payload and tell the operator a non-existent applicant is underage (prompt 152).
     * Rejecting or waiting-listing a person who never applied is meaningless too; to end an unredeemed invite,
     * revoke it (Anular). `applications.review` still gates every decision.
     */
    private static function isDecidable(MemberApplication $record): bool
    {
        return $record->status === ApplicationStatus::PENDING
            && $record->submitted_at !== null
            && (Auth::user()?->can('applications.review') ?? false);
    }

    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('Aprobar'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->schema([
                Toggle::make('allow_duplicate')
                    ->label(__('Aprobar aunque haya un posible duplicado'))
                    ->helperText(__('Sólo si has verificado que es una persona distinta.'))
                    ->default(false),
            ])
            ->visible(fn (MemberApplication $record): bool => self::isDecidable($record))
            ->action(function (array $data, MemberApplication $record): void {
                try {
                    $member = (new ApproveApplication)->handle($record, allowDuplicate: (bool) ($data['allow_duplicate'] ?? false));

                    if ($member->email !== null) {
                        Mail::to($member->email)->queue(new ApplicationApprovedMail($member->fullName(), (string) $member->member_no));
                    }

                    Notification::make()
                        ->title(__('Solicitud aprobada'))
                        ->success()
                        ->send();
                } catch (RuntimeException $e) {
                    Notification::make()
                        ->title(__('No se pudo aprobar la solicitud'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')
            ->label(__('Rechazar'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (MemberApplication $record): bool => self::isDecidable($record))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Motivo del rechazo'))
                    ->required(),
            ])
            ->action(function (MemberApplication $record, array $data): void {
                $reason = $data['reason'] ?? null;

                $record->update([
                    'status' => ApplicationStatus::REJECTED,
                    'reject_reason' => $reason,
                    'reviewed_by' => Auth::id(),
                    'reviewed_at' => now(),
                ]);

                $email = data_get($record->payload, 'email');
                if (is_string($email) && $email !== '') {
                    $name = trim((string) data_get($record->payload, 'first_name').' '.(string) data_get($record->payload, 'last_name'));
                    Mail::to($email)->queue(new ApplicationRejectedMail($name !== '' ? $name : $email, is_string($reason) ? $reason : null));
                }

                Notification::make()
                    ->title(__('Solicitud rechazada'))
                    ->success()
                    ->send();
            });
    }

    public static function waitingListAction(): Action
    {
        return Action::make('waitingList')
            ->label(__('Lista de espera'))
            ->icon(Heroicon::OutlinedClock)
            ->color('warning')
            ->requiresConfirmation()
            ->visible(fn (MemberApplication $record): bool => self::isDecidable($record))
            ->action(function (MemberApplication $record): void {
                $record->update([
                    'status' => ApplicationStatus::WAITING_LIST,
                    'reviewed_by' => Auth::id(),
                    'reviewed_at' => now(),
                ]);

                Notification::make()
                    ->title(__('Movida a lista de espera'))
                    ->success()
                    ->send();
            });
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
            'index' => ListMemberApplications::route('/'),
            'create' => CreateMemberApplication::route('/create'),
            'view' => ViewMemberApplication::route('/{record}'),
            'edit' => EditMemberApplication::route('/{record}/edit'),
        ];
    }
}
