<?php

namespace App\Filament\Pages\Reports;

use App\Support\Money;
use App\Support\Period;
use App\Support\Spreadsheet\ReportExport;
use App\ViewModels\Reports\AbstractReport;
use App\ViewModels\Reports\LossesReport;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Prompt 367 — Informes → Pérdidas: everything that cost the club money, who and why. `reports.view` (the ReportPage default):
 * a manager sees their sedes, the owner the rollup, STAFF never. The period, the sede and the detail's filters ride in the
 * URL, so every figure can link to its list and the morning line can open «ayer» (`?period=yesterday`).
 */
class LossesReportPage extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingDown;

    protected static ?int $navigationSort = 26;

    protected static ?string $slug = 'informes/perdidas';

    /** The detail's section (mostrador | peso | existencias | caja | devoluciones), or null for all. */
    public ?string $section = null;

    /** The detail's person (a user id), or null for everyone. */
    public ?string $person = null;

    public int $detailPage = 1;

    /** The render's report — built once (its figures read several tables); never dehydrated, so a fresh one each request. */
    private ?AbstractReport $built = null;

    public static function getNavigationLabel(): string
    {
        return __('Pérdidas');
    }

    /** @return array<string, array<string, mixed>> */
    protected function queryString(): array
    {
        return [
            'period' => ['except' => 'month'],
            'customStart' => ['except' => null],
            'customEnd' => ['except' => null],
            'scope' => ['except' => null],
            'section' => ['except' => null],
            'person' => ['except' => null],
            'detailPage' => ['except' => 1],
            'sort' => ['except' => null],
            'sortDir' => ['except' => 'desc'],
        ];
    }

    public function updatedSection(): void
    {
        $this->detailPage = 1;
    }

    public function updatedPerson(): void
    {
        $this->detailPage = 1;
    }

    public function goToDetailPage(int $page): void
    {
        $this->detailPage = max(1, $page);
    }

    /**
     * «Ayer»: the business day before today at the sede in scope — the morning line's link. «last7» (prompt 375): the last 7
     * business days — the per-person signal's window, which its link opens on, sorted by the share lost.
     */
    public function resolvePeriod(): Period
    {
        return match ($this->period) {
            'yesterday' => Period::today($this->periodLocation())->previous(),
            'last7' => LossesReport::lastSevenDays($this->periodLocation()),
            default => parent::resolvePeriod(),
        };
    }

    protected function report(): AbstractReport
    {
        return $this->built ??= parent::report();
    }

    protected function makeReport(string $organisationId, ?array $locationIds, Period $period): AbstractReport
    {
        return (new LossesReport($organisationId, $locationIds, $period))
            ->withDetail($this->section, $this->person, $this->detailPage)
            ->linkingWith(fn (array $filters): string => $this->detailUrl($filters));
    }

    /**
     * This report, as it is now, with other detail filters — so a figure links to its list without losing the period or sede.
     *
     * @param  array<string, string|null>  $filters
     */
    public function detailUrl(array $filters): string
    {
        $query = array_filter([
            'period' => $this->period !== 'month' ? $this->period : null,
            'customStart' => $this->customStart,
            'customEnd' => $this->customEnd,
            'scope' => $this->scope,
            'section' => array_key_exists('section', $filters) ? $filters['section'] : $this->section,
            'person' => array_key_exists('person', $filters) ? $filters['person'] : $this->person,
        ], fn ($v): bool => filled($v));

        return static::getUrl($query).'#losses-detail';
    }

    /** The CSV carries the headline, the sections and the people (one block each), not only the primary table. */
    public function exportCsv(): StreamedResponse
    {
        /** @var LossesReport $report */
        $report = $this->report();
        $content = $report->csv();
        $filename = ReportExport::filename($report->key(), $this->resolvePeriod(), 'csv');

        return response()->streamDownload(fn () => print ($content), $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function getViewData(): array
    {
        /** @var LossesReport $report */
        $report = $this->report();
        $data = parent::getViewData();
        $headline = $report->headline();
        $series = $report->series();

        return [
            ...$data,
            // The sections render as cards (with links to their lists) above; their table is for the PDF and the CSV.
            'tables' => array_values(array_filter($data['tables'], fn ($table): bool => $table->key !== 'sections')),
            'chartsView' => 'filament.pages.reports.partials.losses',
            'tableControls' => ['detail' => 'filament.pages.reports.partials.losses-controls'],
            'lossCards' => $this->cards($headline),
            'lossSections' => $report->sections(),
            'lossSeries' => $series,
            'lossSeriesMax' => max([1, ...$series['values']]),
            'lossSeriesLine' => __('Pérdidas en el período: :total, :pct % de lo recaudado.', [
                'total' => Money::fromCents($headline['total'])->formatted(), 'pct' => LossesReport::share($headline['total'], $headline['takings']),
            ]),
            'lossSectionTitles' => LossesReport::sectionTitles(),
            'lossPeople' => $report->personOptions(),
            'lossPages' => $report->detailPages(),
        ];
    }

    /**
     * The headline as the shared stat cards: the total (against the previous period), member discounts beside it.
     *
     * @param  array{total: int, member_discounts: int, staff_discounts: int, takings: int, previous_total: int}  $h
     * @return list<array<string, mixed>>
     */
    private function cards(array $h): array
    {
        /** @var LossesReport $report */
        $report = $this->report();
        $chips = collect($report->summary())->keyBy('key');
        $diff = $h['total'] - $h['previous_total'];

        return [
            [
                'key' => 'total', 'label' => __('Total perdido'), 'icon' => Heroicon::OutlinedArrowTrendingDown,
                'value' => Money::fromCents($h['total'])->formatted(),
                'sub' => $chips['share']['value'],
                // More lost is the bad direction: up reads in red, down in green.
                'delta' => ['dir' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat'), 'tone' => $diff > 0 ? 'error' : ($diff < 0 ? 'success' : 'muted'), 'label' => $chips['previous']['value']],
            ],
            [
                'key' => 'takings', 'label' => __('Recaudado'), 'icon' => Heroicon::OutlinedBanknotes,
                'value' => Money::fromCents($h['takings'])->formatted(),
                'sub' => __('Dispensario, barra y tienda'),
            ],
            [
                'key' => 'member_discounts', 'label' => __('Descuentos de socio (aparte)'), 'icon' => Heroicon::OutlinedReceiptPercent,
                'value' => Money::fromCents($h['member_discounts'])->formatted(),
                'sub' => __('De ellos, al personal: :amount', ['amount' => Money::fromCents($h['staff_discounts'])->formatted()]),
            ],
        ];
    }
}
