<?php

namespace App\Filament\Pages;

use App\Actions\Governance\DraftAssemblyMinute;
use App\Actions\Governance\RecordAttendance;
use App\Actions\Governance\RecordResolution;
use App\Enums\AttendanceMode;
use App\Enums\ResolutionResult;
use App\Filament\Resources\Minutes\MinuteResource;
use App\Models\AssemblyResolution;
use App\Models\Convocatoria;
use App\Models\Member;
use App\Models\User;
use App\Support\AssemblyQuorum;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The assembly, end to end (prompt 137). A convocatoria is CONVENED elsewhere (IssueConvocatoria freezes the
 * roll + notifies). This page runs the meeting itself: mark who attended (present or by proxy) against the
 * frozen roll, watch the quorum reach live, record each agenda item's show-of-hands outcome, then DRAFT the
 * acta from exactly what was recorded — never retyped. Drafting reuses CreateMinute; signing/filing is the
 * existing acta flow, immutable once signed. A thin shell: every write goes through a governance Action, so
 * the roll/quorum/immutability invariants live in one place, not in this screen.
 *
 * Gated on `minutes.manage` — the same governance authority that convenes and drafts.
 *
 * @property-read Convocatoria|null $convocatoria Livewire computed accessor (getConvocatoriaProperty).
 */
class Asamblea extends Page
{
    protected string $view = 'filament.pages.asamblea';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 26;

    public ?string $convocatoriaId = null;

    /** Transient per-member proxy-holder inputs, keyed by member id. */
    /** @var array<string, string> */
    public array $proxyHolder = [];

    /** Per-agenda-position resolution inputs, keyed by position. */
    /** @var array<int, string> */
    public array $resResult = [];

    /** @var array<int, int|string> */
    public array $resFor = [];

    /** @var array<int, int|string> */
    public array $resAgainst = [];

    /** @var array<int, int|string> */
    public array $resAbstain = [];

    public static function getNavigationLabel(): string
    {
        return __('Asamblea');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Documentos');
    }

    public function getTitle(): string
    {
        return __('Asamblea');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('minutes.manage') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->convocatoriaId ??= $this->openAssemblies()->first()?->id;
        $this->loadResolutionInputs();
    }

    public function updatedConvocatoriaId(): void
    {
        $this->proxyHolder = [];
        $this->loadResolutionInputs();
    }

    /**
     * Issued convocatorias not yet closed by a SIGNED acta — the assemblies still in play.
     *
     * @return Collection<int, Convocatoria>
     */
    public function openAssemblies(): Collection
    {
        return Convocatoria::query()
            ->whereNotNull('issued_at')
            ->whereDoesntHave('minutes', fn ($q) => $q->whereNotNull('signed_at'))
            ->orderByDesc('held_at')
            ->get();
    }

    public function getConvocatoriaProperty(): ?Convocatoria
    {
        if ($this->convocatoriaId === null) {
            return null;
        }

        return Convocatoria::query()->whereNotNull('issued_at')->find($this->convocatoriaId);
    }

    /**
     * The frozen roll, each row carrying the member's current attendance (if any).
     *
     * @return list<array{member_id: mixed, member_no: mixed, name: mixed, mode: AttendanceMode|null, proxy_holder: string|null}>
     */
    public function roll(): array
    {
        $convocatoria = $this->convocatoria;
        if ($convocatoria === null) {
            return [];
        }

        $attendance = $convocatoria->attendances()->get()->keyBy('member_id');

        return $convocatoria->recipients()
            ->whereNotNull('member_id')
            ->orderBy('member_no')
            ->get()
            ->map(fn ($recipient): array => [
                'member_id' => $recipient->member_id,
                'member_no' => $recipient->member_no,
                'name' => $recipient->name,
                'mode' => $attendance->get($recipient->member_id)?->mode,
                'proxy_holder' => $attendance->get($recipient->member_id)?->proxy_holder,
            ])
            ->all();
    }

