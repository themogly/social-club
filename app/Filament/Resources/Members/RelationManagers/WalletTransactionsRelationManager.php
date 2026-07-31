<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Actions\Wallet\RecordWalletTransaction;
use App\Enums\WalletTransactionType;
use App\Exceptions\DebtLimitExceededException;
use App\Models\Location;
use App\Models\Member;
use App\Models\Scopes\LocationScope;
use App\Models\WalletTransaction;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class WalletTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'walletTransactions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Cartera');
    }

    public function table(Table $table): Table
    {
        return $table
            // The wallet ledger is append-only: no create/edit/delete row actions.
            // A socio is org-wide, so surface every location's movements.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScope(LocationScope::class))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('Fecha'))->dateTime()->sortable(),
                TextColumn::make('type')->label(__('Tipo'))->badge(),
                TextColumn::make('amount_cents')
                    ->label(__('Importe'))
                    ->state(fn (WalletTransaction $record): int => $record->amount_cents->cents)
                    ->money('EUR', divideBy: 100),
                TextColumn::make('balance_after_cents')
                    ->label(__('Saldo'))
                    ->state(fn (WalletTransaction $record): int => $record->balance_after_cents->cents)
                    ->money('EUR', divideBy: 100),
                TextColumn::make('reason')->label(__('Motivo'))->wrap(),
            ])
            ->headerActions([
                $this->topUpAction(),
                $this->refundAction(),
                $this->adjustAction(),
            ])
            ->emptyStateHeading(__('Sin movimientos'))
            ->emptyStateDescription(__('El monedero del socio no tiene movimientos. Registra un ingreso con el botón de arriba.'));
    }

    /** Ingreso — add credit to the member's wallet at a location (positive movement). */
    protected function topUpAction(): Action
    {
        return Action::make('topup')
            ->label(__('Ingreso'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->schema([
                $this->locationField(),
                TextInput::make('amount_eur')
                    ->label(__('Importe (€)'))
                    ->numeric()
                    ->minValue(0.01)
                    ->required(),
            ])
            ->action(function (array $data): void {
                /** @var Member $member */
                $member = $this->getOwnerRecord();
                $location = Location::query()->whereKey($data['location_id'])->firstOrFail();
                $cents = (int) round_half_up(((float) $data['amount_eur']) * 100);

                (new RecordWalletTransaction)->handle($member, $location, $cents, WalletTransactionType::TOPUP, [
                    'operator_id' => $this->operatorId(),
                ]);

                Notification::make()->title(__('Ingreso registrado'))->success()->send();
            });
    }

    /** Reembolso — return credit (negative movement); may hit the debt limit. */
    protected function refundAction(): Action
    {
        return Action::make('refund')
            ->label(__('Reembolso'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->schema([
                $this->locationField(),
                TextInput::make('amount_eur')
                    ->label(__('Importe (€)'))
                    ->numeric()
                    ->minValue(0.01)
                    ->required(),
            ])
            ->action(function (array $data): void {
                /** @var Member $member */
                $member = $this->getOwnerRecord();
                $location = Location::query()->whereKey($data['location_id'])->firstOrFail();
                $cents = (int) round_half_up(((float) $data['amount_eur']) * 100);

                try {
                    (new RecordWalletTransaction)->handle($member, $location, -$cents, WalletTransactionType::REFUND, [
                        'operator_id' => $this->operatorId(),
                    ]);

                    Notification::make()->title(__('Reembolso registrado'))->success()->send();
                } catch (DebtLimitExceededException $e) {
                    Notification::make()->title(__('No se pudo registrar el reembolso'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** Ajuste — signed manual correction, gated on wallet.adjust; may hit the debt limit. */
    protected function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label(__('Ajuste'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('danger')
            ->visible(fn (): bool => Auth::user()?->can('wallet.adjust') ?? false)
            ->requiresConfirmation()   // a signed correction can subtract a member's balance — confirm first
            ->schema([
                $this->locationField(),
                TextInput::make('amount_eur')
                    ->label(__('Importe (€)'))
                    ->numeric()
                    ->required()
                    ->helperText(__('Positivo suma saldo; negativo lo resta.')),
                Textarea::make('reason')
                    ->label(__('Motivo'))
                    ->required(),
            ])
            ->action(function (array $data): void {
                /** @var Member $member */
                $member = $this->getOwnerRecord();
                $location = Location::query()->whereKey($data['location_id'])->firstOrFail();
                $cents = (int) round_half_up(((float) $data['amount_eur']) * 100);

                try {
                    (new RecordWalletTransaction)->handle($member, $location, $cents, WalletTransactionType::ADJUSTMENT, [
                        'operator_id' => $this->operatorId(),
                        'reason' => (string) $data['reason'],
                    ]);

                    Notification::make()->title(__('Ajuste registrado'))->success()->send();
                } catch (DebtLimitExceededException $e) {
                    Notification::make()->title(__('No se pudo registrar el ajuste'))->body($e->getMessage())->danger()->send();
                }
            });
    }

    protected function locationField(): Select
    {
        return Select::make('location_id')
            ->label(__('Sede'))
            ->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id'))
            ->required();
    }

    protected function operatorId(): ?string
    {
        $id = Auth::id();

        return $id === null ? null : (string) $id;
    }
}
