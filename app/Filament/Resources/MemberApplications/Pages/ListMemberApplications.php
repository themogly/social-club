<?php

namespace App\Filament\Resources\MemberApplications\Pages;

use App\Enums\ApplicationStatus;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use App\Mail\ApplicationInviteMail;
use App\Models\MemberApplication;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ListMemberApplications extends ListRecords
{
    protected static string $resource = MemberApplicationResource::class;

    protected function getHeaderActions(): array
    {
        // No blank "New application" create: a real application needs the applicant's
        // compliance fields (DOB, document, consents). Prospects get a tokenised invite
        // (below); staff-entered walk-ins use the Member direct-create flow (prompt 20),
        // which already captures those fields. (Prompt 29 decision — see DECISIONS.md.)
        return [
            $this->inviteAction(),
        ];
    }

    /**
     * Generate a tokenised invite link (prompt 04). The prospect opens it on their own
     * phone and completes the pre-registration form (the ONE public member route). Only
     * the token HASH is stored; the raw link is shown once here to copy and share.
     */
    protected function inviteAction(): Action
    {
        return Action::make('invite')
            ->label(__('Generar invitación'))
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn (): bool => Auth::user()?->can('members.create') ?? false)
            ->schema([
                Select::make('location_id')
                    ->label(__('Sede'))
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder(__('Sin sede asignada')),
                TextInput::make('applicant_email')
                    ->label(__('Correo del solicitante (opcional)'))
                    ->email()
                    ->helperText(__('Si lo indicas, la invitación se envía por email; si no, cópiala y compártela.')),
            ])
            ->action(function (array $data): void {
                $token = Str::random(48);
                $days = (int) Settings::get('invite_expiry_days', 14);
                $email = $data['applicant_email'] ?? null;

                $application = MemberApplication::create([
                    'location_id' => $data['location_id'] ?? null,
                    'invite_token_hash' => hash('sha256', $token),
                    'invite_token' => $token,                 // encrypted at rest → re-copyable / re-sendable
                    'invited_by' => Auth::id(),
                    'applicant_email' => $email ?: null,
                    'invite_expires_at' => now()->addDays($days),
                    'payload' => [],
                    'status' => ApplicationStatus::PENDING,
                ]);

                if (filled($email)) {
                    Mail::to($email)->send(new ApplicationInviteMail(
                        (string) $application->inviteUrl(),
                        $application->invite_expires_at?->format('d/m/Y') ?? '',
                    ));
                }

                // Persistent panel so a dismissed toast never loses the link — and it is now
                // listed with a Copy action, so it is recoverable even after navigating away.
                Notification::make()
                    ->title(filled($email) ? __('Invitación enviada por email') : __('Enlace de invitación generado'))
                    ->body($application->inviteUrl())
                    ->success()
                    ->persistent()
                    ->send();
            });
    }
}
