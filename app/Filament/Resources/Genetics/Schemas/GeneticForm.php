<?php

namespace App\Filament\Resources\Genetics\Schemas;

use App\Enums\CategoryAppliesTo;
use App\Enums\CultivationType;
use App\Enums\ProductTypeChoice;
use App\Enums\StrainType;
use App\Models\Category;
use App\Rules\GramAmount;
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
                        // The picker offers the type as staff know it — Hachís first-level (prompt 276); the
                        // EditGenetic page maps the choice onto product_type + concentrate_subtype both ways.
                        Select::make('product_type')
                            ->label(__('Tipo de producto'))
                            ->options(ProductTypeChoice::options())
                            ->default(ProductTypeChoice::FLOWER->value)
                            ->required()
                            ->live()
                            ->helperText(fn (Get $get): string => __('Se dispensa: :modo', [
                                'modo' => (ProductTypeChoice::tryFrom((string) $get('product_type')) ?? ProductTypeChoice::FLOWER)->unitType()->label(),
                            ])),

                        // Strain variety (prompt 66) — sativa/indica/hybrid, nullable (some products have none).
                        Select::make('strain_type')
                            ->label(__('Variedad'))
                            ->options(collect(StrainType::cases())
                                ->mapWithKeys(fn (StrainType $case): array => [$case->value => $case->label()])
                                ->all())
                            ->placeholder(__('Sin especificar')),

                        // Descriptive only, concentrates only — and never Hachís, which is its own choice above.
                        Select::make('concentrate_subtype')
                            ->label(__('Subtipo de extracto'))
                            ->options(ProductTypeChoice::extractSubtypeOptions())
                            ->visible(fn (Get $get): bool => $get('product_type') === ProductTypeChoice::CONCENTRATE->value),

                        // Entered as grams (2 dp); the page converts to grams_per_unit_cg. Required for units.
                        TextInput::make('grams_per_unit_g')
                            ->label(__('Gramos por unidad (g)'))
                            ->helperText(__('Contenido en gramos de cada unidad.'))
                            ->numeric()
                            ->rule(new GramAmount)
                            ->minValue(0)
                            ->step(0.01)
                            ->suffix('g')
                            ->visible(fn (Get $get): bool => in_array($get('product_type'), [ProductTypeChoice::PREROLL->value, ProductTypeChoice::EDIBLE->value], true))
                            ->required(fn (Get $get): bool => in_array($get('product_type'), [ProductTypeChoice::PREROLL->value, ProductTypeChoice::EDIBLE->value], true)),

                        // Edibles only — potency per unit, stored directly in milligrams.
                        TextInput::make('thc_mg_per_unit')
                            ->label(__('THC por unidad (mg)'))
                            ->numeric()
                            ->minValue(0)
                            ->step(1)
                            ->suffix('mg')
                            ->visible(fn (Get $get): bool => $get('product_type') === ProductTypeChoice::EDIBLE->value),
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
                        // Prompt 247 — the category is a menu GROUP, not a fact about the strain, so it moved out
                        // of "Datos" (where it rendered as an empty "Select an option" on a club that defined
                        // none) to here, and is HIDDEN entirely when the organisation has no genetic categories:
                        // an empty select is a question with no answer. The create flow does not ask it at all;
                        // the POS filter and everything else that reads category_id are untouched.
                        Select::make('category_id')
                            ->label(__('Grupo en el menú (opcional)'))
                            ->relationship(
                                'category',
                                'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->where('applies_to', CategoryAppliesTo::GENETIC->value),
                            )
                            ->searchable()
                            ->preload()
                            ->visible(fn (): bool => Category::query()
                                ->where('applies_to', CategoryAppliesTo::GENETIC->value)->exists()),

                        Toggle::make('published')
                            ->label(__('Publicada'))
                            ->helperText(__('Visible en el menú de la app de socios. El mostrador la ve igualmente.'))
                            ->default(true),

                        Toggle::make('active')
                            ->label(__('Activa'))
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
