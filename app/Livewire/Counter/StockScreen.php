<?php

namespace App\Livewire\Counter;

use App\Actions\Stock\MoveToReserve;
use App\Actions\Stock\RecordStockMovement;
use App\Actions\Stock\RecountBatch;
use App\Actions\Stock\TopUpFromReserve;
use App\Enums\CloseCountReason;
use App\Enums\StockMovementType;
use App\Livewire\Counter\Concerns\IdentifiesOperator;
use App\Livewire\Counter\Concerns\ReportsSystemErrors;
use App\Livewire\Counter\Concerns\ResolvesCounterLocation;
use App\Models\Batch;
use App\Models\Location;
use App\Support\ManagerApproval;
use App\Support\Weight;
use App\ViewModels\CounterStockSheet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use PDOException;
use RuntimeException;

/**
 * Prompt 364 — *Existencias*, the counter's sixth screen. Ben: "a separate page in the counter — 'view stock' for the
 * staff — where they can quickly adjust the batches, like update weights, view what top-ups are in the club, and top up a
 * jar." Every batch at the counter's sede with its jar and sealed reserve ({@see CounterStockSheet}); tapping one opens
 * its actions, each shown only to who may do it and refused server-side otherwise:
 *
 *  - «Rellenar» / «Toda la reserva» and «Pasar a reserva» (`pos.use`) — {@see TopUpFromReserve}, {@see MoveToReserve};
 *  - «Actualizar peso del bote» (`stock.take`) — the COUNTED weight through {@see RecountBatch} (which absorbs a forgotten
 *    top-up first), with ONE tapped reason, none for a `reasons.optional` holder (356);
 *  - «Añadir a la reserva» (`stock.manage`) — sealed bags that arrived and were never entered: the existing ADJUSTMENT on
 *    the reserve (360's Ajuste), with a reason, audited. No new way to create stock.
 *
 * A THIN shell: the writers are the stock Actions; the figures come from the sheet; the operator is the PIN operator.
 * Reached from the dispensary's empty-jar card with `?lote=<id>&from=pos` (scoped to this sede; anything else opens
 * nothing), and «Volver al dispensario» goes back to the basket and the held socio as they were.
 */
#[Layout('components.layouts.counter')]
class StockScreen extends Component
{
    use IdentifiesOperator, ReportsSystemErrors, ResolvesCounterLocation;

    public ?string $locationId = null;

    public bool $noLocation = false;

    /** The batch the dispensary sent us to (`?lote=`), opened once on arrival if it is at this sede. */
    #[Url(as: 'lote')]
    public ?string $lote = null;

    /** `pos` when we came from the dispensary: shows «Volver al dispensario». */
    #[Url(as: 'from')]
    public ?string $from = null;

    /** The strain / lote filter — a catalogue filter, not a member search (OneMemberLookupTest). */
    public string $batchFilter = '';

    /** all | reserve | low */
    public string $filter = 'all';

    public bool $showEmpty = false;

    public ?string $openBatchId = null;

    /** The last action's result, said in the panel by its buttons (275). */
    public ?string $confirmation = null;

    public ?string $flashMessage = null;

    public string $flashType = 'success';

    public int $flashSeq = 0;

    public function mount(): void
    {
        abort_unless($this->deviceCan('pos.use') || $this->deviceCan('stock.take'), 403);
        $this->resolveCounterLocation();

        if ($this->lote !== null) {
            $this->openBatch($this->lote);
        }
    }

    public function sheet(): CounterStockSheet
    {
        return new CounterStockSheet($this->resolveLocation() ?? new Location);
    }

