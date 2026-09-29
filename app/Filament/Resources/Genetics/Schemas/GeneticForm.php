<?php

namespace App\Filament\Resources\Genetics\Schemas;

use App\Enums\CategoryAppliesTo;
use App\Enums\ConcentrateSubtype;
use App\Enums\CultivationType;
use App\Enums\ProductType;
use App\Enums\StrainType;
use App\Filament\Forms\CameraOrFile;
use App\Filament\Forms\DecimalInput;
use App\Filament\Resources\Genetics\GeneticResource;
use App\Models\Category;
use App\Models\Genetic;
use App\Rules\GramAmount;
use App\Rules\UniqueGeneticName;
use App\Support\EdibleEquivalence;
use App\Support\NumberFormat;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class GeneticForm
{
    /**
     * The strain's name — the SAME field on create and edit (prompt 308; one form since 320): unique in the club,
     * deleted strains included, and when the name belongs to a deleted strain, a link to bring that one back instead.
     */
    public static function nameField(): TextInput
    {
        return TextInput::make('name')
            ->label(__('Nombre'))
            ->required()
            ->maxLength(255)
            ->live(onBlur: true)
            ->rule(fn (?Genetic $record): UniqueGeneticName => new UniqueGeneticName($record))
            ->belowContent(function (?string $state, ?Genetic $record): ?HtmlString {
                $match = filled($state) ? Genetic::sameNameAs($state, $record?->getKey()) : null;
                if ($match === null || ! $match->trashed()) {
                    return null;
                }

                return new HtmlString(Blade::render('<x-filament::link :href="$href" data-restore-genetic>{{ $label }}</x-filament::link>', [
                    'href' => GeneticResource::getUrl('edit', ['record' => $match]),
                    'label' => __('Abrir «:name» para restaurarla', ['name' => $match->name]),
                ]));
            });
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Datos'))
                    ->schema([
                        self::nameField(),

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

                        // Strain variety (prompt 66) — sativa/indica/hybrid, nullable (some products have none).
                        Select::make('strain_type')
                            ->label(__('Variedad'))
                            ->options(collect(StrainType::cases())
                                ->mapWithKeys(fn (StrainType $case): array => [$case->value => $case->label()])
                                ->all())
                            ->placeholder(__('Sin especificar')),

                        // Descriptive only, concentrates only.
                        Select::make('concentrate_subtype')
                            ->label(__('Subtipo de extracto'))
                            ->options(collect(ConcentrateSubtype::cases())
                                ->mapWithKeys(fn (ConcentrateSubtype $case): array => [$case->value => $case->label()])
                                ->all())
                            ->visible(fn (Get $get): bool => $get('product_type') === ProductType::CONCENTRATE->value),

                        // Pre-rolls only (prompt 326): weighed plant material, entered as grams (2 dp); the page converts to
                        // grams_per_unit_cg. An edible's grams are never typed — they are worked out from its THC below.
                        DecimalInput::make('grams_per_unit_g')
                            ->label(__('Peso por unidad (g)'))
                            ->helperText(__('Lo que cuenta para límites y existencias.'))
                            ->numeric()
                            ->rule(new GramAmount)
                            ->minValue(0)
                            ->step(0.01)
                            ->suffix('g')
                            ->visible(fn (Get $get): bool => $get('product_type') === ProductType::PREROLL->value)
                            ->required(fn (Get $get): bool => $get('product_type') === ProductType::PREROLL->value),

                        // Edibles (prompt 326): the THC per unit is THE figure; what one unit counts as is worked out from it
                        // through the club's equivalence (EdibleEquivalence) and shown beside it, live.
                        TextInput::make('thc_mg_per_unit')
                            ->label(__('THC por unidad (mg)'))
                            ->integer()
                            ->minValue(1)
                            // The sanity cap (a constant, not a setting): above it the figure is almost certainly a typo.
                            ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                                if ((int) $value > EdibleEquivalence::MAX_THC_MG_PER_UNIT) {
                                    $fail(__('Revisa la cifra: parece demasiado alta para una unidad.'));
                                }
                            })
                            ->step(1)
                            ->suffix('mg')
                            ->live(debounce: 300)
                            ->visible(fn (Get $get): bool => $get('product_type') === ProductType::EDIBLE->value)
                            ->required(fn (Get $get): bool => $get('product_type') === ProductType::EDIBLE->value),

                        Text::make(fn (Get $get): string => __('Cuenta como :g g por unidad', [
                            'g' => NumberFormat::decimal(EdibleEquivalence::gramsCg((int) $get('thc_mg_per_unit')) / 100, 2),
                        ]))
                            ->extraAttributes(['data-edible-counts-as' => true])
                            ->visible(fn (Get $get): bool => $get('product_type') === ProductType::EDIBLE->value && (int) $get('thc_mg_per_unit') > 0),
                    ])
                    ->columns(2),

                Section::make(__('Cannabinoides y cultivo'))
                    ->schema([
                        // Stored as basis points (thc_bp / cbd_bp). The Create/Edit pages
                        // convert percent ↔ basis points (pct = bp / 100, bp = round(pct * 100)).
                        DecimalInput::make('thc_pct')
                            ->label(__('THC (%)'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->suffix('%'),

                        DecimalInput::make('cbd_pct')
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
                        CameraOrFile::field(FileUpload::make('images')
                            ->label(__('Imágenes'))
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->multiple(), camera: 'environment'),
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
