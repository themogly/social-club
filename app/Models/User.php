<?php

namespace App\Models;

use App\Actions\ResolveLocale;
use App\Casts\NormalisedEmail;
use App\Enums\AlertType;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use App\Support\PinLookup;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'pin', 'active', 'locale', 'member_id'])]
#[Hidden(['password', 'remember_token', 'pin', 'pin_lookup', 'mfa_secret', 'mfa_recovery_codes', 'telegram_chat_id', 'telegram_chat_hash'])]
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
        // Prompt 262 — panel access is its own switch (`panel.access`, owner-editable per role; STAFF off by default).
        return $this->canUseTheApp() && $this->can('panel.access');
    }

    /**
     * May this account sign in at all — to the counter, and to the panel if it also holds `panel.access`? Active and
     * holding a role (prompt 262). The login asks THIS, so a counter-only account is not refused as "wrong credentials".
     */
    public function canUseTheApp(): bool
    {
        return $this->active && $this->hasAnyRole(array_column(Role::cases(), 'value'));
    }

    protected static function booted(): void
    {
        // Prompt 315 — every person has a language SAVED: a new account takes the club's current default (resolved the one
        // way, ResolveLocale with no subject) unless it was given one. ONE place, so no creation path can forget it.
        static::creating(function (User $user): void {
            if (blank($user->locale)) {
                $user->locale = (new ResolveLocale)->handle();
            }
        });

        // Prompt 281 — the registro de jornada is kept at least 4 years, also after the person leaves (a soft delete keeps
        // it; the FK restricts). A force-delete of someone with recent clock events is refused, saying why.
        static::forceDeleting(function (User $user): void {
            if (StaffClockEvent::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('occurred_at', '>=', now()->subYears(4))->exists()) {
                throw new \RuntimeException(__('No se puede borrar definitivamente: su registro de jornada debe conservarse 4 años.'));
            }
        });
    }

    /**
     * Is this PIN already someone else's? (prompt 270; prompt 286 made it an indexed lookup)
     *
     * Since 267 a PIN is a sign-in, so it must name exactly one person. The lookup is unique in the database; the only
     * bcrypt left is for people still on a LEGACY hash (set before 286 and not yet used) — a set that empties as
     * everyone enters their PIN once. Inactive and soft-deleted accounts count too: reactivating one must not collide.
     */
    public static function pinIsTaken(#[SensitiveParameter] string $pin, ?string $exceptUserId = null): bool
    {
        $others = static::query()->withTrashed()->when($exceptUserId !== null, fn ($q) => $q->whereKeyNot($exceptUserId));

        if ((clone $others)->where('pin_lookup', PinLookup::for($pin))->exists()) {
            return true;
        }

        foreach ((clone $others)->whereNull('pin_lookup')->whereNotNull('pin')->pluck('pin') as $hash) {
            if (Hash::check($pin, (string) $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Writing a PIN (prompt 286): a plain PIN is stored as its lookup only — no bcrypt hash beside it, which would keep
     * the offline route open without the key. A value that is ALREADY a password hash is a legacy PIN (older fixtures and
     * data) and is kept as it is, to upgrade on first use. Null clears both.
     *
     * @return Attribute<?string, ?string>
     */
    protected function pin(): Attribute
    {
        return Attribute::make(set: function (#[SensitiveParameter] ?string $value): array {
            if (blank($value)) {
                return ['pin' => null, 'pin_lookup' => null];
            }

            return Hash::isHashed((string) $value)
                ? ['pin' => $value, 'pin_lookup' => null]
                : ['pin' => null, 'pin_lookup' => PinLookup::for((string) $value)];
        });
    }

    /** Has this person a counter PIN at all — a lookup, or a legacy hash not yet upgraded? */
    public function hasPin(): bool
    {
        return filled($this->getRawOriginal('pin_lookup')) || filled($this->getRawOriginal('pin'));
    }

    /**
     * Prompt 347 — this person's own member record, when they are also a socio: the club's staff discount applies to it
     * while the account is active, and the counter knows when they serve themselves. One record per account.
     *
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
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
        if (! $this->hasPin()) {
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

    // --- Owner alerts (prompt 311) ------------------------------------------------------------------------------

    /** Link this person's Telegram chat: the id encrypted, plus a keyed hash so `/stop` can find it again. */
    public function linkTelegram(string $chatId): void
    {
        $this->forceFill(['telegram_chat_id' => $chatId, 'telegram_chat_hash' => self::telegramChatHash($chatId)])->save();
    }

    public function unlinkTelegram(): void
    {
        $this->forceFill(['telegram_chat_id' => null, 'telegram_chat_hash' => null])->save();
    }

    public static function findByTelegramChat(string $chatId): ?self
    {
        return self::query()->where('telegram_chat_hash', self::telegramChatHash($chatId))->first();
    }

    private static function telegramChatHash(string $chatId): string
    {
        return hash_hmac('sha256', $chatId, (string) config('app.key'));
    }

    /** @return list<string> 'telegram' and/or 'email' — both unless the person chose otherwise */
    public function alertChannels(): array
    {
        $channels = data_get($this->alert_preferences, 'channels');

        return is_array($channels) ? array_values(array_intersect(['telegram', 'email'], $channels)) : ['telegram', 'email'];
    }

    /** @return list<string> the alert types this person takes — all six unless they chose otherwise */
    public function alertTypes(): array
    {
        $types = data_get($this->alert_preferences, 'types');
        $all = array_map(fn (AlertType $type): string => $type->value, AlertType::cases());

        return is_array($types) ? array_values(array_intersect($all, $types)) : $all;
    }

    /** @return list<string>|null the sedes chosen, or null for every sede they are assigned to */
    public function alertLocationIds(): ?array
    {
        $ids = data_get($this->alert_preferences, 'location_ids');

        return is_array($ids) && $ids !== [] ? array_values(array_map('strval', $ids)) : null;
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
            'mfa_secret' => 'encrypted',
            'mfa_confirmed_at' => 'datetime',
            'mfa_recovery_codes' => 'encrypted:array',
            'active' => 'boolean',
            'telegram_chat_id' => 'encrypted', // prompt 311 — the only personal data Telegram delivery needs, encrypted
            'alert_preferences' => 'array',
            'alert_summary_sent_on' => 'date',
        ];
    }
}
