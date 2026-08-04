<?php

namespace App\Filament\Resources\MemberApplications\Pages;

use App\Actions\Members\IssueApplicationInvite;
use App\Actions\ResolveLocale;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use App\Mail\ApplicationInviteMail;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Throwable;

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
     * Generate a tokenised invite link (prompt 04). Two explicit paths (prompt 149): EMAIL the invitation, or
     * generate a LINK to hand over — every invitation attributable, and mail never able to fail the action.
     * Creating the invitation and sending its email are separate: the row is created atomically, the mail is
     * QUEUED best-effort, and the link is shown UNCONDITIONALLY (persistent) whatever happens to the mail.
     */
    protected function inviteAction(): Action
    {
        return Action::make('invite')
            ->label(__('Generar invitación'))
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn (): bool => Auth::user()?->can('members.create') ?? false)
            ->schema([
                Radio::make('invite_mode')
                    ->label(__('Cómo enviar la invitación'))
                    ->options([
                        'email' => __('Enviar por email'),
                        'handover' => __('Generar enlace para entregar en mano'),
                    ])
                    ->default('email')
                    ->required()
                    ->live(),
                Select::make('location_id')
                    ->label(__('Sede'))
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder(__('Sin sede asignada')),
                TextInput::make('applicant_email')
                    ->label(__('Correo del solicitante'))
                    ->email()
                    ->required(fn (Get $get): bool => $get('invite_mode') === 'email')
                    ->visible(fn (Get $get): bool => $get('invite_mode') === 'email'),
                TextInput::make('applicant_reference')
                    ->label(__('¿Para quién es la invitación?'))
                    ->maxLength(255)
                    ->required(fn (Get $get): bool => $get('invite_mode') === 'handover')
                    ->visible(fn (Get $get): bool => $get('invite_mode') === 'handover')
                    ->helperText(__('Un nombre o referencia (p. ej. el avalador), para saber a quién se entregó el enlace.')),
            ])
            ->action(function (array $data): void {
                /** @var User $actor */
                $actor = Auth::user();
                $mode = $data['invite_mode'] ?? 'email';
                $email = $mode === 'email' ? ($data['applicant_email'] ?? null) : null;
                $reference = $mode === 'handover' ? ($data['applicant_reference'] ?? null) : null;

                try {
                    $application = (new IssueApplicationInvite)->handle($actor, $data['location_id'] ?? null, $email, $reference);
                } catch (Throwable $e) {
                    Notification::make()->title(__('No se pudo generar la invitación'))->body($e->getMessage())->danger()->send();

                    return;
                }

                // QUEUED, best-effort: a delivery problem belongs in Horizon's failed jobs, never on this
                // screen, and must never decide whether the invitation exists or its link is shown.
                $mailFailed = false;
                if (filled($application->applicant_email)) {
                    try {
                        Mail::to((string) $application->applicant_email)
                            ->locale((new ResolveLocale)->handle())
                            ->queue(new ApplicationInviteMail(
                                (string) $application->inviteUrl(),
                                $application->invite_expires_at?->format('d/m/Y') ?? '',
                            ));
                    } catch (Throwable) {
                        $mailFailed = true;
                    }
                }

                // The link IS the deliverable — always shown, persistent so a dismissed toast never loses it.
                Notification::make()
                    ->title(filled($application->applicant_email)
                        ? ($mailFailed ? __('Invitación creada — no se pudo encolar el email') : __('Invitación creada y email en cola'))
                        : __('Enlace de invitación generado'))
                    ->body((string) $application->inviteUrl())
                    ->success()
                    ->persistent()
                    ->send();
            });
    }
}
