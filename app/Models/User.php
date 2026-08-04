<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Casts\NormalisedEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'pin', 'active', 'locale'])]
#[Hidden(['password', 'remember_token', 'pin', 'mfa_secret', 'mfa_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUlids, Notifiable, SoftDeletes;

    /** @var array<string, mixed> */
    protected $attributes = [
        'active' => true,
    ];

    /**
     * Gate access to the Filament admin panel: a staff account (has a role) that is
     * active. Members authenticate on a separate guard (prompt 15) — they are not
     * User records. A no-role or deactivated user is refused with a clear 403 (a
     * distinct failure from wrong credentials, so a role problem is not mistaken for one).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->active && $this->hasAnyRole(array_column(Role::cases(), 'value'));
    }

    /** @return BelongsToMany<Location, $this> */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class)->withTimestamps();
    }

    /** The user's own UI-language preference (prompt 96); null = fall through to the org default. */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * Derived setup gaps (prompt 93), never stored: without a ROLE canAccessPanel() refuses; without a
     * LOCATION they are scoped to nothing; without a PIN they cannot identify at the counter. A user row
     * can look complete while being unable to do any of these.
     *
     * @return list<string>
     */
    public function setupIncompleteReasons(): array
    {
        $reasons = [];
        if ($this->getRoleNames()->isEmpty()) {
            $reasons[] = 'no_role';
        }
        if ($this->locations()->count() === 0) {
            $reasons[] = 'no_location';
        }
        if (blank($this->getRawOriginal('pin'))) {
            $reasons[] = 'no_pin';
        }

        return $reasons;
    }

    // --- Multi-factor (TOTP app authentication) --------------------------------

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->mfa_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->mfa_secret = $secret;
        $this->mfa_confirmed_at = $secret !== null ? now() : null;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /**
     * @return ?array<string>
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->mfa_recovery_codes;
    }

    /**
     * @param  ?array<string>  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->mfa_recovery_codes = $codes;
        $this->save();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email' => NormalisedEmail::class,   // login identifier — normalised lowercase at the boundary (prompt 146)
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_confirmed_at' => 'datetime',
            'mfa_recovery_codes' => 'encrypted:array',
            'active' => 'boolean',
        ];
    }
}
