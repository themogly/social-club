<?php

namespace App\Filament\Resources\MemberApplications\Tables;

use App\Enums\ApplicationStatus;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use App\Models\MemberApplication;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
                    ->state(fn (MemberApplication $record): string => MemberApplicationResource::inviteLabel($record))
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
                // Invitation lifecycle actions shared with the View page (prompt 154) — copy link / resend / revoke.
                ...MemberApplicationResource::inviteActions(),
                ViewAction::make(),
                ...MemberApplicationResource::recordActions(),
                EditAction::make(),
            ]);
        // No bulk delete: MemberApplicationPolicy grants no `delete` (applications are the
        // invite/review record, not disposable) — the action would have been inert (prompt 37).
    }

    private static function applicantName(MemberApplication $record): string
    {
        $first = data_get($record->payload, 'first_name');
        $last = data_get($record->payload, 'last_name');

        return $first !== null ? trim($first.' '.$last) : '—';
    }
}
