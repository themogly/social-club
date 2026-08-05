<?php

namespace App\Filament\Pages;

use App\Actions\Organisation\UpdateOrganisationIdentity;
use App\Models\Organisation;
use App\Support\ActiveScope;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * The one place a club edits WHO IT IS (prompt 159): the Organisation columns (trading name, legal name,
 * CIF/NIF, registered address, contact email + phone, logo) and the two per-locale consent declarations.
 * Distinct from ManageSettings (thresholds) because these are mostly Organisation COLUMNS, not Setting rows —
 * plus the consent texts, which ARE settings and carry the version-bump rule. All writes go through
 * UpdateOrganisationIdentity (the single writer, which enforces that rule, archives superseded consent text,
 * and audits before/after). Owner only (settings.manage): the data controller printed on statutory documents
 * and the text everyone consents to are not routine settings. `csc:install` still creates the org; this
 * corrects it afterwards.
 *
 * @property-read Schema $form
 */
class ManageOrganisationIdentity extends Page
{
    protected string $view = 'filament.pages.manage-organisation-identity';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 29;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Identidad del club');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sistema');
    }

    public function getTitle(): string
    {
        return __('Identidad del club');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('settings.manage') ?? false;
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
                Section::make(__('Datos del club'))
                    ->description(__('El nombre legal es el responsable del tratamiento que se imprime en los documentos estatutarios. Los textos de consentimiento se editan en «Textos de consentimiento».'))
                    ->schema([
                        TextInput::make('name')->label(__('Nombre comercial'))->maxLength(255)->required(),
                        TextInput::make('legal_name')->label(__('Nombre legal (asociación)'))->maxLength(255)
                            ->helperText(__('Cambiarlo NO reescribe los documentos ya emitidos; solo afecta a los nuevos. El cambio queda registrado.')),
                        TextInput::make('tax_id')->label(__('CIF / NIF'))->maxLength(64),
                        TextInput::make('contact_email')->label(__('Correo de contacto'))->email()->maxLength(255)
                            ->helperText(__('Se usa como Responder-a en los correos a los socios.')),
                        TextInput::make('contact_phone')->label(__('Teléfono'))->tel()->maxLength(64),
                        TextInput::make('address')->label(__('Domicilio social'))->maxLength(500)->columnSpanFull(),
                    ])->columns(2),

                Section::make(__('Logotipo'))
                    ->description(__('Aparece en el membrete de los correos y en los PDF. Si no hay logo, se muestra el nombre del club.'))
                    ->schema([
                        FileUpload::make('logo_path')
                            ->label(__('Logotipo'))
                            ->image()
                            ->disk('public')
                            ->directory('org-logos')
                            ->visibility('public')
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                            ->maxSize(1024) // 1 MB — a heavy logo in every email is a deliverability problem
                            ->imageResizeMode('contain')
                            ->imageResizeTargetWidth('512')
                            ->imageResizeTargetHeight('512')
                            ->helperText(__('PNG, JPG o WebP. Máx 1 MB. Se redimensiona a 512 px.')),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        app(UpdateOrganisationIdentity::class)->handle($this->organisation(), [
            'name' => $state['name'],
            'legal_name' => $this->nullable($state['legal_name'] ?? null),
            'tax_id' => $this->nullable($state['tax_id'] ?? null),
            'address' => $this->nullable($state['address'] ?? null),
            'contact_email' => $this->nullable($state['contact_email'] ?? null),
            'contact_phone' => $this->nullable($state['contact_phone'] ?? null),
            'logo_path' => $this->nullable($state['logo_path'] ?? null),
        ]);

        $this->form->fill($this->currentValues());

        Notification::make()->title(__('Identidad del club guardada'))->success()->send();
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        $organisation = $this->organisation();

        return [
            'name' => $organisation->name,
            'legal_name' => $organisation->legal_name,
            'tax_id' => $organisation->tax_id,
            'address' => $organisation->address,
            'contact_email' => $organisation->contact_email,
            'contact_phone' => $organisation->contact_phone,
            'logo_path' => $organisation->logo_path,
        ];
    }

    private function organisation(): Organisation
    {
        return Organisation::query()->whereKey(app(ActiveScope::class)->organisationId())->firstOrFail();
    }

    private function nullable(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value === '' ? null : $value;
    }
}
