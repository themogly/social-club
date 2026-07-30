<?php

namespace App\ViewModels\Reports;

use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Enums\SanctionType;
use App\Models\Member;
use App\Models\MemberSanction;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Socios — the directory with counts by status, new joins and churn in the period,
 * cards expiring soon and sanctions. A lighter report by design: the formal libro de
 * socios is prompt 16. People are org-wide; a specific-location view is filtered to
 * members holding a membership at that sede.
 */
class MembersReport extends AbstractReport
{
    private int $total = 0;

    private int $active = 0;

    public function key(): string
    {
        return 'socios';
    }

    public function title(): string
    {
        return __('Informe de socios');
    }

    protected function build(): array
    {
        return [
            $this->directory(),
            $this->countsByStatus(),
            $this->expiringCards(),
            $this->sanctions(),
        ];
    }

    public function summary(): array
    {
        $this->tables();
        [$start, $end] = $this->bounds();

        $joined = $this->memberScope()->whereBetween('joined_at', [$start, $end])->count();
        $left = $this->memberScope()->whereBetween('left_at', [$start, $end])->count();

        return [
            ['label' => __('Total socios'), 'value' => (string) $this->total],
            ['label' => __('Activos'), 'value' => (string) $this->active],
            ['label' => __('Nuevas altas'), 'value' => (string) $joined, 'tone' => 'success'],
            ['label' => __('Bajas'), 'value' => (string) $left, 'tone' => $left > 0 ? 'warning' : 'success'],
        ];
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            MemberStatus::APPLICANT->value => __('Solicitante'),
            MemberStatus::ACTIVE->value => __('Activo'),
            MemberStatus::INACTIVE->value => __('Inactivo'),
            MemberStatus::EXPIRED->value => __('Caducado'),
            MemberStatus::SUSPENDED->value => __('Suspendido'),
            MemberStatus::EXPELLED->value => __('Expulsado'),
            default => $status,
        };
    }

    // --- Directory ------------------------------------------------------------------

    private function directory(): ReportTable
    {
        $members = $this->memberScope()
            ->orderBy('member_no')
            ->get(['id', 'member_no', 'first_name', 'last_name', 'status', 'joined_at', 'is_therapeutic']);

        $this->total = $members->count();
        $this->active = $members->where('status', MemberStatus::ACTIVE)->count();

        $rows = $members->map(fn (Member $m): array => [
            'member_no' => (string) $m->member_no,
            'socio' => $m->fullName(),
            'estado' => self::statusLabel($m->status->value),
            'alta' => $m->joined_at,
            'terapeutico' => $m->is_therapeutic ? __('Sí') : __('No'),
        ])->all();

        return new ReportTable(
            key: 'directory',
            title: __('Directorio de socios'),
            columns: [
                ReportColumn::text('member_no', __('Nº')),
                ReportColumn::text('socio', __('Socio')),
                ReportColumn::text('estado', __('Estado')),
                ReportColumn::date('alta', __('Alta')),
                ReportColumn::text('terapeutico', __('Terapéutico'), sortable: false),
            ],
            rows: $rows,
            totals: [],
            empty: __('Sin socios en esta sede'),
            emptyHint: __('Da de alta a un socio para empezar a construir el directorio.'),
            defaultSort: 'member_no',
            defaultSortDir: 'asc',
            sortable: true,
            note: __(':n socios', ['n' => $this->total]),
        );
    }

    // --- Counts by status -----------------------------------------------------------

    private function countsByStatus(): ReportTable
    {
        $counts = $this->memberScope()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $rows = [];
        foreach (MemberStatus::cases() as $status) {
            $n = (int) ($counts[$status->value] ?? 0);
            if ($n > 0) {
                $rows[] = ['estado' => self::statusLabel($status->value), 'socios' => $n];
            }
        }

        return new ReportTable(
            key: 'by_status',
            title: __('Socios por estado'),
            columns: [
                ReportColumn::text('estado', __('Estado'), sortable: false),
                ReportColumn::number('socios', __('Socios')),
            ],
            rows: $rows,
            totals: ['socios' => array_sum(array_column($rows, 'socios'))],
            empty: __('Sin socios'),
        );
    }

    // --- Cards expiring soon --------------------------------------------------------

    private function expiringCards(): ReportTable
    {
        $days = (int) Settings::get('expiring_soon_days', 30);

        $rows = DB::table('memberships')
            ->join('members', 'memberships.member_id', '=', 'members.id')
            ->whereIn('memberships.location_id', $this->resolvedLocationIds())
            ->where('memberships.status', MembershipStatus::ACTIVE->value)
            ->whereNull('memberships.deleted_at')
            ->whereNotNull('memberships.expires_at')
            ->whereBetween('memberships.expires_at', [now(), now()->addDays($days)])
            ->orderBy('memberships.expires_at')
            ->get([
                'members.member_no as member_no',
                'members.first_name as first_name',
                'members.last_name as last_name',
                'memberships.expires_at as caduca',
            ])
            ->map(fn (\stdClass $r): array => [
                'member_no' => (string) $r->member_no,
                'socio' => trim($r->first_name.' '.$r->last_name),
                'caduca' => $r->caduca,
            ])->all();

        return new ReportTable(
            key: 'expiring',
            title: __('Membresías por vencer (:n días)', ['n' => $days]),
            columns: [
                ReportColumn::text('member_no', __('Nº'), sortable: false),
                ReportColumn::text('socio', __('Socio'), sortable: false),
                ReportColumn::date('caduca', __('Caduca'), sortable: false),
            ],
            rows: $rows,
            empty: __('Ninguna membresía vence pronto'),
        );
    }

    // --- Sanctions in the period ----------------------------------------------------

    private function sanctions(): ReportTable
    {
        [$start, $end] = $this->bounds();

        $rows = MemberSanction::query()
            ->with('member')
            ->whereHas('member', fn (Builder $q) => $q->where('organisation_id', $this->organisationId)
                ->when(! $this->includesAllLocations(), fn (Builder $q) => $q->whereHas('memberships', fn (Builder $m) => $m->whereIn('location_id', $this->resolvedLocationIds()))))
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (MemberSanction $s): array => [
                'member_no' => $s->member->member_no,
                'socio' => $s->member->fullName(),
                'tipo' => self::sanctionLabel($s->type->value),
                'desde' => $s->from_date,
                'hasta' => $s->until_date,
            ])->all();

        return new ReportTable(
            key: 'sanctions',
            title: __('Sanciones en el período'),
            columns: [
                ReportColumn::text('member_no', __('Nº'), sortable: false),
                ReportColumn::text('socio', __('Socio'), sortable: false),
                ReportColumn::text('tipo', __('Tipo'), sortable: false),
                ReportColumn::date('desde', __('Desde'), sortable: false),
                ReportColumn::date('hasta', __('Hasta'), sortable: false),
            ],
            rows: $rows,
            empty: __('Sin sanciones en este período'),
        );
    }

    private static function sanctionLabel(string $type): string
    {
        return match ($type) {
            SanctionType::WARNING->value => __('Apercibimiento'),
            SanctionType::SUSPENSION->value => __('Suspensión'),
            SanctionType::EXPULSION->value => __('Expulsión'),
            default => $type,
        };
    }

    /**
     * Members in scope: org-wide, narrowed to those holding a membership at the selected
     * sede unless this is the owner's "All" view.
     *
     * @return Builder<Member>
     */
    private function memberScope(): Builder
    {
        return Member::query()->withoutGlobalScopes()
            ->where('organisation_id', $this->organisationId)
            ->when(! $this->includesAllLocations(), fn (Builder $q) => $q->whereHas('memberships', fn (Builder $m) => $m->whereIn('location_id', $this->resolvedLocationIds())));
    }
}
