<?php

namespace App\Filament\Resources\Discounts\Schemas;

use App\Enums\DiscountAppliesTo;
use App\Enums\DiscountKind;
use App\Enums\DiscountMode;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DiscountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Nombre'))->required()->maxLength(255),

            Select::make('kind')->label(__('Tipo'))
                ->options(collect(DiscountKind::cases())->mapWithKeys(fn (DiscountKind $c) => [$c->value => $c->value])->all())
                ->required(),

            Select::make('mode')->label(__('Modo'))
                ->options([DiscountMode::PERCENT->value => __('Porcentaje'), DiscountMode::FIXED->value => __('Importe fijo')])
                ->required(),

            TextInput::make('value_pct')->label(__('Porcentaje (%)'))->numeric()->minValue(0)->maxValue(100)
                ->helperText(__('Sólo si el modo es porcentaje.')),

            TextInput::make('value_eur')->label(__('Importe (€)'))->numeric()->minValue(0)
                ->helperText(__('Sólo si el modo es importe fijo.')),

            Select::make('applies_to')->label(__('Se aplica a'))
                ->options([
                    DiscountAppliesTo::GENETIC->value => __('Genéticas'),
                    DiscountAppliesTo::ARTICLE->value => __('Artículos'),
                    DiscountAppliesTo::BOTH->value => __('Ambos'),
                ])->default(DiscountAppliesTo::BOTH->value)->required(),

            Select::make('category_id')->label(__('Categoría (opcional)'))->relationship('category', 'name')->searchable(),

            Select::make('locations')->label(__('Sedes donde aplica'))->relationship('locations', 'name')->multiple()->preload(),

            Toggle::make('active')->label(__('Activo'))->default(true),
        ]);
    }
}
