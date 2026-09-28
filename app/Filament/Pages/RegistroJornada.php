<?php

namespace App\Filament\Pages;

use App\Actions\Staff\AnnulClockEvent;
use App\Actions\Staff\ClockIn;
use App\Actions\Staff\ClockOut;
use App\Enums\Role;
use App\Enums\StaffClockSource;
use App\Enums\StaffClockType;
use App\Filament\Concerns\GuardsStatutoryDocuments;
use App\Models\Location;
use App\Models\StaffClockEvent;
use App\Models\User;
use App\Support\OrganisationIdentity;
use App\Support\WorkedHours;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use League\Csv\Writer;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Registro de jornada (prompt 281, Ben's 280) — everyone's clocked hours at the sedes you may see (`staff.hours.view`),
 * per person and day, with flags, corrections (`staff.hours.manage`), a CSV and a printable monthly sheet per person.
 * Reads ONLY through `WorkedHours` (one pairing rule). A correction is always a NEW row; nothing is edited.
 *
 * Hours only — no pay, rates or compensation: whoever calculates that works from the export, and keeping money about
 * staff out of the system keeps a category of personal financial data out of the RAT.
 */
class RegistroJornada extends Page
{
    use GuardsStatutoryDocuments;

    protected string $view = 'filament.pages.registro-jornada';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $slug = 'registro-de-jornada';

    // The person and month ride in the URL (prompt 285) so the "Horas del personal" report can link a name straight to
    // that person's month here. Visibility is still the page's own: a person outside the viewer's sedes shows nothing.
    #[Url]
    public ?string $personId = null;

    public ?string $locationId = null;

    #[Url]
    public string $month = '';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('staff.hours.view');
    }

    public static function getNavigationLabel(): string
    {
        return __('Registro de jornada');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sistema');
    }

    public function getTitle(): string
    {
        return __('Registro de jornada');
    }

    public function mount(): void
    {
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) === 1 ? $this->month : now()->format('Y-m');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->addPeriodAction(),
            Action::make('csv')->label(__('Exportar CSV'))->color('gray')->action(fn (): StreamedResponse => $this->exportCsv()),
            Action::make('sheets')->label(__('Hoja mensual (PDF)'))->color('gray')->action(fn (): ?StreamedResponse => $this->exportSheets()),
        ];
    }

    /** @return list<string> the sedes this page shows: those the viewer may see, narrowed by the filter */
    private function locationIds(): array
    {
        $user = Auth::user();
        $visible = $user instanceof User ? WorkedHours::viewableLocationIds($user) : [];

        return $this->locationId !== null ? array_values(array_intersect($visible, [$this->locationId])) : $visible;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function window(): array
    {
        $from = CarbonImmutable::createFromFormat('Y-m-d', ($this->month ?: now()->format('Y-m')).'-01') ?: now()->toImmutable()->startOfMonth();

        return [$from->startOfDay(), $from->startOfDay()->addMonth()];
    }

    /**
     * Post-296 audit (P3-3) — protected: the page's view reads it, the browser cannot call it (it returned raw models,
     * staff e-mails included). The report: one row per period, per annulled event (struck through) and per day of unclocked activity — in a
     * bounded number of queries, whatever the number of rows.
     *
     * @return list<array{kind: string, user_id: string, name: string, business_date: string, location: string, in: ?StaffClockEvent, out: ?StaffClockEvent, minutes: ?int, flags: list<string>}>
     */
    protected function reportRows(): array
    {
        $locationIds = $this->locationIds();
        if ($locationIds === []) {
            return [];
        }

        [$from, $to] = $this->window();
        $people = $this->personId !== null ? [$this->personId] : null;
        $periods = WorkedHours::periods($people, $locationIds, $from, $to);
        $annulled = WorkedHours::annulled($people, $locationIds, $from, $to);
        $unclocked = array_values(array_filter(WorkedHours::unclockedActivity($locationIds, $from, $to, $periods),
            fn (array $u): bool => $people === null || in_array($u['user_id'], $people, true)));

        $userIds = array_unique(array_merge(array_column($periods, 'user_id'), $annulled->pluck('user_id')->all(), array_column($unclocked, 'user_id')));
        $names = User::withTrashed()->whereIn('id', $userIds)->pluck('name', 'id');
        $sedes = Location::query()->withoutGlobalScopes()->whereIn('id', $locationIds)->pluck('name', 'id');

        $rows = [];
        foreach ($periods as $p) {
            $rows[] = ['kind' => 'period', 'user_id' => $p['user_id'], 'name' => (string) ($names[$p['user_id']] ?? '—'), 'business_date' => $p['business_date'],
                'location' => (string) ($sedes[$p['location_id']] ?? '—'), 'in' => $p['in'], 'out' => $p['out'], 'minutes' => $p['minutes'], 'flags' => $p['flags']];
        }
        foreach ($annulled as $event) {
            $rows[] = ['kind' => 'annulled', 'user_id' => (string) $event->user_id, 'name' => (string) ($names[$event->user_id] ?? '—'), 'business_date' => $event->business_date->toDateString(),
                'location' => (string) ($sedes[$event->location_id] ?? '—'), 'in' => $event->type === StaffClockType::IN ? $event : null,
                'out' => $event->type === StaffClockType::OUT ? $event : null, 'minutes' => null, 'flags' => [__('Anulado')]];
        }
        foreach ($unclocked as $u) {
            $rows[] = ['kind' => 'unclocked', 'user_id' => $u['user_id'], 'name' => (string) ($names[$u['user_id']] ?? '—'), 'business_date' => $u['business_date'],
                'location' => (string) ($sedes[$u['location_id']] ?? '—'), 'in' => null, 'out' => null, 'minutes' => null, 'flags' => [__('Actividad sin fichar')]];
        }

        usort($rows, fn (array $a, array $b): int => [$a['name'], $a['business_date']] <=> [$b['name'], $b['business_date']]);

        return $rows;
    }

    /** @return array<string, string> the people with hours at the sedes this viewer may see */
    protected function peopleOptions(): array
    {
        $ids = StaffClockEvent::query()->withoutGlobalScopes()->whereIn('location_id', $this->locationIds() ?: ['-'])->distinct()->pluck('user_id');

        return User::withTrashed()->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> */
    protected function sedeOptions(): array
    {
        $user = Auth::user();

        return Location::query()->withoutGlobalScopes()->whereIn('id', $user instanceof User ? WorkedHours::viewableLocationIds($user) : [])->orderBy('name')->pluck('name', 'id')->all();
    }

    protected function canManage(): bool
    {
        return Auth::user()?->can('staff.hours.manage') ?? false;
    }

    /** Añadir jornada — a missing period (entrada + salida), both marked as a correction, with a reason. */
    public function addPeriodAction(): Action
    {
        return Action::make('addPeriod')
            ->label(__('Añadir jornada'))
            ->visible(fn (): bool => $this->canManage())
            ->schema([
                Select::make('user_id')->label(__('Persona'))->options(fn (): array => $this->staffOptions())->required()->searchable(),
                Select::make('location_id')->label(__('Sede'))->options(fn (): array => $this->sedeOptions())->required(),
                DateTimePicker::make('in_at')->label(__('Entrada'))->seconds(false)->required(),
                DateTimePicker::make('out_at')->label(__('Salida'))->seconds(false)->required()->after('in_at'),
                TextInput::make('reason')->label(__('Motivo'))->required()->maxLength(200),
            ])
            ->action(function (array $data): void {
                $this->correct(function () use ($data): void {
                    $actor = $this->actor();
                    $user = User::query()->findOrFail($data['user_id']);
                    $location = Location::query()->withoutGlobalScopes()->findOrFail($data['location_id']);
                    (new ClockIn)->handle($user, $location, $actor, StaffClockSource::MANAGER_CORRECTION, CarbonImmutable::parse($data['in_at']), $data['reason']);
                    (new ClockOut)->handle($user, $actor, StaffClockSource::MANAGER_CORRECTION, CarbonImmutable::parse($data['out_at']), $data['reason']);
                });
            });
    }

    /** Añadir salida — end a period left open, at a stated time, with a reason. */
    public function addOutAction(): Action
    {
        return Action::make('addOut')
            ->label(__('Añadir salida'))
            ->visible(fn (): bool => $this->canManage())
            ->modalDescription(fn (array $arguments): string => $this->originalLine($arguments['event'] ?? null))
            ->schema([
                DateTimePicker::make('out_at')->label(__('Salida'))->seconds(false)->required(),
                TextInput::make('reason')->label(__('Motivo'))->required()->maxLength(200),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->correct(function () use ($data, $arguments): void {
                    $in = StaffClockEvent::query()->withoutGlobalScopes()->findOrFail($arguments['event'] ?? null);
                    (new ClockOut)->handle(User::withTrashed()->findOrFail($in->user_id), $this->actor(), StaffClockSource::MANAGER_CORRECTION, CarbonImmutable::parse($data['out_at']), $data['reason']);
                });
            });
    }

    /** Anular — a new ANNUL row; the original stays, struck through. */
    public function annulAction(): Action
    {
        return Action::make('annul')
            ->label(__('Anular'))
            ->color('danger')
            ->visible(fn (): bool => $this->canManage())
            ->modalDescription(fn (array $arguments): string => $this->originalLine($arguments['event'] ?? null))
            ->schema([TextInput::make('reason')->label(__('Motivo'))->required()->maxLength(200)])
            ->action(function (array $data, array $arguments): void {
                $this->correct(function () use ($data, $arguments): void {
                    (new AnnulClockEvent)->handle(StaffClockEvent::query()->withoutGlobalScopes()->findOrFail($arguments['event'] ?? null), $this->actor(), $data['reason']);
                });
            });
    }

    public function exportCsv(): StreamedResponse
    {
        $writer = Writer::createFromString();
        $writer->setDelimiter(app()->getLocale() === 'es' ? ';' : ',');
        $writer->insertOne([__('Persona'), __('Fecha'), __('Sede'), __('Entrada'), __('Salida'), __('Total (min)'), __('Avisos')]);
        foreach ($this->reportRows() as $row) {
            $writer->insertOne($this->exportLine($row));
        }
        $content = "\u{FEFF}".$writer->toString();

        return response()->streamDownload(fn () => print ($content), 'registro-jornada-'.$this->month.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The printable monthly sheet per person — what a gestor asks for: the club, the person, each day, totals, a signature line. */
    public function exportSheets(): ?StreamedResponse
    {
        if (! self::hasStatutoryIdentity()) {
            return null;
        }

        $byPerson = collect($this->reportRows())->groupBy('user_id')->map(fn ($rows) => [
            'name' => $rows->first()['name'],
            'rows' => $rows->map(fn (array $row): array => $this->exportLine($row))->all(),
            'minutes' => (int) $rows->sum(fn (array $row): int => $row['kind'] === 'period' ? (int) $row['minutes'] : 0),
        ])->values()->all();

        $content = Pdf::loadView('documents.registro-jornada', [
            'identity' => OrganisationIdentity::current(),
            'people' => $byPerson,
            'month' => CarbonImmutable::createFromFormat('Y-m', $this->month)?->translatedFormat('F Y') ?? $this->month,
            'generatedAt' => now(),
        ])->output();

        return response()->streamDownload(fn () => print ($content), 'registro-jornada-'.$this->month.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    /**
     * @param  array{kind: string, name: string, business_date: string, location: string, in: ?StaffClockEvent, out: ?StaffClockEvent, minutes: ?int, flags: list<string>}  $row
     * @return list<string>
     */
    private function exportLine(array $row): array
    {
        return [
            $row['name'], $row['business_date'], $row['location'],
            $row['in'] !== null ? local_datetime($row['in']->occurred_at, 'H:i', $row['in']->location) : '',
            $row['out'] !== null ? local_datetime($row['out']->occurred_at, 'H:i', $row['out']->location) : '',
            $row['minutes'] !== null ? (string) $row['minutes'] : '',
            implode(' · ', $row['flags']),
        ];
    }

    /** @return array<string, string> the people a manager may add hours for: staff assigned to the sedes they see */
    private function staffOptions(): array
    {
        // Post-296 audit (P3-4) — a responsable is not offered their own hours to correct (the owner is).
        $actor = $this->actor();

        return User::query()->whereHas('locations', fn ($q) => $q->whereIn('locations.id', $this->locationIds() ?: ['-']))
            ->when(! $actor->hasRole(Role::OWNER->value), fn ($q) => $q->whereKeyNot($actor->getKey()))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    private function originalLine(mixed $eventId): string
    {
        $event = is_string($eventId) ? StaffClockEvent::query()->withoutGlobalScopes()->find($eventId) : null;

        return $event === null ? '' : __('Original: :type :time (:source)', [
            'type' => $event->type->label(), 'time' => local_datetime($event->occurred_at, 'd/m/Y H:i', $event->location), 'source' => $event->source->label(),
        ]);
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function correct(callable $write): void
    {
        try {
            $write();
            Notification::make()->title(__('Corrección registrada'))->success()->send();
        } catch (DomainException|InvalidArgumentException|AuthorizationException $e) {
            Notification::make()->title(__('No se pudo corregir'))->body($e->getMessage())->danger()->send();
        }
    }
}
