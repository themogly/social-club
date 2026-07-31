<?php

namespace App\Filament\Resources\Genetics\RelationManagers;

use App\Actions\Pricing\SaveGeneticPrice;
use App\Models\Genetic;
use App\Models\GeneticPrice;
use App\Models\Location;
use App\Models\MembershipTier;
use App\Models\Scopes\LocationScope;
use App\Support\Money;
use App\Support\Weight;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Prompt 63 — the (previously non-existent) edit surface for GeneticPrice rows: per genetic, per
 * location, optionally per membership tier (tier_id null = the base price ResolvePrice falls back to).
 * A relation manager on the Genetic resource is the natural home — a price is an attribute OF a genetic
 * and is managed in its context, scoped to that one genetic rather than a flat org-wide price list.
 *
 * Writes go through the single SaveGeneticPrice action (column selection driven by unit_type + audit);
 * ResolvePrice stays the single reader. Gated on prices.manage (its first real enforcement).
 */
class GeneticPricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Precios');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('prices.manage') ?? false;
    }

    public function table(Table $table): Table
    {
        $perUnit = $this->genetic()->isUnitType();

        return $table
            // A genetic is org-wide, so show its prices across EVERY location, not just the active one.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withoutGlobalScope(LocationScope::class))
            ->columns([
                TextColumn::make('location.name')->label(__('Sede'))->sortable(),
                TextColumn::make('tier.name')->label(__('Tarifa'))->placeholder(__('Base')),
                TextColumn::make('price')
                    ->label(__('Precio'))
                    ->state(fn (GeneticPrice $record): string => Money::fromCents(
                        (int) ($perUnit ? $record->price_per_unit_cents : $record->price_per_gram_cents)
                    )->formatted().' / '.($perUnit ? __('unidad') : __('g'))),
                TextColumn::make('low_stock_threshold_cg')
                    ->label(__('Aviso de stock bajo'))
                    ->state(fn (GeneticPrice $record): string => $record->low_stock_threshold_cg !== null
                        ? Weight::fromCentigrams($record->low_stock_threshold_cg)->formatted()
                        : '—'),
                IconColumn::make('active')->label(__('Activo'))->boolean(),
            ])
            ->headerActions([$this->createAction()])
            ->recordActions([
                $this->editAction(),
                DeleteAction::make()->visible(fn (): bool => Auth::user()?->can('prices.manage') ?? false),
            ])
            ->emptyStateHeading(__('Sin precios'))
            ->emptyStateDescription(__('Añade el precio base de esta genética por sede. Sin un precio base activo no se puede dispensar.'));
    }

    protected function createAction(): Action
    {
        return Action::make('create')
            ->label(__('Añadir precio'))
            ->icon(Heroicon::OutlinedPlus)
            ->visible(fn (): bool => Auth::user()?->can('prices.manage') ?? false)
            ->schema($this->formSchema())
            ->action(function (array $data): void {
                $location = Location::query()->whereKey($data['location_id'])->firstOrFail();

                (new SaveGeneticPrice)->handle(
                    $this->genetic(),
                    $location,
                    ($data['tier_id'] ?? null) ?: null,
                    $this->priceCents($data),
                    $this->thresholdCg($data),
                    (bool) ($data['active'] ?? true),
                );

                Notification::make()->title(__('Precio guardado'))->success()->send();
            });
    }

    protected function editAction(): Action
    {
        return Action::make('edit')
            ->label(__('Editar'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (): bool => Auth::user()?->can('prices.manage') ?? false)
            ->fillForm(fn (GeneticPrice $record): array => [
                'location_id' => $record->location_id,
                'tier_id' => $record->tier_id,
                'price_eur' => (($this->genetic()->isUnitType() ? $record->price_per_unit_cents : $record->price_per_gram_cents) ?? 0) / 100,
                'low_stock_threshold_g' => $record->low_stock_threshold_cg !== null ? $record->low_stock_threshold_cg / 100 : null,
                'active' => $record->active,
            ])
            ->schema($this->formSchema(edit: true))
            ->action(function (GeneticPrice $record, array $data): void {
                // location + tier identify the row — fixed on edit; only price/threshold/active change.
                (new SaveGeneticPrice)->handle(
                    $this->genetic(),
                    $record->location,
                    $record->tier_id,
                    $this->priceCents($data),
                    $this->thresholdCg($data),
                    (bool) ($data['active'] ?? true),
                    existing: $record,
                );

                Notification::make()->title(__('Precio actualizado'))->success()->send();
            });
    }

    /**
     * @return list<Component>
     */
    protected function formSchema(bool $edit = false): array
    {
        $perUnit = $this->genetic()->isUnitType();

        return [
            Select::make('location_id')
                ->label(__('Sede'))
                ->options(fn () => Location::query()->orderBy('name')->pluck('name', 'id'))
                ->required()
                ->disabled($edit),
            Select::make('tier_id')
                ->label(__('Tarifa'))
                ->options(fn () => MembershipTier::query()->orderBy('name')->pluck('name', 'id'))
                ->placeholder(__('Base (sin tarifa)'))
                ->helperText(__('Vacío = precio base. Una tarifa concreta tiene prioridad sobre la base para sus socios.'))
                ->disabled($edit),
            TextInput::make('price_eur')
                ->label($perUnit ? __('Precio por unidad (€)') : __('Precio por gramo (€)'))
                ->numeric()
                ->minValue(0)
                ->required(),
            TextInput::make('low_stock_threshold_g')
                ->label(__('Aviso de stock bajo (g)'))
                ->helperText($perUnit
                    ? __('Opcional. Equivalente en gramos (unidades × g/unidad) por debajo del cual avisar.')
                    : __('Opcional. Nivel por debajo del cual avisar de stock bajo.'))
                ->numeric()
                ->minValue(0), // prompt 54 consumes this as a gram-equivalent threshold for BOTH types
            Toggle::make('active')
                ->label(__('Activo'))
                ->default(true),
        ];
    }

    private function genetic(): Genetic
    {
        /** @var Genetic $genetic */
        $genetic = $this->getOwnerRecord();

        return $genetic;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function priceCents(array $data): int
    {
        return (int) round_half_up(((float) ($data['price_eur'] ?? 0)) * 100);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function thresholdCg(array $data): ?int
    {
        return filled($data['low_stock_threshold_g'] ?? null)
            ? (int) round_half_up(((float) $data['low_stock_threshold_g']) * 100)
            : null;
    }
}
