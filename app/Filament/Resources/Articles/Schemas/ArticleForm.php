<?php

namespace App\Filament\Resources\Articles\Schemas;

use App\Enums\CategoryAppliesTo;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ArticleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Artículo'))
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
                                modifyQueryUsing: fn (Builder $query): Builder => $query->where('applies_to', CategoryAppliesTo::ARTICLE->value),
                            )
                            ->searchable()
                            ->preload(),

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
                        FileUpload::make('images')
                            ->label(__('Imágenes'))
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->multiple(),
                    ]),

                Toggle::make('active')
                    ->label(__('Activo'))
                    ->default(true),
            ]);
    }
}
