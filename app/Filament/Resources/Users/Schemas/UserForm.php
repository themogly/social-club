<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Actions\ResolveLocale;
use App\Enums\Role;
use App\Filament\Forms\PinInput;
use App\Models\Member;
use App\Models\User;
use App\Support\Email;
use App\Support\PinCollisionGuard;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

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

                // Prompt 315 — the person's saved language: the club default on a new person, editable here like the rest of
                // the form. The person changes their own on their profile (the same value as the top-bar ES/EN switch).
                Select::make('locale')
                    ->label(__('Idioma'))
                    ->options(fn (): array => (new ResolveLocale)->options())
                    ->default(fn (): string => (new ResolveLocale)->handle())
                    ->required()
                    ->selectablePlaceholder(false)
                    ->helperText(__('Idioma del panel, del mostrador, de sus correos y de sus avisos de Telegram.')),

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

                // Prompt 322 — the PIN is NOT a password field any more (see PinInput): on live a Mac's password manager
                // filled the masked `new-password` PIN with something that was not the PIN that was typed. It is typed
                // twice, and the save says where it will work. The intent toggle and the dehydration guard above still
                // apply: an untouched field never persists.
                PinInput::make('pin')
                    ->label(__('PIN de mostrador'))
                    ->helperText(__('4–8 dígitos. Identifica al operador en el mostrador.'))
                    ->live(onBlur: true) // on create, the repeat appears once a PIN is typed
                    // Prompt 270 — a PIN is a sign-in, so it must name exactly one person.
                    // Post-296 audit — and the "taken" answer is throttled and audited (PinCollisionGuard), so the form is no
                    // longer a way to find somebody else's PIN.
                    ->rule(fn (?User $record): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                        $refusal = filled($value) ? PinCollisionGuard::refusal((string) $value, $record?->id) : null;
                        if ($refusal !== null) {
                            $fail($refusal);
                        }
                    })
                    ->visible(fn (string $operation, Get $get): bool => $operation === 'create' || (bool) $get('set_pin'))
                    ->required(fn (string $operation, Get $get): bool => $operation === 'edit' && (bool) $get('set_pin'))
                    ->dehydrated(fn (?string $state, string $operation, Get $get): bool => filled($state)
                        && ($operation === 'create' || (bool) $get('set_pin'))),

                PinInput::make('pin_confirmation')
                    ->label(__('Repite el PIN'))
                    ->same('pin')
                    ->validationMessages(['same' => __('Los PIN no coinciden.')])
                    ->visible(fn (string $operation, Get $get): bool => self::settingPin($operation, $get))
                    ->required(fn (string $operation, Get $get): bool => self::settingPin($operation, $get))
                    ->dehydrated(false),

                // Prompt 270 — only an owner hands out (or takes away) the owner role, and nobody but an owner edits their
                // own roles: `staff.manage` granted to a manager used to let them promote themselves to OWNER. The
                // options are filtered here; `UserPolicy` and `EnsureRoleChangeIsAllowed` hold the line server-side.
                Select::make('roles')
                    ->label(__('Roles'))
                    ->relationship('roles', 'name', modifyQueryUsing: fn (Builder $query): Builder => self::actorIsOwner()
                        ? $query
                        : $query->where('name', '!=', Role::OWNER->value))
                    ->multiple()
                    ->preload()
                    ->disabled(fn (?User $record): bool => ! self::actorIsOwner() && $record !== null && $record->is(Auth::user()))
                    ->helperText(fn (?User $record): ?string => ! self::actorIsOwner() && $record !== null && $record->is(Auth::user())
                        ? __('Tus propios roles solo los puede cambiar el propietario.')
                        : null)
                    ->required(),

                Select::make('locations')
                    ->label(__('Sedes asignadas'))
                    ->relationship('locations', 'name')
                    ->multiple()
                    ->preload()
                    ->helperText(__('Asigna una o varias sedes. El propietario ve todas las sedes de todos modos, así que aquí es opcional. Sin ninguna sede, un gestor o personal puede iniciar sesión pero no tendrá sede activa (sin acceso hasta que se le asigne una).')),

                // Prompt 347 — this person's own member record, when they are also a socio: the club's staff discount
                // (Ajustes) then applies to them by itself while this account is active, and the counter knows when they
                // serve themselves. Only people who manage staff see it (this form is `staff.manage`); audited.
                Select::make('member_id')
                    ->label(__('Ficha de socio'))
                    ->helperText(__('Opcional. Vincula a esta persona con su propia ficha de socio (nombre o número).'))
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => blank(trim($search)) ? [] : Member::query()
                        ->matchingNameOrNumber(trim($search))->limit(20)->get()
                        ->mapWithKeys(fn (Member $member): array => [$member->id => $member->avaladorLabel()])->all())
                    ->getOptionLabelUsing(fn (?string $value): ?string => $value !== null ? Member::query()->find($value)?->avaladorLabel() : null)
                    ->unique(ignoreRecord: true)
                    ->validationMessages(['unique' => __('Esa ficha de socio ya está vinculada a otra cuenta del personal.')])
                    ->nullable(),

                Toggle::make('active')
                    ->label(__('Activo'))
                    ->default(true),
            ]);
    }

    /** A PIN is being set: typed on create, or asked for on edit (*Establecer un PIN nuevo*). */
    private static function settingPin(string $operation, Get $get): bool
    {
        return $operation === 'create' ? filled($get('pin')) : (bool) $get('set_pin');
    }

    private static function actorIsOwner(): bool
    {
        $actor = Auth::user();

        return $actor instanceof User && $actor->hasRole(Role::OWNER->value);
    }
}
