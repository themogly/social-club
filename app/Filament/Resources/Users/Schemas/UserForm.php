<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Support\Email;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
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
                    ->maxLength(255)
                    // Normalise on blur so the uniqueness check and the stored value both use the lowercase
                    // form regardless of driver (prompt 146).
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, callable $set) => $set('email', Email::normalise($state))),

                // Credentials (prompt 163). A browser password manager fills ANY password input on a domain
                // it holds credentials for, so an owner editing SOMEONE ELSE'S row had their own password
                // filled in, dehydrated (the field was merely non-empty), and re-hashed by the `hashed` cast
                // — bcrypt is salted, so even the same plaintext yields a different hash. That silently
                // replaced the target user's password. The visible symptom was the milder one: the new hash
                // no longer matched the editing session's, so AuthenticateSession signed the owner out
                // ("saving a PIN signs me out"). Two independent defences:
                //
                //   1. autocomplete="new-password" — the only hint Chrome honours on a password field
                //      ("off" is ignored). It is also semantically right: this input sets a NEW password,
                //      never the current one.
                //   2. An explicit intent toggle on edit. The value is dehydrated only when the operator
                //      ASKED to set it in this session, so a populated-but-untouched field cannot persist
                //      even if an extension ignores the hint. Until the toggle is on the input is not
                //      rendered at all, so there is nothing for the browser to fill in the first place.
                //
                // The original `filled($state)` guard is kept and AND-ed with the intent, not replaced.
                // AuthenticateSession is deliberately untouched — invalidating sessions on a password
                // change is exactly what it is for; see DECISIONS.
                Toggle::make('set_password')
                    ->label(__('Establecer una contraseña nueva'))
                    ->helperText(__('Déjalo desactivado para conservar la contraseña actual.'))
                    ->live()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'edit'),

                TextInput::make('password')
                    ->label(__('Contraseña'))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->maxLength(255)
                    ->visible(fn (string $operation, Get $get): bool => $operation === 'create' || (bool) $get('set_password'))
                    ->required(fn (string $operation, Get $get): bool => $operation === 'create' || (bool) $get('set_password'))
                    ->dehydrated(fn (?string $state, string $operation, Get $get): bool => filled($state)
                        && ($operation === 'create' || (bool) $get('set_password'))),

                Toggle::make('set_pin')
                    ->label(__('Establecer un PIN nuevo'))
                    ->helperText(__('Déjalo desactivado para conservar el PIN actual.'))
                    ->live()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation === 'edit'),

                // Same treatment: the PIN is a password-type input on the same form and carries the same
                // autofill exposure. A silently rewritten PIN means an operator who cannot identify at the
                // till, and every transaction they take is attributed to nobody.
                TextInput::make('pin')
                    ->label(__('PIN de mostrador'))
                    ->password()
                    ->revealable()
                    ->autocomplete('new-password')
                    ->numeric()
                    ->minLength(4)
                    ->maxLength(8)
                    ->helperText(__('4–8 dígitos. Identifica al operador en el mostrador.'))
                    ->visible(fn (string $operation, Get $get): bool => $operation === 'create' || (bool) $get('set_pin'))
                    ->required(fn (string $operation, Get $get): bool => $operation === 'edit' && (bool) $get('set_pin'))
                    ->dehydrated(fn (?string $state, string $operation, Get $get): bool => filled($state)
                        && ($operation === 'create' || (bool) $get('set_pin'))),

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
