<?php

namespace App\Filament\Pages;

use App\Enums\DispensationStatus;
use App\Filament\Pages\Reports\ReportPage;
use App\Models\Location;
use App\Support\Money;
use App\Support\Period;
use App\Support\Weight;
use App\ViewModels\Reports\AbstractReport;
use App\ViewModels\Reports\ConsumptionReport;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Registro de dispensación — the statutory dispensing control sheet: one chronological
 * line per withdrawal (fecha · nº socio · genética · lote · gramos · aportación ·
 * operador) for the selected period and sede. It REUSES the report-page shape (period
 * toggle, sede scope, exports) and App\ViewModels\Reports\ConsumptionReport for the
 * summary figures; the per-line rows are a live, scoped query (transactional data is
 * never cached). PDF is the formal printable. Gated on `reports.view` via ReportPage.
 */
class RegistroDispensacion extends ReportPage
{
    protected string $view = 'filament.pages.registro-dispensacion';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'documentos/registro-dispensacion';

    public static function getNavigationLabel(): string
    {
        return __('Registro de dispensación');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Documentos');
    }

    public function getHeading(): string
    {
        return __('Registro de dispensación');
    }

    public function getSubheading(): ?string
    {
        return __('Registro interno de la asociación. No constituye asesoramiento legal.');
    }

    protected function makeReport(string $organisationId, ?array $locationIds, Period $period): AbstractReport
    {
        return new ConsumptionReport($organisationId, $locationIds, $period);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $rows = $this->controlRows();

        return [
            'summary' => $this->report()->summary(),
            'rows' => $rows,
            'count' => count($rows),
            'periodKey' => $this->period,
            'customStart' => $this->customStart,
            'customEnd' => $this->customEnd,
            'scope' => $this->scope ?? $this->defaultScope(),
            'scopeOptions' => $this->scopeOptions(),
            'showScope' => count($this->scopeOptions()) > 1,
            'scopeLabel' => $this->scopeLabel(),
            'periodLabel' => $this->periodLabel(),
        ];
    }

    public function exportPdf(): StreamedResponse
    {
        $rows = $this->controlRows();
        $content = Pdf::loadView('documents.dispensing-record', [
            'rows' => $rows,
            'count' => count($rows),
            'summary' => $this->report()->summary(),
            'scopeLabel' => $this->scopeLabel(),
            'periodLabel' => $this->periodLabel(),
            'orgName' => config('app.name'),
            'generatedAt' => now(),
        ])->output();

        return response()->streamDownload(fn () => print ($content), $this->filename('pdf'), [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function exportCsv(): StreamedResponse
    {
        [$delimiter, $decimal] = app()->getLocale() === 'es' ? [';', ','] : [',', '.'];
        $number = fn (int $value, int $divideBy): string => number_format($value / $divideBy, 2, $decimal, '');

        $writer = Writer::createFromString();
        $writer->setDelimiter($delimiter);
        $writer->insertOne([__('Fecha'), __('Nº socio'), __('Genética'), __('Lote'), __('Gramos'), __('Aportación'), __('Operador')]);
        foreach ($this->controlRows() as $row) {
            $writer->insertOne([
                Carbon::parse((string) $row['fecha'])->format('d/m/Y H:i'),
                (string) $row['member_no'],
                (string) $row['genetica'],
                (string) $row['lote'],
                $number((int) $row['grams_cg'], 100),
                $number((int) $row['total_cents'], 100),
                (string) $row['operador'],
            ]);
        }

        $content = "\xEF\xBB\xBF".$writer->toString();

        return response()->streamDownload(fn () => print ($content), $this->filename('csv'), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * One row per dispensation line in the period/scope — the chronological control sheet.
     *
     * @return list<array<string, mixed>>
     */
    protected function controlRows(): array
    {
        [$start, $end] = $this->resolvePeriod()->bounds();

        return DB::table('dispensation_lines')
            ->join('dispensations', 'dispensation_lines.dispensation_id', '=', 'dispensations.id')
            ->join('members', 'dispensations.member_id', '=', 'members.id')
            ->leftJoin('users', 'dispensations.operator_id', '=', 'users.id')
            ->whereIn('dispensations.location_id', $this->scopeLocationIds())
            ->where('dispensations.status', DispensationStatus::COMPLETED->value)
            ->whereBetween('dispensations.dispensed_at', [$start, $end])
            ->orderBy('dispensations.dispensed_at')
            ->get([
                'dispensations.dispensed_at as fecha',
                'members.member_no as member_no',
                'dispensation_lines.genetic_name_snapshot as genetica',
                'dispensation_lines.batch_no_snapshot as lote',
                'dispensation_lines.grams_cg as grams_cg',
                'dispensation_lines.line_total_cents as total_cents',
                'users.name as operador',
            ])
            ->map(fn (\stdClass $r): array => [
                'fecha' => (string) $r->fecha,
                'member_no' => (string) ($r->member_no ?? '—'),
                'genetica' => (string) ($r->genetica ?? __('Sin genética')),
                'lote' => (string) ($r->lote ?? '—'),
                'grams_cg' => (int) $r->grams_cg,
                'grams' => Weight::fromCentigrams((int) $r->grams_cg)->formatted(),
                'total_cents' => (int) $r->total_cents,
                'total' => Money::fromCents((int) $r->total_cents)->formatted(),
                'operador' => (string) ($r->operador ?? '—'),
            ])
            ->all();
    }

    /**
     * The concrete location ids this render covers — the scope select resolved to ids
     * (null "All" expands to every org location). Cross-location access is refused by the
     * inherited resolveLocationIds() 403 checks.
     *
     * @return list<string>
     */
    protected function scopeLocationIds(): array
    {
        $ids = $this->resolveLocationIds();

        if ($ids !== null) {
            return $ids;
        }

        return Location::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->organisationId())->pluck('id')->all();
    }

    protected function filename(string $extension): string
    {
        $period = $this->resolvePeriod();
        $from = $period->start->format('Ymd');
        $to = $period->end->subDay()->format('Ymd');
        $range = $from === $to ? $from : "{$from}-{$to}";

        return "registro-dispensacion-{$range}.{$extension}";
    }
}
