<?php

namespace App\Filament\Resources\Genetics\Tables;

use App\Enums\BatchStatus;
use App\Enums\ProductType;
use App\Enums\StrainType;
use App\Filament\Resources\Genetics\GeneticDeletion;
use App\Models\Batch;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Scopes\LocationScope;
use App\Support\ActiveScope;
use App\Support\Percent;
use App\Support\StockCover;
use App\Support\Units;
use App\Support\Weight;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class GeneticsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Prompt 354 (Ben: "the latest first on all the entries") — newest first; every header still sorts.
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('Nombre'))->searchable()->sortable(),
                // Derived completeness (prompt 93) — NEVER stored. A genetic can be Active + Published and
                // still appear NOWHERE at the counter; this says why, so nobody has to guess.
                TextColumn::make('completeness')
                    ->label(__('Estado'))
                    ->badge()
                    // Prompt 320 — a strain is created without a batch now, so "no stock anywhere" is the ordinary first
                    // state and says so (its price comes with its first batch), rather than "Sin precio".
                    ->state(fn (Genetic $record): string => ! $record->hasAnyStock() ? __('Sin existencias') : match ($record->completenessReason()) {
                        'no_price' => __('Sin precio'),
                        'no_stock' => __('Sin stock'),
                        default => __('Lista'),
                    })
                    ->color(fn (Genetic $record): string => $record->completenessReason() === null ? 'success' : 'warning')
                    ->tooltip(fn (Genetic $record): ?string => ! $record->hasAnyStock() ? __('Añade existencias con «Crear lote».') : match ($record->completenessReason()) {
                        'no_price' => __('Añade un precio por sede para poder dispensarla.'),
                        'no_stock' => __('Añade un lote con stock para poder dispensarla.'),
                        default => null,
                    }),
                TextColumn::make('product_type')->label(__('Tipo'))->badge()->sortable(),
                TextColumn::make('strain_type')->label(__('Variedad'))->badge()->placeholder('—')->toggleable(),
                TextColumn::make('category.name')->label(__('Categoría'))->sortable()->toggleable(),
                TextColumn::make('thc_bp')
                    ->label(__('THC'))
                    ->state(fn (Genetic $record): string => Percent::formatted(((int) $record->thc_bp) / 100)),
                TextColumn::make('cbd_bp')
                    ->label(__('CBD'))
                    ->state(fn (Genetic $record): string => Percent::formatted(((int) $record->cbd_bp) / 100)),
                TextColumn::make('cultivation_type')
                    ->label(__('Cultivo'))
                    ->badge()
                    ->toggleable(),
                // OPEN batches at the active location — grams for WEIGHT genetics, units +
                // gram-equivalent for UNIT genetics. The relation keeps the LocationScope in force.
                TextColumn::make('stock_g')
                    ->label(__('Stock'))
                    ->state(function (Genetic $record): string {
                        $open = $record->batches()->where('status', BatchStatus::OPEN->value);
                        if ($record->isUnitType()) {
                            $units = (int) $open->sum('remaining_units');

                            return $units === 0 ? __('Sin existencias') : Units::count((int) $units).' ('.Weight::fromCentigrams($units * (int) $record->grams_per_unit_cg)->formatted().')';
                        }
                        $cg = (int) $open->sum('remaining_cg');

                        return $cg === 0 ? __('Sin existencias') : Weight::fromCentigrams($cg)->formatted();
                    }),
                // Prompt 347 — where each strain is in stock (308's rule: anything left), every sede and the store, whatever the
                // panel's sede: a strain belongs to the club, its stock to batches at a sede.
                TextColumn::make('in_stock_at')
                    ->label(__('Sedes'))
                    ->state(fn (Genetic $record): string => Batch::query()->withoutGlobalScope(LocationScope::class)
                        ->where('batches.genetic_id', $record->getKey())->inStock()
                        ->join('locations', 'locations.id', '=', 'batches.location_id')
                        ->orderBy('locations.name')->distinct()->pluck('locations.name')->implode(' · '))
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('published')->label(__('Publicada'))->boolean(),
                IconColumn::make('active')->label(__('Activa'))->boolean(),
            ])
            ->filters([
                // Prompt 273 — where the "variedades con stock bajo" alert lands: the SAME verdict as the counter's dot and
                // the alert (StockCover), at the active sede or — for the owner's rollup — at any sede.
                Filter::make('low_stock')
                    ->label(__('Stock bajo'))
                    ->query(fn (Builder $query): Builder => $query->whereIn('id', StockCover::lowGeneticIds(self::sedesInScope()))),
                SelectFilter::make('product_type')
                    ->label(__('Tipo de producto'))
                    ->options(collect(ProductType::cases())
                        ->mapWithKeys(fn (ProductType $case): array => [$case->value => $case->label()])
                        ->all()),
                SelectFilter::make('strain_type')
                    ->label(__('Variedad'))
                    ->options(collect(StrainType::cases())
                        ->mapWithKeys(fn (StrainType $case): array => [$case->value => $case->label()])
                        ->all()),
                // Prompt 347 (Liam: "Strains filter by location") — strains with stock left at one or more sedes (the store
                // included). With one sede chosen in the panel's top bar it starts as that sede, a chip that can be removed.
                SelectFilter::make('sede')
                    ->label(__('Con existencias en…'))
                    ->multiple()
                    ->options(fn (): array => Location::query()->withoutGlobalScopes()
                        ->where('organisation_id', app(ActiveScope::class)->organisationId())
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (): array => array_filter([app(ActiveScope::class)->locationId()]))
                    // A strain with no stock ANYWHERE yet (just created, 320) belongs to no sede, so it stays in view under every
                    // sede — otherwise the strain you just created would vanish from the list behind the default chip.
                    ->query(fn (Builder $query, array $data): Builder => filled($data['values'] ?? null)
                        ? $query->where(fn (Builder $q): Builder => $q
                            ->whereIn('genetics.id', Batch::query()->withoutGlobalScope(LocationScope::class)
                                ->select('batches.genetic_id')->inStock()->whereIn('batches.location_id', (array) $data['values']))
                            ->orWhereNotIn('genetics.id', Batch::query()->withoutGlobalScope(LocationScope::class)
                                ->select('batches.genetic_id')->inStock()))
                        : $query),
                TrashedFilter::make(),
            ])
            // 13 columns needing 1147px in a 1056px holder — six row-action controls were measured
            // OFF the viewport and not clickable at 1440 (prompt 170), on a laptop, today.
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    GeneticDeletion::bulkAction(), // prompt 308 — deletes what it can, reports the rest
                    RestoreBulkAction::make(),
                ]),
            ])
            // Day one of a real club, EVERY one of these tables is empty; a framework shrug is the
            // first thing a new owner sees (admin audit, Phase C). Say what the screen is for and
            // what to do first.
            ->emptyStateHeading(__('Sin genéticas'))
            ->emptyStateDescription(__('Una genética es una variedad con su precio por gramo en cada sede. Crea la primera y después registra un lote con su stock.'));
    }

    /** @return Collection<int, Location> the active sede, or every sede of the organisation for the rollup */
    private static function sedesInScope(): Collection
    {
        $scope = app(ActiveScope::class);

        return Location::query()->withoutGlobalScopes()
            ->where('organisation_id', $scope->organisationId())
            ->when($scope->locationId() !== null, fn (Builder $query): Builder => $query->whereKey($scope->locationId()))
            ->get();
    }
}
