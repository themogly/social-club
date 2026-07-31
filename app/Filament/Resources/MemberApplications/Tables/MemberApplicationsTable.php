<?php

namespace App\Filament\Resources\MemberApplications\Tables;

use App\Enums\ApplicationStatus;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use App\Mail\ApplicationInviteMail;
use App\Models\MemberApplication;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

class MemberApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('applicant')
                    ->label(__('Solicitante'))
                    ->state(fn (MemberApplication $record): string => self::applicantName($record)),
                TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->color(fn (ApplicationStatus $state): string => match ($state) {
                        ApplicationStatus::APPROVED => 'success',
                        ApplicationStatus::PENDING => 'warning',
                        ApplicationStatus::REJECTED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('invite')
                    ->label(__('Invitación'))
                    ->badge()
                    ->state(fn (MemberApplication $record): string => self::inviteLabel($record))
                    ->color(fn (MemberApplication $record): string => match (true) {
                        $record->isInviteRevoked() || $record->isInviteExpired() => 'danger',
                        $record->submitted_at !== null => 'success',
                        $record->opened_at !== null => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('invitedBy.name')->label(__('Invitado por'))->placeholder('—')->toggleable(),
                TextColumn::make('invite_expires_at')->label(__('Caduca'))->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('location.name')->label(__('Sede'))->toggleable(),
                TextColumn::make('created_at')->label(__('Creada'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Estado'))
                    ->options(collect(ApplicationStatus::cases())
                        ->mapWithKeys(fn (ApplicationStatus $case): array => [$case->value => $case->label()])
                        ->all()),
                SelectFilter::make('lifecycle')
                    ->label(__('Invitación'))
                    ->options([
                        'open' => __('Invitaciones abiertas'),
                        'submitted' => __('Solicitudes enviadas'),
                    ])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'open' => $query->whereNull('submitted_at'),
                        'submitted' => $query->whereNotNull('submitted_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                self::copyLinkAction(),
                self::resendAction(),
                self::revokeAction(),
                ViewAction::make(),
                ...MemberApplicationResource::recordActions(),
                EditAction::make(),
            ]);
        // No bulk delete: MemberApplicationPolicy grants no `delete` (applications are the
        // invite/review record, not disposable) — the action would have been inert (prompt 37).
    }

    /** Re-display the shareable link (the fix: recoverable after the toast is gone). */
    private static function copyLinkAction(): Action
    {
        return Action::make('copyLink')
            ->label(__('Copiar enlace'))
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn (MemberApplication $record): bool => $record->isInviteLive() && $record->inviteUrl() !== null)
            ->action(function (MemberApplication $record, Component $livewire): void {
                // Prompt 45: ACTUALLY copy to the clipboard (it used to just re-show the URL in a toast).
                // $livewire->js runs client-side within the click's transient activation.
                $livewire->js('navigator.clipboard.writeText('.json_encode((string) $record->inviteUrl()).')');

                Notification::make()->title(__('Enlace copiado'))->success()->send();
            });
    }

    /** Reenviar invitación — re-email the SAME token to the applicant's email (prompt 45). */
    private static function resendAction(): Action
    {
        return Action::make('resend')
            ->label(__('Reenviar'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->requiresConfirmation()
            ->visible(fn (MemberApplication $record): bool => $record->isInviteLive()
                && filled($record->applicant_email)
                && $record->inviteUrl() !== null
                && (Auth::user()?->can('members.create') ?? false))
            ->action(function (MemberApplication $record): void {
                Mail::to((string) $record->applicant_email)->send(new ApplicationInviteMail(
                    (string) $record->inviteUrl(),
                    $record->invite_expires_at?->format('d/m/Y') ?? '',
                ));

                Notification::make()->title(__('Invitación reenviada'))->success()->send();
            });
    }

    /** Revoke an invite — its link is refused immediately (the controller checks revoked_at). */
    private static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('Anular'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (MemberApplication $record): bool => $record->isInviteLive()
                && (Auth::user()?->can('members.create') ?? false))
            ->action(function (MemberApplication $record): void {
                $record->update(['revoked_at' => now()]);

                Notification::make()->title(__('Invitación anulada'))->success()->send();
            });
    }

    private static function applicantName(MemberApplication $record): string
    {
        $first = data_get($record->payload, 'first_name');
        $last = data_get($record->payload, 'last_name');

        return $first !== null ? trim($first.' '.$last) : '—';
    }

    private static function inviteLabel(MemberApplication $record): string
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
}
