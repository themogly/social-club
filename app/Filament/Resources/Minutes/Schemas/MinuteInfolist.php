<?php

namespace App\Filament\Resources\Minutes\Schemas;

use App\Enums\MinuteBook;
use App\Filament\Resources\Minutes\MinuteResource;
use App\Models\Member;
use App\Models\Minute;
use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MinuteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Convocatoria'))
                    ->schema([
                        TextEntry::make('book')
                            ->label(__('Libro'))
                            ->badge()
                            ->formatStateUsing(fn (MinuteBook $state): string => match ($state) {
                                MinuteBook::ASSEMBLY => __('Asamblea'),
                                MinuteBook::BOARD => __('Junta directiva'),
                            }),
                        TextEntry::make('number')->label(__('Nº de acta')),
                        TextEntry::make('type')
                            ->label(__('Tipo de reunión'))
                            ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : (MinuteResource::typeOptions()[$state] ?? $state)),
                        TextEntry::make('held_on')->label(__('Fecha de celebración'))->date(),
                        TextEntry::make('location.name')->label(__('Sede'))->placeholder(__('General (organización)')),
                        TextEntry::make('quorum')
                            ->label(__('Quórum (presente / requerido)'))
                            ->state(fn (Minute $record): string => $record->quorum_present.' / '.$record->quorum_required),
                        TextEntry::make('signed_at')
                            ->label(__('Estado'))
                            ->badge()
                            ->state(fn (Minute $record): string => $record->signed_at === null ? __('Borrador') : __('Firmada'))
                            ->color(fn (Minute $record): string => $record->signed_at === null ? 'warning' : 'success'),
                        TextEntry::make('signatory')
                            ->label(__('Firmante'))
                            ->state(fn (Minute $record): string => self::signatory($record))
                            // Only meaningful once signed; hidden on drafts.
                            ->visible(fn (Minute $record): bool => $record->signed_at !== null),
                    ])
                    ->columns(2),

                Section::make(__('Orden del día'))
                    ->schema([
                        TextEntry::make('agenda')
                            ->hiddenLabel()
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder(__('Sin puntos')),
                    ]),

                Section::make(__('Acuerdos'))
                    ->schema([
                        TextEntry::make('resolutions')
                            ->hiddenLabel()
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->state(fn (Minute $record): array => array_map(
                                fn (array $r): string => (string) ($r['texto'] ?? '')
                                    .(isset($r['resultado']) ? ' — '.$r['resultado'] : '')
                                    .' ('.__('A favor').' '.(int) ($r['favor'] ?? 0).' · '.__('En contra').' '.(int) ($r['contra'] ?? 0).' · '.__('Abstención').' '.(int) ($r['abstencion'] ?? 0).')',
                                array_values(array_filter((array) $record->resolutions, 'is_array')),
                            ))
                            ->placeholder(__('Sin acuerdos')),
                    ]),

                Section::make(__('Asistentes'))
                    ->schema([
                        TextEntry::make('attendees')
                            ->hiddenLabel()
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->state(fn (Minute $record): array => self::attendeeNames($record))
                            ->placeholder(__('Sin asistentes registrados')),
                    ]),

                Section::make(__('Desarrollo de la sesión'))
                    ->schema([
                        TextEntry::make('body')
                            ->hiddenLabel()
                            ->prose()
                            ->placeholder(__('Sin contenido')),
                    ]),
            ]);
    }

    /**
     * The signatory's name for a signed acta. Honest when unrecorded: an acta signed before signatories
     * were tracked (or whose signer's account was deleted) shows "no consta", never a fabricated name.
     */
    private static function signatory(Minute $record): string
    {
        if ($record->signed_at === null) {
            return '—';
        }

        // Not through the magic relation: the FK is nullable (historical/deleted signer), and it must
        // report "no consta" honestly rather than fabricate a name. value() models that null cleanly.
        $name = User::query()->whereKey($record->signed_by)->value('name');

        return is_string($name) ? $name : __('No consta');
    }

    /** @return list<string> */
    private static function attendeeNames(Minute $record): array
    {
        /** @var list<string> $ids */
        $ids = array_values(array_filter((array) $record->attendees, 'is_string'));
        if ($ids === []) {
            return [];
        }

        return Member::query()->whereIn('id', $ids)
            ->get(['id', 'member_no', 'first_name', 'last_name'])
            ->map(fn (Member $m): string => trim($m->member_no.' · '.$m->fullName()))
            ->all();
    }
}
