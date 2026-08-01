<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GuardsStatutoryDocuments;
use App\Models\Location;
use App\Support\ActiveScope;
use App\Support\MembersRegister;
use App\Support\OrganisationIdentity;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Libro de socios — the statutory member register rendered "as at" any date (so a past
 * assembly can be evidenced against the roll that existed then). It WRAPS
 * App\Support\MembersRegister::asAt (which keeps leavers and excludes later joiners) and
 * adds the "as at" control, an optional sede filter, a count line and PDF + CSV export.
 * Gated on `register.view`.
 */
class LibroSocios extends Page
{
    use GuardsStatutoryDocuments;

    protected string $view = 'filament.pages.libro-socios';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 10;

    /** ISO date the register is rendered "as at". */
    public ?string $asAt = null;

    /** 'all' or a location id — filters the roll by the member's current sede. */
    public string $sede = 'all';

    public static function getNavigationLabel(): string
    {
        return __('Libro de socios');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Documentos');
    }

    public function getTitle(): string
    {
        return __('Libro de socios');
    }

    public function getSubheading(): ?string
    {
        return __('Registro interno de la asociación. No constituye asesoramiento legal.');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('register.view') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->asAt ??= now()->toDateString();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rows = $this->rows();

        return [
            'rows' => $rows,
            'count' => count($rows),
            'asAt' => $this->asAtDate(),
            'sedeOptions' => $this->sedeOptions(),
            'sedeLabel' => $this->sedeLabel(),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        $delimiter = app()->getLocale() === 'es' ? ';' : ',';

        $writer = Writer::createFromString();
        $writer->setDelimiter($delimiter);
        $writer->insertOne([__('Nº socio'), __('Nombre'), __('Documento'), __('Alta'), __('Baja'), __('Estado'), __('Sede')]);
        foreach ($this->rows() as $row) {
            $writer->insertOne([
                (string) ($row['member_no'] ?? ''),
                (string) ($row['nombre'] ?? ''),
                (string) ($row['documento'] ?? ''),
                (string) ($row['alta'] ?? ''),
                (string) ($row['baja'] ?? ''),
                (string) ($row['estado'] ?? ''),
                (string) ($row['sede'] ?? ''),
            ]);
        }

        $content = "\xEF\xBB\xBF".$writer->toString();
        $filename = 'libro-socios-'.$this->asAtDate().'.csv';

        return response()->streamDownload(fn () => print ($content), $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function exportPdf(): ?StreamedResponse
    {
        if (! self::hasStatutoryIdentity()) {
            return null;
        }

        $identity = OrganisationIdentity::current();
        $rows = $this->rows();
        $content = Pdf::loadView('documents.register', [
            'rows' => $rows,
            'count' => count($rows),
            'asAt' => $this->asAtDate(),
            'sedeLabel' => $this->sedeLabel(),
            'orgName' => $identity['display_name'],
            'identity' => $identity,
            'generatedAt' => now(),
        ])->output();

        $filename = 'libro-socios-'.$this->asAtDate().'.pdf';

        return response()->streamDownload(fn () => print ($content), $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * The register rows "as at" the selected date, optionally narrowed to a sede.
     *
     * @return list<array<string, mixed>>
     */
    protected function rows(): array
    {
        $rows = MembersRegister::asAt($this->organisationId(), $this->asAtDate());

        if ($this->sede !== 'all') {
            $name = $this->sedeOptions()[$this->sede] ?? null;
            $rows = array_values(array_filter($rows, fn (array $r): bool => ($r['sede'] ?? null) === $name));
        }

        return $rows;
    }

    protected function asAtDate(): string
    {
        return Carbon::parse($this->asAt ?? now()->toDateString())->toDateString();
    }

    protected function organisationId(): string
    {
        return (string) app(ActiveScope::class)->organisationId();
    }

    /**
     * @return array<string, string>
     */
    protected function sedeOptions(): array
    {
        return Location::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->organisationId())
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    protected function sedeLabel(): string
    {
        if ($this->sede === 'all') {
            return __('Todas las sedes');
        }

        return $this->sedeOptions()[$this->sede] ?? __('Sede');
    }
}
