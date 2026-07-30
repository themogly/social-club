<?php

namespace App\Filament\Resources\Genetics\Schemas;

use App\Enums\CategoryAppliesTo;
use App\Enums\CultivationType;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
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
                                ->mapWithKeys(fn (CultivationType $case): array => [$case->value => $case->value])
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
