<?php

namespace App\Filament\Resources\MemberApplications\Pages;

use App\Enums\ApplicationStatus;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use App\Models\MemberApplication;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ListMemberApplications extends ListRecords
{
    protected static string $resource = MemberApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->inviteAction(),
            CreateAction::make(),
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
            ])
            ->action(function (array $data): void {
                $token = Str::random(48);

                MemberApplication::create([
                    'location_id' => $data['location_id'] ?? null,
                    'invite_token_hash' => hash('sha256', $token),
                    'payload' => [],
                    'status' => ApplicationStatus::PENDING,
                ]);

                $url = route('socio.application', ['token' => $token]);

                Notification::make()
                    ->title(__('Enlace de invitación generado'))
                    ->body($url)
                    ->success()
                    ->persistent()
                    ->send();
            });
    }
}
