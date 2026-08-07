<?php

namespace App\Filament\Resources\MemberApplications;

use App\Actions\Members\ApproveApplication;
use App\Actions\ResolveLocale;
use App\Enums\ApplicationStatus;
use App\Filament\Resources\MemberApplications\Pages\EditMemberApplication;
use App\Filament\Resources\MemberApplications\Pages\ListMemberApplications;
use App\Filament\Resources\MemberApplications\Pages\ViewMemberApplication;
use App\Filament\Resources\MemberApplications\Schemas\MemberApplicationForm;
use App\Filament\Resources\MemberApplications\Schemas\MemberApplicationInfolist;
use App\Filament\Resources\MemberApplications\Tables\MemberApplicationsTable;
use App\Mail\ApplicationApprovedMail;
use App\Mail\ApplicationInviteMail;
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
use Livewire\Component;
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

    /**
     * Invitation lifecycle actions — shared by the list table AND the View page (prompt 154), so an operator
     * gets them wherever they land. All three gate on `isInviteLive()`: a revoked/expired/decided invite offers
     * none of them (a dead link is never offered for copying). `inviteUrl()` is the ONE way to derive the link
     * (from the retained encrypted token) — never re-derived elsewhere.
     *
     * @return array<int, Action>
     */
    public static function inviteActions(): array
    {
        return [self::copyLinkAction(), self::resendAction(), self::revokeAction()];
    }

    /**
     * An OUTSTANDING invitation: the link is still live AND nobody has submitted yet. Copy/resend/revoke only
     * make sense here — a SUBMITTED application (even while still PENDING) is a decision to make, not a link to
     * chase, so the invite actions drop off it on both the list and the View page (prompt 154).
     */
    private static function isOutstandingInvite(MemberApplication $record): bool
    {
        return $record->isInviteLive() && $record->submitted_at === null;
    }

    /** Re-display the shareable link (recoverable after the toast is gone — prompt 45). */
    public static function copyLinkAction(): Action
    {
        return Action::make('copyLink')
            ->label(__('Copiar enlace'))
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn (MemberApplication $record): bool => self::isOutstandingInvite($record)
                && $record->inviteUrl() !== null
                && (Auth::user()?->can('members.create') ?? false)) // revealing the invite link is invite management
            ->action(function (MemberApplication $record, Component $livewire): void {
                $livewire->js('navigator.clipboard.writeText('.json_encode((string) $record->inviteUrl()).')');

                Notification::make()->title(__('Enlace copiado'))->success()->send();
            });
    }

    /** Reenviar invitación — re-email the SAME token to the applicant's email (prompts 45 + 149: queued, best-effort). */
    public static function resendAction(): Action
    {
        return Action::make('resend')
            ->label(__('Reenviar'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->requiresConfirmation()
            ->visible(fn (MemberApplication $record): bool => self::isOutstandingInvite($record)
                && filled($record->applicant_email)
                && $record->inviteUrl() !== null
                && (Auth::user()?->can('members.create') ?? false))
            ->action(function (MemberApplication $record): void {
                try {
                    Mail::to((string) $record->applicant_email)
                        ->locale((new ResolveLocale)->handle())
                        ->queue(new ApplicationInviteMail(
                            (string) $record->inviteUrl(),
                            $record->invite_expires_at?->format('d/m/Y') ?? '',
                        ));
                    Notification::make()->title(__('Invitación reenviada (en cola)'))->success()->send();
                } catch (\Throwable $e) {
                    Notification::make()->title(__('No se pudo reenviar'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** Revoke an invite — its link is refused immediately (the controller checks revoked_at). */
    public static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('Anular'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (MemberApplication $record): bool => self::isOutstandingInvite($record)
                && (Auth::user()?->can('members.create') ?? false))
            ->action(function (MemberApplication $record): void {
                $record->update(['revoked_at' => now()]);

                Notification::make()->title(__('Invitación anulada'))->success()->send();
            });
    }

    /** The invite's lifecycle as a human label — Anulada / Caducada / Enviada / Abierta / Sin abrir. */
    public static function inviteLabel(MemberApplication $record): string
    {
        if ($record->isInviteRevoked()) {
            return __('Anulada');
        }
        if ($record->isInviteExpired()) {
            return __('Caducada');
        }

        return match ($record->inviteStatus()) {
            'submitted' => __('Enviada'),
            'started' => __('Abierta'),
            default => __('Sin abrir'),
        };
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
            // No `create` page (admin audit, Phase C). An application hand-made in the panel has no invite
            // token, so nobody can ever fill it in — and that page was the way to the free `status` Select.
            // Invitations are issued by the `Invitar` action on the list page, through IssueApplicationInvite.
            'view' => ViewMemberApplication::route('/{record}'),
            'edit' => EditMemberApplication::route('/{record}/edit'),
        ];
    }
}
