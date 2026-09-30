<?php

namespace App\Filament\Resources\Members\RelationManagers;

use App\Actions\Memberships\CancelMembership;
use App\Actions\Memberships\ChangeMembershipTier;
use App\Actions\Memberships\CorrectMembershipDates;
use App\Actions\Memberships\EnrolMembership;
use App\Actions\Memberships\RecordFeePayment;
use App\Actions\Memberships\RenewMembership;
use App\Actions\Memberships\TransferMembership;
use App\Enums\FeePaymentMethod;
use App\Enums\MembershipStatus;
use App\Enums\TillSessionStatus;
use App\Exceptions\DebtLimitExceededException;
use App\Filament\Forms\DecimalInput;
use App\Models\Location;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipFeePayment;
use App\Models\MembershipTier;
use App\Models\Scopes\LocationScope;
use App\Models\TillSession;
use App\Models\User;
use App\Support\FeeWaiverReasons;
use App\Support\ManagerApproval;
use App\Support\MembershipCorrections;
use App\Support\Money;
use App\Support\NumberFormat;
use Carbon\Carbon;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
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
                // Outstanding fee (prompt 46) — so a member's unpaid balance is visible at a glance,
                // not just a silent counter-side block. Same feesPaid()-style comparison.
                TextColumn::make('outstanding')
                    ->label(__('Cuota pendiente'))
                    ->badge()
                    ->state(fn (Membership $record): string => self::owedCents($record) > 0
                        ? Money::fromCents(self::owedCents($record))->formatted()
                        : __('Al día'))
                    ->color(fn (Membership $record): string => self::owedCents($record) > 0 ? 'danger' : 'success'),
            ])
            ->headerActions([
                $this->enrolAction(),
            ])
            ->recordActions([
                $this->renewAction(),
                $this->transferAction(),
                // Prompt 325 — specific, audited corrections (a membership decides who may be served and carries money), in one
                // ⋮ menu so the row keeps its width (seven inline buttons pushed the table sideways).
                ActionGroup::make([
                    $this->changeTierAction(),
                    $this->correctDatesAction(),
                    $this->collectFeeAction(),
                    $this->waiveFeeAction(),
                    $this->cancelAction(),
                ])->label(__('Corregir'))->tooltip(__('Corregir')),
            ])
            ->emptyStateHeading(__('Sin membresías'))
            ->emptyStateDescription(__('Este socio aún no tiene una membresía. Da de alta una con el botón de arriba.'));
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
                    ->options(fn (): array => Location::assignableOptions())
                    ->required(),
                Select::make('tier_id')
                    ->label(__('Tarifa'))
                    ->options(fn () => MembershipTier::query()->where('active', true)->orderBy('name')->pluck('name', 'id'))
                    ->required(),
                DecimalInput::make('fee_eur')
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

                $membership = (new EnrolMembership)->handle($member, $location, $tier, $options);

                Notification::make()->title(__('Membresía dada de alta'))->success()->send();
                self::promptFeeCollection($membership);
            });
    }

    /**
     * Point staff at the till after a membership is created/renewed with an unpaid fee. The fee
     * field on THIS form only sets what is OWED — it is not a payment; the money is collected at
     * the till (prompt 46), which is the only path that clears unpaid_fee at the counter.
     */
    /** Outstanding fee on a membership (fee owed minus payments recorded), never negative. */
    private static function owedCents(Membership $membership): int
    {
        $paid = (int) MembershipFeePayment::query()->where('membership_id', $membership->id)->sum('amount_cents');

        return max(0, $membership->fee_cents->cents - $paid);
    }

    private static function promptFeeCollection(Membership $membership): void
    {
        $owed = self::owedCents($membership);

        if ($owed <= 0) {
            return;
        }

        $tillOpen = TillSession::query()->withoutGlobalScopes()
            ->where('location_id', $membership->location_id)
            ->where('status', TillSessionStatus::OPEN->value)
            ->exists();

        $notification = Notification::make()
            ->title(__('Cuota pendiente de cobro'))
            ->body(__('Pendiente: :amount. La cuota se cobra en la sesión de caja.', ['amount' => Money::fromCents($owed)->formatted()])
                .($tillOpen ? '' : ' '.__('No hay ninguna caja abierta en esta sede — abre una primero.')))
            ->warning()
            ->persistent();

        if ($tillOpen) {
            $notification->actions([
                Action::make('goToTill')
                    ->label(__('Ir a la caja'))
                    ->url(route('counter.till'))
                    ->button(),
            ]);
        }

        $notification->send();
    }

    /** Renovar — extend the membership through the audited domain action. */
    protected function renewAction(): Action
    {
        return Action::make('renew')
            ->label(__('Renovar'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->action(function (Membership $record): void {
                $membership = (new RenewMembership)->handle($record, ['actor' => Auth::user()]);

                Notification::make()->title(__('Membresía renovada'))->success()->send();
                self::promptFeeCollection($membership);
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
                    ->options(fn (): array => Location::assignableOptions())
                    ->required(),
            ])
            ->action(function (Membership $record, array $data): void {
                $location = Location::query()->whereKey($data['location_id'])->firstOrFail();

                (new TransferMembership)->handle($record, $location);

                Notification::make()->title(__('Membresía transferida'))->success()->send();
            });
    }

    /** Show a correction only to someone who may make it, on a membership that is not cancelled. */
    private static function correctable(Membership $record, string $permission = 'membership.manage'): bool
    {
        $user = Auth::user();

        return $record->status !== MembershipStatus::CANCELLED && MembershipCorrections::may($user instanceof User ? $user : null, $record, $permission);
    }

    /** Surface a writer's refusal where the operator is looking, and stop the action. */
    private static function refuse(DomainException|AuthorizationException $e): never
    {
        Notification::make()->title($e->getMessage())->danger()->send();

        throw new Halt;
    }

    /** Prompt 325 — *Cambiar tarifa* (ChangeMembershipTier). */
    protected function changeTierAction(): Action
    {
        return Action::make('changeTier')
            ->label(__('Cambiar tarifa'))
            ->icon(Heroicon::OutlinedArrowsUpDown)
            ->visible(fn (Membership $record): bool => self::correctable($record))
            ->schema([
                Select::make('tier_id')->label(__('Nueva tarifa'))
                    ->options(fn (Membership $record) => MembershipTier::query()->where('active', true)->whereKeyNot($record->tier_id)->orderBy('name')->pluck('name', 'id'))
                    ->required(),
                DecimalInput::make('fee_eur')->label(__('Cuota (€)'))->numeric()->minValue(0)
                    ->helperText(__('Deja en blanco para usar la cuota de la nueva tarifa. Solo cambia lo que queda por cobrar.'))
                    ->visible(fn (Membership $record): bool => $record->owedCents() > 0 && (Auth::user()?->can('membership.fee.override') ?? false)),
                TextInput::make('reason')->label(__('Motivo'))->required()->maxLength(255),
            ])
            ->action(function (Membership $record, array $data): void {
                /** @var User $actor */
                $actor = Auth::user();
                $tier = MembershipTier::query()->whereKey($data['tier_id'])->firstOrFail();
                $fee = filled($data['fee_eur'] ?? null) ? (int) round_half_up(((float) $data['fee_eur']) * 100) : null;
                try {
                    $outcome = (new ChangeMembershipTier)->handle($record, $tier, $actor, (string) $data['reason'], $fee);
                } catch (DomainException|AuthorizationException $e) {
                    self::refuse($e);
                }

                if ($outcome['fee_kept']) {
                    Notification::make()->title(__('La cuota ya está pagada; cobra o devuelve la diferencia desde la caja si corresponde.'))
                        ->body(__('Diferencia con la nueva tarifa: :amount.', ['amount' => Money::fromCents($outcome['difference_cents'])->formatted()]))
                        ->warning()->persistent()->send();
                } else {
                    Notification::make()->title(__('Tarifa cambiada'))->success()->send();
                }
            });
    }

    /** Prompt 325 — *Corregir fechas* (CorrectMembershipDates; the sweep's own status rule). */
    protected function correctDatesAction(): Action
    {
        return Action::make('correctDates')
            ->label(__('Corregir fechas'))
            ->icon(Heroicon::OutlinedCalendarDays)
            ->visible(fn (Membership $record): bool => self::correctable($record))
            ->fillForm(fn (Membership $record): array => ['starts_at' => $record->starts_at?->toDateString(), 'expires_at' => $record->expires_at?->toDateString()])
            ->schema([
                DatePicker::make('starts_at')->label(__('Inicio'))->required()->live(),
                DatePicker::make('expires_at')->label(__('Caduca'))->required()->live()->after('starts_at')
                    ->validationMessages(['after' => __('La caducidad debe ser posterior al inicio.')]),
                // Said before saving, not after: these dates would leave the socio unable to be served today.
                Text::make(__('Con estas fechas la membresía no estará activa hoy.'))
                    ->color('warning')
                    ->visible(fn (Get $get): bool => filled($get('starts_at')) && filled($get('expires_at'))
                        && Carbon::parse((string) $get('expires_at'))->gt(Carbon::parse((string) $get('starts_at')))
                        && ! CorrectMembershipDates::activeToday(Carbon::parse((string) $get('starts_at'))->startOfDay(), Carbon::parse((string) $get('expires_at'))->endOfDay())),
                TextInput::make('reason')->label(__('Motivo'))->required()->maxLength(255),
            ])
            ->action(function (Membership $record, array $data): void {
                /** @var User $actor */
                $actor = Auth::user();
                try {
                    (new CorrectMembershipDates)->handle($record, Carbon::parse((string) $data['starts_at'])->startOfDay(),
                        Carbon::parse((string) $data['expires_at'])->endOfDay(), $actor, (string) $data['reason']);
                } catch (DomainException|AuthorizationException $e) {
                    self::refuse($e);
                }
                Notification::make()->title(__('Fechas corregidas'))->success()->send();
            });
    }

    /** Prompt 325 — *Cobrar cuota*: the counter's own writer (RecordFeePayment); cash only into the sede's open till. */
    protected function collectFeeAction(): Action
    {
        return Action::make('collectFee')
            ->label(__('Cobrar cuota'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn (Membership $record): bool => $record->owedCents() > 0 && self::correctable($record, 'membership.fee.collect'))
            ->fillForm(fn (Membership $record): array => ['amount' => NumberFormat::decimal($record->owedCents() / 100, 2), 'method' => FeePaymentMethod::CASH->value])
            ->schema([
                DecimalInput::make('amount')->label(__('Importe (€)'))->numeric()->minValue(0.01)->required(),
                Select::make('method')->label(__('Forma de pago'))->required()
                    ->options([FeePaymentMethod::CASH->value => FeePaymentMethod::CASH->label(), FeePaymentMethod::WALLET->value => FeePaymentMethod::WALLET->label()]),
            ])
            ->action(function (Membership $record, array $data): void {
                $cents = Money::parseTyped((string) $data['amount']);
                $owed = $record->owedCents();
                if ($cents === null || $cents <= 0 || $cents > $owed) {
                    self::refuse(new DomainException(__('El importe supera la cuota pendiente (:owed).', ['owed' => Money::fromCents($owed)->formatted()])));
                }
                $method = FeePaymentMethod::from((string) $data['method']);
                $session = TillSession::query()->withoutGlobalScopes()->where('location_id', $record->location_id)
                    ->where('status', TillSessionStatus::OPEN->value)->oldest('opened_at')->first();

                // Cash lands in the sede's open drawer, or the arqueo is wrong at close — exactly the counter's rule.
                if ($method === FeePaymentMethod::CASH && $session === null) {
                    Notification::make()->warning()->persistent()
                        ->title(__('Abre la caja de :sede para cobrar en efectivo, o cobra desde el monedero.', ['sede' => (string) $record->location?->name]))
                        ->actions([Action::make('goToTill')->label(__('Ir a la caja'))->url(route('counter.till'))->button()])
                        ->send();

                    throw new Halt;
                }

                try {
                    (new RecordFeePayment)->handle($record, $cents, $method, ['till_session_id' => $session?->id, 'operator_id' => Auth::id()]);
                } catch (DebtLimitExceededException $e) {
                    self::refuse(new DomainException(__('El monedero no admite el cargo: :reason', ['reason' => $e->getMessage()])));
                }
                Notification::make()->title(__('Cuota cobrada: :amount', ['amount' => Money::fromCents($cents)->formatted()]))->success()->send();
            });
    }

    /** Prompt 325 — *Condonar cuota*: the counter's waiver (RecordFeePayment, WAIVED), with the same reasons. */
    protected function waiveFeeAction(): Action
    {
        return Action::make('waiveFee')
            ->label(__('Condonar cuota'))
            ->icon(Heroicon::OutlinedGift)
            ->visible(fn (Membership $record): bool => $record->owedCents() > 0 && self::correctable($record, 'membership.fee.waive'))
            ->schema([
                Select::make('waive_reason')->label(__('Motivo'))->required()->live()
                    ->options(fn (Membership $record): array => collect(FeeWaiverReasons::options($record->member, $record->location, ManagerApproval::allows(Auth::user())))->pluck('label', 'value')->all())
                    ->default(fn (): ?string => ManagerApproval::allows(Auth::user()) ? 'MANAGER_APPROVED' : null), // prompt 333
                TextInput::make('waive_reason_text')->label(__('Explica el motivo'))->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('waive_reason') === 'OTHER')
                    ->required(fn (Get $get): bool => $get('waive_reason') === 'OTHER'),
            ])
            ->action(function (Membership $record, array $data): void {
                $reason = FeeWaiverReasons::resolve(FeeWaiverReasons::options($record->member, $record->location, ManagerApproval::allows(Auth::user())), (string) $data['waive_reason'], (string) ($data['waive_reason_text'] ?? ''));
                if ($reason === null) {
                    self::refuse(new DomainException(__('Indica el motivo de la condonación.')));
                }
                (new RecordFeePayment)->handle($record, $record->owedCents(), FeePaymentMethod::WAIVED, ['operator_id' => Auth::id(), 'reason' => $reason]);
                Notification::make()->title(__('Cuota condonada'))->success()->send();
            });
    }

    /** Prompt 325 — *Anular* one membership entered by mistake (Memberships\CancelMembership). */
    protected function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label(__('Anular'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Membership $record): bool => self::correctable($record))
            ->modalDescription(__('Para una membresía creada por error (duplicada, sede equivocada). La fila se conserva como anulada y el socio deja de poder ser atendido en esa sede.'))
            ->schema([
                TextInput::make('reason')->label(__('Motivo'))->required()->maxLength(255),
                // No fee refund yet (Ben, 325): a paid fee is kept, and saying so is required.
                Checkbox::make('keep_paid_fee')
                    ->label(fn (Membership $record): string => __('Mantener la cuota pagada (:amount). No se devuelve dinero.', ['amount' => Money::fromCents(CancelMembership::paidCents($record))->formatted()]))
                    ->visible(fn (Membership $record): bool => CancelMembership::paidCents($record) > 0)
                    ->accepted(fn (Membership $record): bool => CancelMembership::paidCents($record) > 0),
                Checkbox::make('confirm_recent_dispensations')
                    ->label(fn (Membership $record): string => trans_choice('Hay :count dispensación a este socio en esta sede en los últimos 30 días. Anular de todos modos.|Hay :count dispensaciones a este socio en esta sede en los últimos 30 días. Anular de todos modos.', CancelMembership::recentDispensations($record), ['count' => CancelMembership::recentDispensations($record)]))
                    ->visible(fn (Membership $record): bool => CancelMembership::recentDispensations($record) > 0)
                    ->accepted(fn (Membership $record): bool => CancelMembership::recentDispensations($record) > 0),
            ])
            ->action(function (Membership $record, array $data): void {
                /** @var User $actor */
                $actor = Auth::user();
                try {
                    (new CancelMembership)->handle($record, $actor, (string) $data['reason'], (bool) ($data['keep_paid_fee'] ?? false), (bool) ($data['confirm_recent_dispensations'] ?? false));
                } catch (DomainException|AuthorizationException $e) {
                    self::refuse($e);
                }
                Notification::make()->title(__('Membresía anulada'))->success()->send();
            });
    }
}
