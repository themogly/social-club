<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Nombre'))
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label(__('Correo electrónico'))
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                // Password (and PIN) columns carry a 'hashed' cast — set the plain value
                // and it is hashed on save. Only persisted when filled, so editing a user
                // without changing them leaves them intact.
                TextInput::make('password')
                    ->label(__('Contraseña'))
                    ->password()
                    ->revealable()
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->maxLength(255),

                TextInput::make('pin')
                    ->label(__('PIN de mostrador'))
                    ->password()
                    ->revealable()
                    ->numeric()
                    ->minLength(4)
                    ->maxLength(8)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText(__('4–8 dígitos. Identifica al operador en el mostrador.')),

                Select::make('roles')
                    ->label(__('Roles'))
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->required(),

                Select::make('locations')
                    ->label(__('Sedes asignadas'))
                    ->relationship('locations', 'name')
                    ->multiple()
                    ->preload()
                    ->helperText(__('Asigna una o varias sedes. El propietario ve todas las sedes de todos modos, así que aquí es opcional. Sin ninguna sede, un gestor o personal puede iniciar sesión pero no tendrá sede activa (sin acceso hasta que se le asigne una).')),

                Toggle::make('active')
                    ->label(__('Activo'))
                    ->default(true),
            ]);
    }
}
