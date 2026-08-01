<?php

namespace App\Filament\Resources\Convocatorias\Schemas;

use App\Enums\ConvocatoriaType;
use App\Models\Location;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The convocatoria form — the notice content only. The recipient roll, notice period and quorum are NOT
 * here: they are frozen by App\Actions\Governance\IssueConvocatoria at issue. This gathers the meeting the
 * club is convening; "Emitir" then fixes the roll and sends it.
 */
class ConvocatoriaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Asamblea'))
                ->schema([
                    Select::make('type')
                        ->label(__('Tipo de asamblea'))
                        ->options(collect(ConvocatoriaType::cases())->mapWithKeys(fn (ConvocatoriaType $t): array => [$t->value => $t->label()])->all())
                        ->default(ConvocatoriaType::ORDINARY->value)
                        ->required(),

                    TextInput::make('title')
                        ->label(__('Título'))
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    DateTimePicker::make('held_at')
                        ->label(__('Fecha y hora (primera convocatoria)'))
                        ->seconds(false)
                        ->required()
                        ->helperText(__('Debe respetar el plazo mínimo de convocatoria configurado.')),

                    DateTimePicker::make('second_call_at')
                        ->label(__('Segunda convocatoria'))
                        ->seconds(false)
                        ->helperText(__('Opcional.')),

                    TextInput::make('venue')
                        ->label(__('Lugar'))
                        ->maxLength(255),

                    Select::make('location_id')
                        ->label(__('Sede'))
                        ->placeholder(__('General (organización)'))
                        ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->helperText(__('Solo el lugar de celebración. La asamblea es de la asociación: se convoca a todos los socios.')),
                ])
                ->columns(2),

            Section::make(__('Orden del día'))
                ->schema([
                    Repeater::make('agenda')
                        ->label(__('Puntos del orden del día'))
                        ->hiddenLabel()
                        ->simple(
                            TextInput::make('punto')->label(__('Punto'))->required(),
                        )
                        ->addActionLabel(__('Añadir punto'))
                        ->defaultItems(0),
                ]),

            Section::make(__('Texto de la convocatoria'))
                ->schema([
                    Textarea::make('body')
                        ->label(__('Texto'))
                        ->hiddenLabel()
                        ->rows(6)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
