<?php

namespace App\Filament\Resources\Members\Schemas;

use App\Actions\Members\IssueDocumentUrl;
use App\Enums\IdDocumentType;
use App\Enums\MemberDocumentType;
use App\Models\Member;
use App\Models\User;
use App\Support\DocumentVault;
use App\Support\MemberEligibility;
use App\Support\Settings;
use App\Support\Weight;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class MemberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Datos personales'))
                    ->schema([
                        TextInput::make('first_name')
                            ->label(__('Nombre'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('last_name')
                            ->label(__('Apellidos'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label(__('Correo electrónico'))
                            ->email()
                            ->maxLength(255),

                        TextInput::make('phone')
                            ->label(__('Teléfono'))
                            ->tel()
                            ->maxLength(255),

                        DatePicker::make('date_of_birth')
                            ->label(__('Fecha de nacimiento'))
                            ->required()
                            ->maxDate(now())
                            ->helperText(__('Edad mínima: :age años', ['age' => MemberEligibility::minimumAge()])),

                        TextInput::make('address')
                            ->label(__('Dirección'))
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make(__('Identificación'))
                    ->schema([
                        Select::make('document_type')
                            ->label(__('Tipo de documento'))
                            ->options(collect(IdDocumentType::cases())
                                ->mapWithKeys(fn (IdDocumentType $type): array => [$type->value => $type->label()])
                                ->all()),

                        TextInput::make('document_number')
                            ->label(__('Número de documento'))
                            ->maxLength(255),

                        FileUpload::make('document_scan_path')
                            ->label(__('Escaneo del documento'))
                            ->disk('documents')
                            ->visibility('private')
                            ->directory('member-id-scans')
                            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => DocumentVault::storeUpload($file, 'member-id-scans'))
                            ->acceptedFileTypes(['application/pdf', 'image/*'])
                            ->previewable(false)
                            ->helperText(__('PDF o imagen. Se guarda cifrado y solo se puede ver mediante un enlace firmado y registrado.'))
                            ->hintAction(self::viewScanAction('id', MemberDocumentType::ID))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('Membresía'))
                    ->schema([
                        Toggle::make('is_temporary')
                            ->label(__('Socio temporal'))
                            ->visible(fn (string $operation): bool => $operation === 'create'
                                && (bool) Settings::get('temporary_members_enabled', false))
                            ->helperText(__('Caduca automáticamente tras la ventana configurada. No relaja ninguna verificación (edad, aval, carencia, límites).')),

                        Toggle::make('is_therapeutic')
                            ->label(__('Terapéutico'))
                            ->helperText(__('Activa el certificado médico y exime del avalador según la política.'))
                            ->live(),

                        Select::make('avalador_member_id')
                            ->label(__('Avalador'))
                            ->relationship('avalador', 'member_no')
                            ->searchable()
                            ->preload()
                            ->required(fn (Get $get): bool => self::avaladorRequired((bool) $get('is_therapeutic')))
                            ->helperText(fn (Get $get): string => self::avaladorRequired((bool) $get('is_therapeutic'))
                                ? __('Obligatorio según la política de aval.')
                                : __('Opcional según la política de aval.')),

                        TextInput::make('declared_monthly_cg')
                            ->label(__('Previsión mensual (g)'))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->suffix(__('g'))
                            ->helperText(__('Previsión declarada por el socio, en gramos.'))
                            ->formatStateUsing(fn (?int $state): ?string => filled($state) ? number_format($state / 100, 2, '.', '') : null)
                            ->dehydrateStateUsing(fn ($state): ?int => filled($state) ? Weight::fromGrams((string) $state)->centigrams : null),

                        FileUpload::make('medical_cert_path')
                            ->label(__('Certificado médico'))
                            ->visible(fn (Get $get): bool => (bool) $get('is_therapeutic'))
                            ->disk('documents')
                            ->visibility('private')
                            ->directory('member-medical-certs')
                            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => DocumentVault::storeUpload($file, 'member-medical-certs'))
                            ->acceptedFileTypes(['application/pdf', 'image/*'])
                            ->previewable(false)
                            ->helperText(__('Solo socios terapéuticos. PDF o imagen; mismo tratamiento seguro que el escaneo del documento.'))
                            ->hintAction(self::viewScanAction('medical', MemberDocumentType::MEDICAL))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('Fotografía'))
                    ->schema([
                        FileUpload::make('photo_path')
                            ->label(__('Foto'))
                            ->image()
                            ->avatar()
                            ->imageEditor()
                            ->disk('documents')
                            ->directory('member-photos')
                            ->visibility('private'),
                    ]),

                Section::make(__('Declaraciones'))
                    ->description(__('Consentimiento y declaraciones legales del socio.'))
                    ->schema([
                        Checkbox::make('consent_given')
                            ->label(__('Consentimiento de tratamiento de datos (RGPD)'))
                            ->helperText(__('He informado al socio y obtenido su consentimiento (versión :version).', [
                                'version' => Settings::get('consent_text_version', '1.0'),
                            ]))
                            ->accepted()
                            ->dehydrated(false)
                            ->visibleOn('create'),

                        Checkbox::make('sole_association_declared_at')
                            ->label(__('Declaro no pertenecer a otra asociación cannábica'))
                            ->helperText(__('El socio declara pertenecer únicamente a esta asociación.'))
                            ->afterStateHydrated(fn (Checkbox $component, $state) => $component->state(filled($state)))
                            ->dehydrateStateUsing(function ($state, Checkbox $component) {
                                if (! $state) {
                                    return null;
                                }
                                // Keep the original declaration date on re-save; stamp now() the first time.
                                $record = $component->getRecord();

                                return ($record instanceof Member ? $record->sole_association_declared_at : null) ?? now();
                            }),
                    ]),
            ]);
    }

    /**
     * Is an avalador mandatory given the therapeutic flag and org policy?
     * Therapeutic members are exempt when `avalador_therapeutic_exempt` is on; otherwise
     * the `avalador_policy` decides — required always, waivable unless the current user
     * may waive (carencia.waive / manager), or not required at all.
     */
    protected static function avaladorRequired(bool $isTherapeutic): bool
    {
        if ($isTherapeutic && (bool) Settings::get('avalador_therapeutic_exempt', true)) {
            return false;
        }

        return match ((string) Settings::get('avalador_policy', 'required')) {
            'required' => true,
            'waivable' => ! (Auth::user()?->can('carencia.waive') ?? false),
            default => false,
        };
    }

    /**
     * "Ver documento" — open a sensitive scan through IssueDocumentUrl: a short-lived
     * signed URL that access-logs every view and 403s without `member.documents.view`.
     * The scan is NEVER served from a plain disk URL. Reuses the resource-wide pattern.
     */
    protected static function viewScanAction(string $key, MemberDocumentType $type): Action
    {
        return Action::make('view_'.$key.'_scan')
            ->label(__('Ver documento'))
            ->icon(Heroicon::OutlinedEye)
            ->visible(fn (?Member $record): bool => $record !== null
                && (Auth::user()?->can('member.documents.view') ?? false)
                && $record->documents()->where('type', $type->value)->exists())
            ->action(function (?Member $record, Component $livewire) use ($type): void {
                $document = $record?->documents()->where('type', $type->value)->latest()->first();
                if ($document === null) {
                    return;
                }

                /** @var User $actor */
                $actor = Auth::user();
                $url = (new IssueDocumentUrl)->handle($document, $actor);

                $livewire->js('window.open('.json_encode($url, JSON_THROW_ON_ERROR).", '_blank')");
            });
    }
}