    public function quorum(): ?AssemblyQuorum
    {
        $convocatoria = $this->convocatoria;

        return $convocatoria === null ? null : AssemblyQuorum::forConvocatoria($convocatoria);
    }

    /**
     * Each agenda point paired with its recorded resolution (if any).
     *
     * @return list<array{position: int, title: string, saved: AssemblyResolution|null}>
     */
    public function agendaItems(): array
    {
        $convocatoria = $this->convocatoria;
        if ($convocatoria === null) {
            return [];
        }

        $saved = $convocatoria->resolutions()->get()->keyBy('position');

        return collect(array_values($convocatoria->agenda ?? []))
            ->map(fn (string $punto, int $i): array => [
                'position' => $i + 1,
                'title' => $punto,
                'saved' => $saved->get($i + 1),
            ])
            ->all();
    }

    public function markPresent(string $memberId): void
    {
        $this->record($memberId, AttendanceMode::PRESENT, null);
    }

    public function markProxy(string $memberId): void
    {
        $this->record($memberId, AttendanceMode::PROXY, $this->proxyHolder[$memberId] ?? null);
    }

    private function record(string $memberId, AttendanceMode $mode, ?string $proxyHolder): void
    {
        $convocatoria = $this->convocatoria;
        $member = Member::query()->find($memberId);
        if ($convocatoria === null || $member === null) {
            return;
        }

        try {
            (new RecordAttendance)->handle($convocatoria, $member, $mode, $proxyHolder, $this->actor());
        } catch (Throwable $e) {
            Notification::make()->title(__('No se pudo registrar la asistencia'))->body($e->getMessage())->danger()->send();
        }
    }

    public function clearAttendance(string $memberId): void
    {
        $convocatoria = $this->convocatoria;
        $member = Member::query()->find($memberId);
        if ($convocatoria === null || $member === null) {
            return;
        }

        (new RecordAttendance)->remove($convocatoria, $member, $this->actor());
    }

    public function saveResolution(int $position, string $title): void
    {
        $convocatoria = $this->convocatoria;
        if ($convocatoria === null) {
            return;
        }

        try {
            (new RecordResolution)->handle(
                $convocatoria,
                $position,
                $title,
                ResolutionResult::tryFrom((string) ($this->resResult[$position] ?? '')) ?? ResolutionResult::APPROVED,
                (int) ($this->resFor[$position] ?? 0),
                (int) ($this->resAgainst[$position] ?? 0),
                (int) ($this->resAbstain[$position] ?? 0),
                $this->actor(),
            );
            Notification::make()->title(__('Acuerdo guardado'))->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title(__('No se pudo guardar el acuerdo'))->body($e->getMessage())->danger()->send();
        }
    }

    public function draftActa(): void
    {
        $convocatoria = $this->convocatoria;
        if ($convocatoria === null) {
            return;
        }

        try {
            $minute = (new DraftAssemblyMinute)->handle($convocatoria, $this->actor());
            Notification::make()->title(__('Acta redactada'))->body(__('Revísala y fírmala para archivarla.'))->success()->send();
            $this->redirect(MinuteResource::getUrl('view', ['record' => $minute]));
        } catch (Throwable $e) {
            Notification::make()->title(__('No se pudo redactar el acta'))->body($e->getMessage())->danger()->send();
        }
    }

    /** Prefill the resolution inputs from what is already recorded, so re-saving corrects rather than resets. */
    private function loadResolutionInputs(): void
    {
        $convocatoria = $this->convocatoria;
        if ($convocatoria === null) {
            return;
        }

        foreach ($convocatoria->resolutions()->get() as $resolution) {
            $this->resResult[$resolution->position] = $resolution->result->value;
            $this->resFor[$resolution->position] = $resolution->votes_for;
            $this->resAgainst[$resolution->position] = $resolution->votes_against;
            $this->resAbstain[$resolution->position] = $resolution->votes_abstain;
        }
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