    /** Prompt 365 — «N lotes agotados ocultos · Mostrar» / «Ocultar agotados». */
    public function toggleEmpty(): void
    {
        $this->showEmpty = ! $this->showEmpty;
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'reserve', 'low'], true) ? $filter : 'all';
    }

    /** Open a batch's actions — only a batch at THIS sede (a forged id from elsewhere opens nothing). */
    public function openBatch(string $batchId): void
    {
        $this->openBatchId = $this->batch($batchId)?->id;
        $this->confirmation = null;
    }

    public function closeBatch(): void
    {
        $this->openBatchId = null;
        $this->confirmation = null;
    }

    /** «Rellenar» (grams typed) or «Toda la reserva» (null): sealed bag → jar. */
    public function topUpJar(?string $grams): void
    {
        $this->act('pos.use', function (Batch $batch) use ($grams): string {
            $before = $batch->remaining_cg->centigrams;
            (new TopUpFromReserve)->handle($batch, $grams === null ? null : $this->centigrams($grams), $this->counterActor());
            $fresh = $batch->fresh();

            return __('Rellenado: :moved · bote :jar · reserva :reserve', ['moved' => '+'.Weight::fromCentigrams($fresh->remaining_cg->centigrams - $before)->formatted(),
                'jar' => $fresh->remaining_cg->formatted(), 'reserve' => $fresh->reserve_cg->formatted()]);
        });
    }

    /** «Pasar a reserva»: grams from the jar into sealed bags. */
    public function moveToReserve(string $grams): void
    {
        $this->act('pos.use', function (Batch $batch) use ($grams): string {
            $cg = $this->centigrams($grams);
            (new MoveToReserve)->handle($batch, $cg, $this->counterActor());
            $fresh = $batch->fresh();

            return __('Pasado a reserva: :moved · bote :jar · reserva :reserve', ['moved' => Weight::fromCentigrams($cg)->formatted(),
                'jar' => $fresh->remaining_cg->formatted(), 'reserve' => $fresh->reserve_cg->formatted()]);
        });
    }

    /**
     * «Actualizar peso del bote»: what the scale says (never a difference), through RecountBatch. ONE reason, tapped: a quick
     * pick ({@see CloseCountReason}) or «Otro» with a short line; a `reasons.optional` holder gives none.
     */
    public function updateJarWeight(string $counted, ?string $reasonKey = null, ?string $otherText = null): void
    {
        $this->act('stock.take', function (Batch $batch) use ($counted, $reasonKey, $otherText): string {
            $operator = $this->counterActor();
            $reason = ManagerApproval::allows($operator) ? ManagerApproval::reason() : $this->reason($reasonKey, $otherText);
            if ($reason === null) {
                throw new InvalidArgumentException(__('Elige qué ha pasado.'));
            }
            $quantity = $batch->isUnitType() ? $this->units($counted) : $this->centigrams($counted);
            $result = (new RecountBatch)->handle($batch, $quantity, $reason, $operator);
            $fresh = $batch->fresh();

            $jar = $batch->isUnitType() ? ((int) $fresh->remaining_units).' '.__('uds') : $fresh->remaining_cg->formatted();
            $diff = $batch->isUnitType() ? (($result['delta'] > 0 ? '+' : '').$result['delta'].' '.__('uds'))
                : (($result['delta'] > 0 ? '+' : '').Weight::fromCentigrams($result['delta'])->formatted());

            // Prompt 369 — said by what happened, never a «0.00 g» adjustment: a surplus the reserve covered is a forgotten
            // «Rellenar» (359), and only what is left over is an adjustment.
            if ($result['absorbed'] > 0) {
                return __('Bote :jar · :grams pasados desde la reserva (rellenado sin registrar)', ['jar' => $jar, 'grams' => Weight::fromCentigrams($result['absorbed'])->formatted()])
                    .($result['delta'] !== 0 ? ' · '.__('ajuste :diff', ['diff' => $diff]) : '');
            }

            return $result['delta'] === 0
                ? __('Sin diferencia · bote :jar', ['jar' => $jar])
                : __('Peso actualizado: :diff · bote :jar', ['diff' => $diff, 'jar' => $jar]);
        });
    }

    /** «Añadir a la reserva» (`stock.manage`): sealed bags never entered — the existing ADJUSTMENT on the reserve, with a reason. */
    public function addToReserve(string $grams, string $reason): void
    {
        $this->act('stock.manage', function (Batch $batch) use ($grams, $reason): string {
            if (trim($reason) === '') {
                throw new InvalidArgumentException(__('Indica el motivo.'));
            }
            $cg = $this->centigrams($grams);
            (new RecordStockMovement)->handle($batch, StockMovementType::ADJUSTMENT, $cg, [
                'reserve' => true, 'reason' => mb_substr(trim($reason), 0, 200), 'operator_id' => $this->counterActor()?->id,
            ]);

            return __('Añadido a la reserva: :moved · reserva :reserve', ['moved' => '+'.Weight::fromCentigrams($cg)->formatted(), 'reserve' => $batch->fresh()->reserve_cg->formatted()]);
        });
    }

    /** One shape for every action: an identified operator with the permission, the open batch at this sede, a plain answer. */
    private function act(string $permission, \Closure $do): void
    {
        if (! $this->requireOperator()) {
            return;
        }
        if (! $this->userCan($permission)) {
            $this->flash(__('No tienes permiso para hacer esto.'), 'error');

            return;
        }
        $batch = $this->batch($this->openBatchId);
        if ($batch === null) {
            return;
        }

        try {
            $this->confirmation = $do($batch);
        } catch (PDOException $e) {
            $this->confirmation = null;
            $this->systemError($e);

            return;
        } catch (RuntimeException|InvalidArgumentException|AuthorizationException $e) {
            $this->confirmation = null;
            $this->flash($e->getMessage(), 'error');

            return;
        }
        $this->flashMessage = null;
    }

    private function batch(?string $id): ?Batch
    {
        $location = $this->resolveLocation();

        return $id === null || $location === null ? null : Batch::query()->withoutGlobalScopes()
            ->where('organisation_id', $location->organisation_id)->where('location_id', $location->id)->whereNull('deleted_at')->find($id);
    }

    private function resolveLocation(): ?Location
    {
        return $this->locationId !== null ? Location::query()->find($this->locationId) : null;
    }

    private function centigrams(string $grams): int
    {
        if (Weight::canonicalGrams($grams) === null) {
            throw new InvalidArgumentException(__('Escribe los gramos (p. ej. 10 o 3.5).'));
        }

        return Weight::fromGrams($grams)->centigrams;
    }

    private function units(string $units): int
    {
        if (preg_match('/^\d+$/', trim($units)) !== 1) {
            throw new InvalidArgumentException(__('Escribe un número entero de unidades.'));
        }

        return (int) trim($units);
    }

    private function reason(?string $key, ?string $otherText): ?string
    {
        $pick = CloseCountReason::tryFrom((string) $key);
        if ($pick === CloseCountReason::OTHER) {
            $text = mb_substr(trim((string) $otherText), 0, 120);

            return $text === '' ? null : $text;
        }

        return $pick?->label();
    }

    /** @return list<CloseCountReason> 360's quick picks, without «Bote no disponible» (it is here, being weighed) */
    public function reasonPicks(): array
    {
        return [CloseCountReason::WEIGHING_ERROR, CloseCountReason::SPILL, CloseCountReason::UNRECORDED_TOPUP];
    }

    protected function flash(string $message, string $type): void
    {
        $this->flashSeq++;
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    public function render(): View
    {
        $this->applyCounterScope();
        $sheet = $this->sheet();

        return view('livewire.counter.stock-screen', [
            'rows' => $this->resolveLocation() !== null ? $sheet->rows($this->filter, $this->showEmpty, $this->batchFilter) : collect(),
            'summary' => $this->resolveLocation() !== null ? $sheet->summary() : null,
            'emptyCount' => $this->resolveLocation() !== null ? $sheet->emptyCount($this->filter, $this->batchFilter) : 0,
            'open' => $sheet->row($this->openBatchId),
            'canTopUp' => $this->userCan('pos.use'),
            'canWeigh' => $this->userCan('stock.take'),
            'canAddReserve' => $this->userCan('stock.manage'),
            'reasonOptional' => ManagerApproval::allows($this->counterActor()),
        ]);
    }
}
