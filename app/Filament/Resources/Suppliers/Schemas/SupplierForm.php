<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Nombre'))
                    ->required()
                    ->maxLength(255),

                TextInput::make('contact')
                    ->label(__('Contacto'))
                    ->maxLength(255),

                TextInput::make('tax_id')
                    ->label(__('CIF/NIF'))
                    ->maxLength(255),

                Textarea::make('notes')
                    ->label(__('Notas'))
                    ->columnSpanFull(),

                Toggle::make('active')
                    ->label(__('Activo'))
                    ->default(true),
            ]);
    }
}
