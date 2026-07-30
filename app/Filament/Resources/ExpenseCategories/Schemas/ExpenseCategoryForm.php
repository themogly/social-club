<?php

namespace App\Filament\Resources\ExpenseCategories\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ExpenseCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Nombre'))
                    ->required()
                    ->maxLength(255),

                Select::make('default_kind')
                    ->label(__('Tipo por defecto'))
                    ->options([
                        'TILL' => __('Caja'),
                        'OVERHEAD' => __('Gasto general'),
                    ]),

                Toggle::make('active')
                    ->label(__('Activo'))
                    ->default(true),
            ]);
    }
}
