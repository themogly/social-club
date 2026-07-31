<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Actions\Memberships\EnrolMembership;
use App\Actions\Memberships\RenewMembership;
use App\Actions\Memberships\TransferMembership;
use App\Enums\MembershipStatus;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Scopes\LocationScope;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class MembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Membresías');
    }

    public function table(Table $table): Table
    {
        return $table
            // A socio is org-wide; their memberships span locations. Show them all
            // regardless of the active location (the documented cross-location opt-in).
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScope(LocationScope::class))
            ->columns([
                TextColumn::make('tier.name')->label(__('Tarifa'))->searchable()->sortable(),
                TextColumn::make('location.name')->label(__('Sede'))->sortable(),
                TextColumn::make('status')
                    ->label(__('Estado'))
                    ->badge()
                    ->color(fn (MembershipStatus $state): string => match ($state) {
                        MembershipStatus::ACTIVE => 'success',
                        MembershipStatus::EXPIRING_SOON => 'warning',
                        MembershipStatus::LAPSED, MembershipStatus::CANCELLED => 'danger',
                    }),
                TextColumn::make('starts_at')->label(__('Inicio'))->date()->sortable(),
                TextColumn::make('expires_at')->label(__('Caduca'))->date()->sortable(),
            ])
            ->headerActions([
                $this->enrolAction(),
            ])
            ->recordActions([
                $this->renewAction(),
                $this->transferAction(),
            ]);
    }

    /** Alta de membresía — enrol the socio at a location on a tier (optional fee override). */
    protected function enrolAction(): Action
    {
        return Action::make('enrol')
            ->label(__('Alta de membresía'))
            ->icon(Heroicon::OutlinedPlus)
            ->schema([
                Select::make('location_id')
                    ->label(__('Sede'))
                    ->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id'))
                    ->required(),
                Select::make('tier_id')
                    ->label(__('Tarifa'))
                    ->options(fn () => MembershipTier::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))
                    ->required(),
                TextInput::make('fee_eur')
                    ->label(__('Cuota (€)'))
                    ->numeric()
                    ->minValue(0)
                    ->helperText(__('Deja en blanco para usar la cuota de la tarifa.')),
                TextInput::make('fee_override_reason')
                    ->label(__('Motivo del cambio de cuota'))
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                /** @var Member $member */
                $member = $this->getOwnerRecord();
                $location = Location::query()->whereKey($data['location_id'])->firstOrFail();
                $tier = MembershipTier::query()->whereKey($data['tier_id'])->firstOrFail();
                $reason = $data['fee_override_reason'] ?? null;

                $options = [
                    'actor' => Auth::user(),
                    'fee_override_reason' => is_string($reason) ? $reason : null,
                ];

                if (filled($data['fee_eur'] ?? null)) {
                    $options['fee_cents'] = (int) round_half_up(((float) $data['fee_eur']) * 100);
                }

                (new EnrolMembership)->handle($member, $location, $tier, $options);

                Notification::make()->title(__('Membresía dada de alta'))->success()->send();
            });
    }

    /** Renovar — extend the membership through the audited domain action. */
    protected function renewAction(): Action
    {
        return Action::make('renew')
            ->label(__('Renovar'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->action(function (Membership $record): void {
                (new RenewMembership)->handle($record, ['actor' => Auth::user()]);

                Notification::make()->title(__('Membresía renovada'))->success()->send();
            });
    }

    /** Transferir — move the membership to another location (recorded settlement). */
    protected function transferAction(): Action
    {
        return Action::make('transfer')
            ->label(__('Transferir'))
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->schema([
                Select::make('location_id')
                    ->label(__('Sede destino'))
                    ->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id'))
                    ->required(),
            ])
            ->action(function (Membership $record, array $data): void {
                $location = Location::query()->whereKey($data['location_id'])->firstOrFail();

                (new TransferMembership)->handle($record, $location);

                Notification::make()->title(__('Membresía transferida'))->success()->send();
            });
    }
}
