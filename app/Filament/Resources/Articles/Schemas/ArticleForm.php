<?php

namespace App\Filament\Resources\Articles\Schemas;

use App\Filament\Forms\CameraOrFile;
use App\Models\Location;
use App\Support\ActiveScope;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Producto'))
                    ->schema([
                        // WHERE the article is sold (prompt 294, the same field as BatchForm's — prompt 238). Stock always
                        // belongs to a sede, so the form must NAME it rather than inherit an invisible scope: in the "Todas
                        // las sedes" view that scope is null and the insert failed in production. Defaults to the sede in
                        // the top bar; BLANK in the rollup so an owner picks deliberately (never a guessed first row,
                        // prompt 148); disabled and pre-filled when the club has a single sede. Sedes only — a bar/shop
                        // article is sold at a counter and the Almacén / cultivo has none. Fixed once the article exists.
                        Select::make('location_id')
                            ->label(__('Sede'))
                            ->options(fn (): array => Location::assignableOptions())
                            ->default(fn (): ?string => app(ActiveScope::class)->locationId())
                            ->required()
                            ->searchable()
                            ->disabled(fn (string $operation): bool => $operation !== 'create' || self::singleSede())
                            // A disabled field is not submitted by default; the single-sede value still must be.
                            ->dehydrated()
                            ->columnSpanFull(),

                        TextInput::make('name')
                            ->label(__('Nombre'))
                            ->required()
                            ->maxLength(255),

                        // No *Categoría* (prompt 295, Shane): nothing in the app can create a product category — only the demo
                        // seeder does — so on a live club the drop-down was always empty. The `category_id` column and any
                        // existing values stay; grouping bar products would be a small resource of its own.

                        // The model stores integer cents in price_cents; the pages
                        // convert euros ↔ cents (mutate hooks on Create/Edit).
                        TextInput::make('price_eur')
                            ->label(__('Precio (€)'))
                            ->numeric()
                            ->minValue(0)
                            ->required(),

                        // Opening stock is set here at creation only. Thereafter use the
                        // "Reponer" row action, which records the movement in the ledger.
                        TextInput::make('stock')
                            ->label(__('Existencias'))
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (string $operation): bool => $operation === 'create'),

                        TextInput::make('low_stock_threshold')
                            ->label(__('Umbral de stock bajo'))
                            ->numeric()
                            ->minValue(0),
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

                Toggle::make('active')
                    ->label(__('Activo'))
                    ->default(true),
            ]);
    }

    /** One sede in the org: nothing to choose, so the field is pre-filled and locked. */
    private static function singleSede(): bool
    {
        return Location::query()->sedes()->count() === 1;
    }
}
