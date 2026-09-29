<?php

namespace App\Filament\Pages\Auth;

use App\Actions\RecordAuditLog;
use App\Models\User;
use App\Support\PanelIdentity;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasMaxWidth;
use Filament\Pages\Concerns\HasTopbar;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

/**
 * Post-296 audit, finding 7 — "Confirma tu identidad": a PIN session opening the panel gives the person's password (and
 * MFA code where enrolled) once per shift per tablet ({@see PanelIdentity}). The same checks as the login page: the
 * password against the account, the code through the panel's own MFA provider.
 *
 * @property-read Schema $form
 */
class ConfirmIdentity extends Page
{
    use HasMaxWidth, HasTopbar, WithRateLimiting;

    // A routed panel page (so it has a URL behind the panel's auth) drawn like the login: Filament's simple page shell.
    protected string $view = 'filament-panels::pages.simple';

    protected static string $layout = 'filament-panels::components.layout.simple';

    protected static ?string $slug = 'confirmar-identidad';

    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Prompt 310 — asked again because the cache could not be read, not because anything expired: say so. */
    public bool $cacheUnavailable = false;

    public function mount(): void
    {
        $this->cacheUnavailable = session('panel_identity.cache_unavailable') === true;

        // *Renovar* (prompt 310) comes here from a page with `?return=` — back to that page afterwards, this app's own only.
        $return = request()->query('return');
        $root = rtrim(url('/'), '/');
        if (is_string($return) && ($return === $root || str_starts_with($return, $root.'/'))) {
            session()->put('url.intended', $return);
        }

        $this->form->fill();
    }

    /** @return array<string, mixed> */
    protected function getLayoutData(): array
    {
        return [
            'hasTopbar' => $this->hasTopbar(),
            'maxContentWidth' => $maxContentWidth = $this->getMaxWidth() ?? $this->getMaxContentWidth(),
            'maxWidth' => $maxContentWidth,
        ];
    }

    public function hasLogo(): bool
    {
        return true;
    }

    public function getTitle(): string|Htmlable
    {
        return __('Confirma tu identidad');
    }

    public function getSubheading(): string|Htmlable|null
    {
        if ($this->cacheUnavailable) {
            return __('No se ha podido comprobar la confirmación (caché no disponible). Vuelve a introducir tu contraseña.');
        }

        return __('Has entrado con tu PIN. Para abrir la administración, escribe tu contraseña. Solo se pide una vez por turno.');
    }

    public function form(Schema $schema): Schema
    {
        $user = Auth::user();

        return $schema
            ->components([
                TextInput::make('password')
                    ->label(__('Contraseña'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule('current_password:web')
                    ->autocomplete('current-password'),
                ...($user instanceof User ? $this->multiFactorComponents($user) : []),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('confirm')
                ->footer([
                    Actions::make([Action::make('confirm')->label(__('Confirmar'))->submit('confirm')])->fullWidth(),
                    // The labelled way back (252): not confirming is fine — the counter itself needs only the PIN.
                    Actions::make([Action::make('backToCounter')->label('← '.__('Volver al mostrador'))->link()->color('gray')->url(route('counter.home'))])
                        ->alignCenter(),
                ]),
        ]);
    }

    public function confirm(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            Notification::make()->danger()
                ->title(__('Demasiados intentos. Espera :seconds segundos.', ['seconds' => $exception->secondsUntilAvailable]))
                ->send();

            return;
        }

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $this->form->getState(); // validates the password and, where enrolled, the MFA code

        PanelIdentity::confirmed($user);
        (new RecordAuditLog)->handle('counter.panel.identity_confirmed', $user, null, ['terminal_id' => session('counter.terminal_id')]);

        $this->redirect((string) session()->pull('url.intended', Filament::getUrl()), navigate: false);
    }

    /** @return array<Component> the enrolled MFA provider's own challenge fields */
    private function multiFactorComponents(User $user): array
    {
        foreach (Filament::getCurrentOrDefaultPanel()->getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider->isEnabled($user)) {
                return $provider->getChallengeFormComponents($user);
            }
        }

        return [];
    }
}
