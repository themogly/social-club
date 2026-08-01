<?php

namespace App\Filament\Resources\Minutes;

use App\Actions\Documents\SignMinute;
use App\Enums\MinuteBook;
use App\Filament\Concerns\GuardsStatutoryDocuments;
use App\Filament\Resources\Minutes\Pages\CreateMinute;
use App\Filament\Resources\Minutes\Pages\EditMinute;
use App\Filament\Resources\Minutes\Pages\ListMinutes;
use App\Filament\Resources\Minutes\Pages\ViewMinute;
use App\Filament\Resources\Minutes\Schemas\MinuteForm;
use App\Filament\Resources\Minutes\Schemas\MinuteInfolist;
use App\Filament\Resources\Minutes\Tables\MinutesTable;
use App\Models\Member;
use App\Models\Minute;
use App\Models\User;
use App\Support\OrganisationIdentity;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Actas — the club's minute books (asambleas + junta directiva). This resource only
 * WRAPS the domain: creation routes through App\Actions\Documents\CreateMinute (which
 * owns sequential per-book numbering + quorum), signing through
 * App\Actions\Documents\SignMinute (immutable afterwards). Every acta is a formal PDF
 * with signature blocks. Gated by MinutePolicy on `minutes.manage`.
 */
class MinuteResource extends Resource
{
    use GuardsStatutoryDocuments;

    protected static ?string $model = Minute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('Actas');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Documentos');
    }

    public static function getModelLabel(): string
    {
        return __('acta');
    }

    public static function getPluralModelLabel(): string
    {
        return __('actas');
    }

    /**
     * The meeting types offered on the form (stored as a plain string on the acta).
     *
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            'asamblea_general' => __('Asamblea general'),
            'junta_directiva' => __('Junta directiva'),
            'extraordinaria' => __('Extraordinaria'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return MinuteForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MinuteInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MinutesTable::configure($table);
    }

    // --- Shared record actions (reused by the table rows and the view page) ----------

    /** Firmar — close the acta through SignMinute. Immutable afterwards; only while unsigned. */
    public static function signAction(): Action
    {
        return Action::make('sign')
            ->label(__('Firmar'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('Firmar acta'))
            ->modalDescription(__('Una vez firmada, el acta queda inmutable: no podrá editarse ni eliminarse. Se registrará su nombre como firmante. Una corrección se registra como un acta nueva vinculada.'))
            ->visible(fn (Minute $record): bool => $record->signed_at === null && (Auth::user()?->can('minute.sign') ?? false))
            ->action(function (Minute $record): void {
                /** @var User $actor */
                $actor = Auth::user();
                (new SignMinute)->handle($record, $actor);

                Notification::make()->title(__('Acta firmada'))->success()->send();
            });
    }

    /** Corregir — open a new acta prefilled with supersedes_id pointing back at this one. */
    public static function correctAction(): Action
    {
        return Action::make('correct')
            ->label(__('Corregir'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Minute $record): bool => $record->signed_at !== null && (Auth::user()?->can('minutes.manage') ?? false))
            ->url(fn (Minute $record): string => CreateMinute::getUrl(['supersedes' => $record->id]));
    }

    /** Exportar PDF — a formal printable with quorum line, resolutions and signature blocks. */
    public static function pdfAction(): Action
    {
        return Action::make('pdf')
            ->label(__('PDF'))
            ->icon(Heroicon::OutlinedDocumentArrowDown)
            ->color('gray')
            ->action(function (Minute $record): ?StreamedResponse {
                if (! self::hasStatutoryIdentity()) {
                    return null;
                }

                $content = Pdf::loadView('documents.minute', self::pdfData($record))->output();
                $filename = 'acta-'.$record->book->value.'-'.$record->number.'.pdf';

                return response()->streamDownload(fn () => print ($content), $filename, [
                    'Content-Type' => 'application/pdf',
                ]);
            });
    }

    /**
     * The data an acta PDF renders — the record, the meeting-type label and the
     * attendee names resolved from the stored member ids (org-wide lookup).
     *
     * @return array<string, mixed>
     */
    public static function pdfData(Minute $record): array
    {
        /** @var list<string> $attendeeIds */
        $attendeeIds = array_values(array_filter((array) $record->attendees, 'is_string'));

        $names = $attendeeIds === []
            ? []
            : Member::query()->whereIn('id', $attendeeIds)
                ->get(['id', 'member_no', 'first_name', 'last_name'])
                ->map(fn (Member $m): string => trim($m->member_no.' · '.$m->fullName()))
                ->all();

        return [
            'minute' => $record,
            'bookLabel' => self::bookLabel($record->book),
            'typeLabel' => self::typeOptions()[$record->type] ?? $record->type,
            'attendeeNames' => $names,
            'identity' => OrganisationIdentity::current(),
        ];
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMinutes::route('/'),
            'create' => CreateMinute::route('/create'),
            'view' => ViewMinute::route('/{record}'),
            'edit' => EditMinute::route('/{record}/edit'),
        ];
    }

    public static function bookLabel(MinuteBook $book): string
    {
        return match ($book) {
            MinuteBook::ASSEMBLY => __('Libro de actas de asamblea'),
            MinuteBook::BOARD => __('Libro de actas de junta directiva'),
        };
    }
}
