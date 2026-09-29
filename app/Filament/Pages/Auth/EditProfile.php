<?php

namespace App\Filament\Pages\Auth;

use App\Actions\Alerts\IssueTelegramLink;
use App\Actions\RecordAuditLog;
use App\Actions\ResolveLocale;
use App\Enums\AlertType;
use App\Models\User;
use App\Support\LocationSwitcher;
use App\Support\Qr;
use App\Support\Telegram;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Prompt 311 — the profile gains *Avisos*: for someone holding `alerts.receive`, which channels (Telegram and/or the
 * morning email), which of the six alert types, and which of their own sedes; plus *Conectar Telegram* (a one-time link as
 * a button and a QR code) and *Desconectar*. With no bot configured the Telegram option is simply absent.
 */
class EditProfile extends BaseEditProfile
{
    /** @var array<string, mixed> */
    public array $alerts = [];

    public function mount(): void
    {
        parent::mount();

        $user = $this->alertsUser();
        if ($user !== null) {
            $this->alerts = [
                'channels' => $user->alertChannels(),
                'types' => $user->alertTypes(),
                'location_ids' => $user->alertLocationIds() ?? array_keys($this->sedeOptions($user)),
            ];
        }
    }

    /** The profile's own fields, plus *Idioma* (prompt 315) — the same value as the top-bar ES/EN switch. */
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            Select::make('locale')
                ->label(__('Idioma'))
                ->options(fn (): array => (new ResolveLocale)->options())
                ->required()
                ->selectablePlaceholder(false)
                ->helperText(__('Idioma del panel, del mostrador, de tus correos y de tus avisos de Telegram.')),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }

    /** Saved → the panel switches at once, exactly as the top-bar switch does (the session carries it to the next request). */
    protected function afterSave(): void
    {
        $locale = Auth::user()?->getAttribute('locale');
        if (is_string($locale) && $locale !== session('locale')) {
            session(['locale' => $locale]);
            $this->redirect(static::getUrl(), navigate: false);
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
            ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
            ...Arr::wrap($this->alertsSection()),
        ]);
    }

    private function alertsSection(): ?Section
    {
        $user = $this->alertsUser();
        if ($user === null) {
            return null;
        }

        return Section::make(__('Avisos'))
            ->description(__('Stock bajo, lotes que caducan, una caja abierta demasiado tiempo y problemas del sistema. Solo nombres de productos, cantidades, sedes y horas: nunca datos de socios.'))
            ->statePath('alerts')
            ->schema([
                CheckboxList::make('channels')->label(__('Cómo'))
                    ->options(array_filter([
                        'telegram' => Telegram::configured() ? __('Telegram, en el momento') : null,
                        'email' => __('Correo de la mañana (08:00) con lo que siga activo'),
                    ])),
                CheckboxList::make('types')->label(__('Qué'))
                    ->options(collect(AlertType::cases())->mapWithKeys(fn (AlertType $type): array => [$type->value => $type->label()])->all())
                    ->columns(2),
                CheckboxList::make('location_ids')->label(__('Sedes'))->options($this->sedeOptions($user))->columns(2),
                Text::make(fn (): string => $this->alertsUser()?->telegram_chat_id !== null ? __('Telegram conectado.') : __('Telegram sin conectar.'))
                    ->visible(Telegram::configured()),
                Actions::make([$this->saveAlertsAction(), $this->connectTelegramAction(), $this->disconnectTelegramAction()]),
            ]);
    }

    public function saveAlertsAction(): Action
    {
        return Action::make('saveAlerts')->label(__('Guardar avisos'))->action(fn () => $this->saveAlerts());
    }

    public function connectTelegramAction(): Action
    {
        return Action::make('connectTelegram')->label(__('Conectar Telegram'))->color('gray')
            ->visible(fn (): bool => Telegram::configured() && $this->alertsUser()?->telegram_chat_id === null)
            ->modalHeading(__('Conectar Telegram'))
            ->modalContent(fn (): HtmlString => $this->connectContent())
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Cerrar'));
    }

    public function disconnectTelegramAction(): Action
    {
        return Action::make('disconnectTelegram')->label(__('Desconectar Telegram'))->color('gray')
            ->visible(fn (): bool => Telegram::configured() && $this->alertsUser()?->telegram_chat_id !== null)
            ->requiresConfirmation()
            ->action(function (): void {
                $user = $this->alertsUser();
                abort_unless($user !== null, 403);
                $user->unlinkTelegram();
                (new RecordAuditLog)->handle('alert.telegram_unlinked', $user);
                Notification::make()->success()->title(__('Telegram desconectado'))->send();
            });
    }

    public function saveAlerts(): void
    {
        $user = $this->alertsUser();
        abort_unless($user !== null, 403);

        $mine = array_keys($this->sedeOptions($user));
        $user->forceFill(['alert_preferences' => [
            'channels' => array_values(array_intersect(['telegram', 'email'], (array) ($this->alerts['channels'] ?? []))),
            'types' => array_values(array_intersect(array_map(fn (AlertType $t): string => $t->value, AlertType::cases()), (array) ($this->alerts['types'] ?? []))),
            // Only their own sedes; all of them is stored as "all" (null), so a sede added later is included.
            'location_ids' => ($ids = array_values(array_intersect($mine, (array) ($this->alerts['location_ids'] ?? [])))) === $mine ? null : $ids,
        ]])->save();

        Notification::make()->success()->title(__('Avisos guardados'))->send();
    }

    private function connectContent(): HtmlString
    {
        $user = $this->alertsUser();
        abort_unless($user !== null, 403);
        $url = (new IssueTelegramLink)->handle($user);

        return new HtmlString(view('filament.telegram-connect', [
            'url' => $url,
            'qr' => 'data:image/png;base64,'.base64_encode(Qr::png($url, 5)),
        ])->render());
    }

    /** @return array<string, string> the person's own sedes (and stores), by id */
    private function sedeOptions(User $user): array
    {
        return app(LocationSwitcher::class)->available($user, includeStores: true)->pluck('name', 'id')->all();
    }

    private function alertsUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('alerts.receive') ? $user->fresh() : null;
    }
}
