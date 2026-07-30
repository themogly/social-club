<?php

namespace App\Filament\Resources\Members\Schemas;

use App\Enums\IdDocumentType;
use App\Support\MemberEligibility;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                                ->mapWithKeys(fn (IdDocumentType $type): array => [$type->value => $type->value])
                                ->all()),

                        TextInput::make('document_number')
                            ->label(__('Número de documento'))
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make(__('Membresía'))
                    ->schema([
                        Toggle::make('is_therapeutic')
                            ->label(__('Terapéutico')),

                        Select::make('avalador_member_id')
                            ->label(__('Avalador'))
                            ->relationship('avalador', 'member_no')
                            ->searchable()
                            ->preload(),

                        TextInput::make('declared_monthly_cg')
                            ->label(__('Previsión mensual (cg)'))
                            ->numeric()
                            ->minValue(0),
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
            ]);
    }
}
