<?php

namespace App\Filament\Resources\Genetics\Schemas;

use App\Enums\CategoryAppliesTo;
use App\Enums\ConcentrateSubtype;
use App\Enums\CultivationType;
use App\Enums\ProductType;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class GeneticForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Datos'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Nombre'))
                            ->required()
                            ->maxLength(255),

                        Select::make('category_id')
                            ->label(__('Categoría'))
                            ->relationship(
                                'category',
                                'name',
                                // Only categories that apply to genetics (nice-to-have narrowing).
                                modifyQueryUsing: fn (Builder $query): Builder => $query->where('applies_to', CategoryAppliesTo::GENETIC->value),
                            )
                            ->searchable()
                            ->preload(),

                        Textarea::make('description')
                            ->label(__('Descripción'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('Tipo de producto'))
                    ->description(__('El tipo determina cómo se dispensa: por peso (flor/extracto) o por unidad (preliado/comestible).'))
                    ->schema([
                        // product_type drives the derived, stored unit_type (set by GeneticObserver).
                        // unit_type is never a form field — it is observer-derived, never user-entered.
                        Select::make('product_type')
                            ->label(__('Tipo de producto'))
                            ->options(collect(ProductType::cases())
                                ->mapWithKeys(fn (ProductType $case): array => [$case->value => $case->label()])
                                ->all())
                            ->default(ProductType::FLOWER->value)
                            ->required()
                            ->live()
                            ->helperText(fn (Get $get): string => __('Se dispensa: :modo', [
                                'modo' => (ProductType::tryFrom((string) $get('product_type')) ?? ProductType::FLOWER)->unitType()->label(),
                            ])),

                        // Descriptive only, concentrates only.
                        Select::make('concentrate_subtype')
                            ->label(__('Subtipo de extracto'))
                            ->options(collect(ConcentrateSubtype::cases())
                                ->mapWithKeys(fn (ConcentrateSubtype $case): array => [$case->value => $case->label()])
                                ->all())
                            ->visible(fn (Get $get): bool => $get('product_type') === ProductType::CONCENTRATE->value),

                        // Entered as grams (2 dp); the page converts to grams_per_unit_cg. Required for units.
                        TextInput::make('grams_per_unit_g')
                            ->label(__('Gramos por unidad (g)'))
                            ->helperText(__('Contenido en gramos de cada unidad.'))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->suffix('g')
                            ->visible(fn (Get $get): bool => in_array($get('product_type'), [ProductType::PREROLL->value, ProductType::EDIBLE->value], true))
                            ->required(fn (Get $get): bool => in_array($get('product_type'), [ProductType::PREROLL->value, ProductType::EDIBLE->value], true)),

                        // Edibles only — potency per unit, stored directly in milligrams.
                        TextInput::make('thc_mg_per_unit')
                            ->label(__('THC por unidad (mg)'))
                            ->numeric()
                            ->minValue(0)
                            ->step(1)
                            ->suffix('mg')
                            ->visible(fn (Get $get): bool => $get('product_type') === ProductType::EDIBLE->value),
                    ])
                    ->columns(2),

                Section::make(__('Cannabinoides y cultivo'))
                    ->schema([
                        // Stored as basis points (thc_bp / cbd_bp). The Create/Edit pages
                        // convert percent ↔ basis points (pct = bp / 100, bp = round(pct * 100)).
                        TextInput::make('thc_pct')
                            ->label(__('THC (%)'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->suffix('%'),

                        TextInput::make('cbd_pct')
                            ->label(__('CBD (%)'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->suffix('%'),

                        Select::make('cultivation_type')
                            ->label(__('Tipo de cultivo'))
                            ->options(collect(CultivationType::cases())
                                ->mapWithKeys(fn (CultivationType $case): array => [$case->value => $case->label()])
                                ->all()),

                        TagsInput::make('terpenes')
                            ->label(__('Terpenos')),
                    ])
                    ->columns(2),

                Section::make(__('Imágenes'))
                    ->schema([
                        FileUpload::make('images')
                            ->label(__('Imágenes'))
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->multiple(),
                    ]),

                Section::make(__('Publicación'))
                    ->schema([
                        Toggle::make('published')
                            ->label(__('Publicada')),

                        Toggle::make('active')
                            ->label(__('Activa'))
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
