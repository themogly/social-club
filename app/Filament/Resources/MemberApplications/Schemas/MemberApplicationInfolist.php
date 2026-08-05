<?php

namespace App\Filament\Resources\MemberApplications\Schemas;

use App\Enums\ApplicationStatus;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use App\Models\MemberApplication;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MemberApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Solicitud'))
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('Estado'))
                            ->badge()
                            ->color(fn (ApplicationStatus $state): string => match ($state) {
                                ApplicationStatus::APPROVED => 'success',
                                ApplicationStatus::PENDING => 'warning',
                                ApplicationStatus::REJECTED => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('location.name')->label(__('Sede'))->placeholder('—'),
                        TextEntry::make('reviewer.name')->label(__('Revisada por'))->placeholder('—'),
                        TextEntry::make('reviewed_at')->label(__('Fecha de revisión'))->dateTime()->placeholder('—'),
                        TextEntry::make('reject_reason')->label(__('Motivo del rechazo'))->placeholder('—'),
                    ])
                    ->columns(2),

                // Prompt 154: an outstanding invitation (nobody has submitted) reads as its own state — "waiting
                // for the applicant" — not as an empty "Formulario" panel that looks like a table that failed to
                // load. Sent when / opened or not / expiring when are three different operator actions: chase,
                // wait, or the link died.
                Section::make(__('Invitación'))
                    ->description(__('Esta persona aún no ha completado sus datos: es una invitación pendiente, no una solicitud para revisar.'))
                    ->visible(fn (MemberApplication $record): bool => $record->submitted_at === null)
                    ->schema([
                        TextEntry::make('invite_state')
                            ->label(__('Estado de la invitación'))
                            ->badge()
                            ->state(fn (MemberApplication $record): string => MemberApplicationResource::inviteLabel($record))
                            ->color(fn (MemberApplication $record): string => match (true) {
                                $record->isInviteRevoked() || $record->isInviteExpired() => 'danger',
                                $record->opened_at !== null => 'info',
                                default => 'gray',
                            }),
                        TextEntry::make('created_at')->label(__('Enviada'))->dateTime(),
                        TextEntry::make('opened_at')->label(__('Abierta por el solicitante'))->dateTime()->placeholder(__('Todavía no')),
                        TextEntry::make('invite_expires_at')->label(__('Caduca'))->dateTime()->placeholder('—'),
                        // The expired case is its own dead end (prompt 154): no copyable dead link is offered (the
                        // invite actions gate on isInviteLive), and the operator is told the path back — a fresh
                        // invitation from the list — rather than an "extend expiry" capability the invite never had.
                        TextEntry::make('expired_note')
                            ->hiddenLabel()
                            ->visible(fn (MemberApplication $record): bool => $record->isInviteExpired() && ! $record->isInviteRevoked())
                            ->state(__('El enlace ha caducado y ya no puede usarse. Genera una nueva invitación desde la lista para volver a invitar a esta persona.'))
                            ->color('danger')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                // A SUBMITTED application shows its payload exactly as before (prompt 154 rule).
                Section::make(__('Datos de la solicitud'))
                    ->visible(fn (MemberApplication $record): bool => $record->submitted_at !== null)
                    ->schema([
                        TextEntry::make('submitted_at')->label(__('Enviada la solicitud'))->dateTime(),
                        KeyValueEntry::make('payload')
                            ->label(__('Formulario'))
                            ->keyLabel(__('Campo'))
                            ->valueLabel(__('Valor')),
                    ]),
            ]);
    }
}
