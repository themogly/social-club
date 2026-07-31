<?php

namespace App\Filament\Resources\Dispensations;

use App\Actions\Dispensing\RefundDispensation;
use App\Enums\DispensationStatus;
use App\Enums\RefundDestination;
use App\Enums\RefundMethod;
use App\Enums\TillSessionStatus;
use App\Filament\Resources\Dispensations\Pages\ListDispensations;
use App\Filament\Resources\Dispensations\Pages\ViewDispensation;
use App\Filament\Resources\Dispensations\Schemas\DispensationInfolist;
use App\Filament\Resources\Dispensations\Tables\DispensationsTable;
use App\Models\Dispensation;
use App\Models\TillSession;
use App\Models\User;
use App\Support\Money;
use App\Support\Weight;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Oversight of dispensations (contribution register) — read-only in the panel, like OrderResource and
 * TillSessionResource: withdrawals are recorded at the counter (App\Livewire\Counter\DispensaryPos), so
 * there is no create/edit here. This is also the home of the REFUND surface (prompt 71): the refund
 * engine (RefundDispensation) shipped complete in prompt 65 with no way to trigger it; this adds the
 * lookup + modal that calls it and nothing else. Authorisation flows through DispensationPolicy.
 */
class DispensationResource extends Resource
{
    protected static ?string $model = Dispensation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 15;

    public static function getNavigationLabel(): string
    {
        return __('Dispensaciones');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Caja');
    }

    public static function getModelLabel(): string
    {
        return __('dispensación');
    }

    public static function getPluralModelLabel(): string
    {
        return __('dispensaciones');
    }

    /** Withdrawals are recorded at the counter — never created from the panel. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return DispensationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DispensationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDispensations::route('/'),
            'view' => ViewDispensation::route('/{record}'),
        ];
    }

    /**
     * The refund surface — the single caller of RefundDispensation. It COLLECTS input (amount, weight, an
     * explicit stock destination, a tender, a reason) and hands it to the action, which owns the over-refund
     * check, the window, the stock branch and the audit. No validation is duplicated here: the action
     * refuses and its reason is surfaced. The merma destination is offered only with `stock.merma`; a cash
     * refund only when an open till exists at the row's location (a cash refund needs a drawer to come out of).
     */
    public static function refundAction(): Action
    {
        return Action::make('refund')
            ->label(__('Reembolsar'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (Dispensation $record): bool => $record->status === DispensationStatus::COMPLETED
                && (Auth::user()?->can('refund', $record) ?? false)
                && $record->remainingRefundableCents() > 0)
            ->modalHeading(__('Reembolsar dispensación'))
            ->modalDescription(fn (Dispensation $record): string => __('Disponible para reembolsar: :money · :weight', [
                'money' => Money::fromCents($record->remainingRefundableCents())->formatted(),
                'weight' => Weight::fromCentigrams($record->remainingRefundableGramsCg())->formatted(),
            ]))
            ->schema([
                TextInput::make('amount_eur')
                    ->label(__('Importe a reembolsar (€)'))
                    ->numeric()->minValue(0)->step(0.01)->required()
                    ->helperText(fn (Dispensation $record): string => __('Máximo :money', [
                        'money' => Money::fromCents($record->remainingRefundableCents())->formatted(),
                    ])),
                TextInput::make('weight_g')
                    ->label(__('Peso a devolver (g)'))
                    ->numeric()->minValue(0)->step(0.01)->default('0')->required()
                    ->helperText(fn (Dispensation $record): string => __('Máximo :weight', [
                        'weight' => Weight::fromCentigrams($record->remainingRefundableGramsCg())->formatted(),
                    ])),
                Select::make('destination')
                    ->label(__('Destino del producto'))
                    ->options(self::destinationOptions())
                    ->required()
                    ->visible(fn (Get $get): bool => (float) ($get('weight_g') ?? 0) > 0)
                    ->helperText(__('El producto vuelto en buen estado se puede vender; el no vendible se registra como merma. Elección obligatoria.')),
                Select::make('method')
                    ->label(__('Método'))
                    ->options(fn (Dispensation $record): array => self::methodOptions($record))
                    ->required()
                    ->helperText(fn (Dispensation $record): ?string => self::openTillFor($record) === null
                        ? __('Sin caja abierta en esta sede: solo se puede reembolsar al monedero.')
                        : null),
                Textarea::make('reason')
                    ->label(__('Motivo'))
                    ->required(),
            ])
            ->action(function (Dispensation $record, array $data): void {
                $actor = Auth::user();
                if (! $actor instanceof User) {
                    return;
                }

                $method = RefundMethod::from((string) $data['method']);
                $grams = Weight::fromGrams((string) ($data['weight_g'] ?? '0'))->centigrams;

                try {
                    (new RefundDispensation)->handle($record, $actor, [
                        'amount_cents' => Money::fromEuros((string) $data['amount_eur'])->cents,
                        'grams_cg' => $grams,
                        'destination' => $grams > 0
                            ? RefundDestination::from((string) $data['destination'])
                            : RefundDestination::STOCK,
                        'method' => $method,
                        'reason' => (string) $data['reason'],
                        'till_session' => $method === RefundMethod::CASH ? self::openTillFor($record) : null,
                    ]);
                } catch (Throwable $e) {
                    // Let the ACTION be the single source of truth on what's refundable — surface its reason,
                    // never a silent dead end (prompt 60): over-refund, closed window, no open till, etc.
                    Notification::make()->danger()->title(__('No se pudo reembolsar'))->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('Reembolso registrado'))->send();
            });
    }

    /** @return array<string, string> STOCK always; MERMA only with the stock.merma permission. */
    public static function destinationOptions(): array
    {
        $options = [RefundDestination::STOCK->value => RefundDestination::STOCK->label()];

        if (Auth::user()?->can('stock.merma') ?? false) {
            $options[RefundDestination::MERMA->value] = RefundDestination::MERMA->label();
        }

        return $options;
    }

    /** @return array<string, string> WALLET always; CASH only when an open till exists to pay it out of. */
    public static function methodOptions(Dispensation $dispensation): array
    {
        $options = [RefundMethod::WALLET->value => RefundMethod::WALLET->label()];

        if (self::openTillFor($dispensation) !== null) {
            $options[RefundMethod::CASH->value] = RefundMethod::CASH->label();
        }

        return $options;
    }

    public static function openTillFor(Dispensation $dispensation): ?TillSession
    {
        return TillSession::query()->withoutGlobalScopes()
            ->where('location_id', $dispensation->location_id)
            ->where('status', TillSessionStatus::OPEN->value)
            ->orderBy('opened_at')
            ->first();
    }
}
