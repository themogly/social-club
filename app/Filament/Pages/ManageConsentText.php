<?php

namespace App\Filament\Pages;

use App\Actions\RecordAuditLog;
use App\Enums\SettingType;
use App\Support\ConsentText;
use App\Support\Settings;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Edit the two org-wide consent declarations — the privacy notice and the statutes summary — in BOTH languages,
 * versioned as a set (prompt 153). This is CLUB-AUTHORED legal content (the statutes summary describes THIS
 * association's actual rules), not product copy — which is why it is not `__()`, and why it lives behind its own
 * permission (`settings.consent`), apart from the routine thresholds of ManageSettings. Spanish is authoritative;
 * the English is a translation of the same version. Saving a CHANGED text REQUIRES bumping the version — a silent
 * edit would leave every already-consented member recorded against a version whose text no longer says what it
 * said, destroying the reproducibility consent records exist to provide (prompt 97).
 *
 * @property-read Schema $form
 */
class ManageConsentText extends Page
{
    protected string $view = 'filament.pages.manage-consent-text';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 31;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Textos de consentimiento');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sistema');
    }

    public function getTitle(): string
    {
        return __('Textos de consentimiento');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('settings.consent') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill($this->currentValues());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Versión'))
                    ->description(__('Un número de versión cubre AMBOS idiomas de AMBOS textos, así que el español y el inglés de una versión son, por construcción, la misma declaración. Si cambias cualquier texto, sube la versión: cada socio queda registrado contra la versión que leyó.'))
                    ->schema([
                        TextInput::make('consent_text_version')->label(__('Versión'))->required()->maxLength(16),
                    ]),
                Section::make(__('Información sobre el tratamiento de datos'))
                    ->description(__('El español es el texto auténtico; el inglés es una traducción de la misma versión.'))
                    ->schema([
                        Textarea::make('privacy_es')->label('Español')->rows(6)->required(),
                        Textarea::make('privacy_en')->label('English')->rows(6)->required(),
                    ])->columns(2),
                Section::make(__('Estatutos de la asociación'))
                    ->description(__('El español es el texto auténtico; el inglés es una traducción de la misma versión.'))
                    ->schema([
                        Textarea::make('statutes_es')->label('Español')->rows(6)->required(),
                        Textarea::make('statutes_en')->label('English')->rows(6)->required(),
                    ])->columns(2),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();  // validates (all four texts + version required)
        $before = $this->currentValues();

        $textsChanged = $state['privacy_es'] !== $before['privacy_es']
            || $state['privacy_en'] !== $before['privacy_en']
            || $state['statutes_es'] !== $before['statutes_es']
            || $state['statutes_en'] !== $before['statutes_en'];

        // A text change under the SAME version is refused: it would silently rewrite what already-consented
        // members are recorded as having read. Bumping the version is the whole point of consent_text_version.
        if ($textsChanged && (string) $state['consent_text_version'] === (string) $before['consent_text_version']) {
            Notification::make()
                ->title(__('Sube la versión'))
                ->body(__('Has cambiado un texto de consentimiento sin cambiar la versión. Sube el número de versión: los socios ya registrados quedaron vinculados a la versión anterior, y reutilizarla borraría lo que realmente aceptaron.'))
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Settings::set('consent_privacy_text', ['es' => $state['privacy_es'], 'en' => $state['privacy_en']], SettingType::JSON);
        Settings::set('consent_statutes_text', ['es' => $state['statutes_es'], 'en' => $state['statutes_en']], SettingType::JSON);
        Settings::set('consent_text_version', (string) $state['consent_text_version'], SettingType::STRING);

        (new RecordAuditLog)->handle('consent_text.updated', null, $before, $this->currentValues());

        Notification::make()->title(__('Textos de consentimiento guardados'))->success()->send();
    }

    /**
     * @return array<string, string>
     */
    private function currentValues(): array
    {
        $texts = ConsentText::editable();

        return [
            'consent_text_version' => ConsentText::version(),
            'privacy_es' => $texts['consent_privacy_text']['es'] ?? '',
            'privacy_en' => $texts['consent_privacy_text']['en'] ?? '',
            'statutes_es' => $texts['consent_statutes_text']['es'] ?? '',
            'statutes_en' => $texts['consent_statutes_text']['en'] ?? '',
        ];
    }
}
