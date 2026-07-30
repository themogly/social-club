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
use App\Models\MemberApplication;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
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
     * Review-queue decisions. Only a PENDING application may be decided, and only
     * by a reviewer (`applications.review`). Approval routes through the audited
     * domain action, which re-runs the age gate server-side.
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

    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('Aprobar'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (MemberApplication $record): bool => $record->status === ApplicationStatus::PENDING
                && (Auth::user()?->can('applications.review') ?? false))
            ->action(function (MemberApplication $record): void {
                try {
                    (new ApproveApplication)->handle($record);

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
            ->visible(fn (MemberApplication $record): bool => $record->status === ApplicationStatus::PENDING
                && (Auth::user()?->can('applications.review') ?? false))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Motivo del rechazo'))
                    ->required(),
            ])
            ->action(function (MemberApplication $record, array $data): void {
                $record->update([
                    'status' => ApplicationStatus::REJECTED,
                    'reject_reason' => $data['reason'] ?? null,
                    'reviewed_by' => Auth::id(),
                    'reviewed_at' => now(),
                ]);

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
            ->visible(fn (MemberApplication $record): bool => $record->status === ApplicationStatus::PENDING
                && (Auth::user()?->can('applications.review') ?? false))
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
