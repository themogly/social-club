<?php

namespace App\Filament\Resources\Minutes\Schemas;

use App\Enums\MinuteBook;
use App\Filament\Resources\Minutes\MinuteResource;
use App\Models\Location;
use App\Models\Member;
use App\Models\Minute;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The acta form. Numbering and quorum are NOT here — they are computed by
 * App\Actions\Documents\CreateMinute at creation. This form only gathers the human
 * content: which book, the meeting type/date/sede, the agenda + resolutions (with
 * votes) + attendees + body. When opened from "Corregir" it prefills supersedes_id
 * (and the book) from the acta being superseded.
 */
class MinuteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Convocatoria'))
                    ->description(__('El número de acta y el quórum se calculan automáticamente al registrarla.'))
                    ->schema([
                        Select::make('book')
                            ->label(__('Libro'))
                            ->options([
                                MinuteBook::ASSEMBLY->value => __('Libro de actas de asamblea'),
                                MinuteBook::BOARD->value => __('Libro de actas de junta directiva'),
                            ])
                            ->default(fn (): ?string => self::supersededBook())
                            ->required(),

                        Select::make('type')
                            ->label(__('Tipo de reunión'))
                            ->options(MinuteResource::typeOptions()),

                        DatePicker::make('held_on')
                            ->label(__('Fecha de celebración'))
                            ->default(now())
                            ->maxDate(now())
                            ->required(),

                        Select::make('location_id')
                            ->label(__('Sede'))
                            ->placeholder(__('General (organización)'))
                            ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all()),
                    ])
                    ->columns(2),

                Section::make(__('Orden del día'))
                    ->schema([
                        Repeater::make('agenda')
                            ->label(__('Puntos del orden del día'))
                            ->hiddenLabel()
                            ->simple(
                                TextInput::make('punto')
                                    ->label(__('Punto'))
                                    ->required(),
                            )
                            ->addActionLabel(__('Añadir punto'))
                            ->defaultItems(0),
                    ]),

                Section::make(__('Acuerdos'))
                    ->schema([
                        Repeater::make('resolutions')
                            ->label(__('Acuerdos y votación'))
                            ->hiddenLabel()
                            ->schema([
                                Textarea::make('texto')
                                    ->label(__('Acuerdo'))
                                    ->required()
                                    ->columnSpanFull(),
                                TextInput::make('favor')->label(__('A favor'))->numeric()->minValue(0)->default(0),
                                TextInput::make('contra')->label(__('En contra'))->numeric()->minValue(0)->default(0),
                                TextInput::make('abstencion')->label(__('Abstención'))->numeric()->minValue(0)->default(0),
                            ])
                            ->columns(3)
                            ->addActionLabel(__('Añadir acuerdo'))
                            ->defaultItems(0),
                    ]),

                Section::make(__('Asistentes y desarrollo'))
                    ->schema([
                        Select::make('attendees')
                            ->label(__('Asistentes'))
                            ->helperText(__('El quórum presente se calcula a partir de los asistentes seleccionados.'))
                            ->multiple()
                            ->searchable()
                            ->options(fn (): array => self::memberOptions()),

                        Textarea::make('body')
                            ->label(__('Desarrollo de la sesión'))
                            ->rows(8)
                            ->columnSpanFull(),

                        Hidden::make('supersedes_id')
                            ->default(fn (): ?string => self::supersededId()),
                    ]),
            ]);
    }

    /** @return array<string, string> member id → "Nº · Nombre" */
    private static function memberOptions(): array
    {
        return Member::query()->orderBy('member_no')
            ->get(['id', 'member_no', 'first_name', 'last_name'])
            ->mapWithKeys(fn (Member $m): array => [$m->id => trim($m->member_no.' · '.$m->fullName())])
            ->all();
    }

    /** The id of the acta being corrected, if this form was opened from "Corregir". */
    private static function supersededId(): ?string
    {
        $id = request()->query('supersedes');

        return is_string($id) ? $id : null;
    }

    /** The book of the acta being corrected, so a correction lands in the same book. */
    private static function supersededBook(): ?string
    {
        $id = self::supersededId();
        if ($id === null) {
            return null;
        }

        return Minute::query()->find($id)?->book->value;
    }
}
