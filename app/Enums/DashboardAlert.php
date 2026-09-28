<?php

namespace App\Enums;

use App\Filament\Pages\RegistroJornada;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\Batches\BatchResource;
use App\Filament\Resources\Genetics\GeneticResource;
use App\Filament\Resources\MemberApplications\MemberApplicationResource;
use App\Filament\Resources\Members\MemberResource;
use App\Filament\Resources\TillSessions\TillSessionResource;
use Filament\Pages\Page;
use Filament\Resources\Resource;

/**
 * The things a dashboard can say need attention — and, for each, WHERE THE SUBJECTS ARE (prompt 207).
 *
 * **Why this is an enum rather than seven string literals.** The alert vocabulary was declared in three
 * places, each a `match` over the same keys with its own `default`: `Dashboard::alerts()` produced them,
 * `Filament\Pages\Dashboard::decorateAlerts()` gave each a sentence, an icon and a panel URL, and
 * `CounterHome` gave each a sentence and a counter route. An eighth alert could therefore be added in one
 * place and arrive at the other two as `default` — *"Aviso"* linking to `#` on the panel, and a **silent
 * `null`** on the counter hub, which renders as a `<p>` that looks deliberate and is not.
 *
 * Every method below is a `match ($this)` with **no default**, so Larastan fails the build on a case that has
 * not declared what it means. That is the guard the prompt asked for: a new alert cannot arrive silently.
 *
 * **The counter destination is the point of the prompt.** `alerts()` carries a count and nothing else — by
 * design; 177's rule is that the hub is counts and states, never names, because it is on display in a room
 * with the next socio in it. So the names have to appear at the far end: the alert links to the working
 * screen **with its worklist already open**, and that screen resolves the rows through its own resolvers.
 * `counterRoute()` names that screen; the alert's own value is the filter it arrives with.
 */
enum DashboardAlert: string
{
    case MEMBERS_OVER_LIMIT = 'members_over_limit';
    case ACTIVE_MEMBER_CAP = 'active_member_cap';
    case UNRECONCILED_TILL = 'unreconciled_till';
    case BATCHES_EXPIRING = 'batches_expiring';
    case STOCK_CEILING_EXCEEDED = 'stock_ceiling_exceeded';
    case MEMBERSHIPS_EXPIRING = 'memberships_expiring';
    case PENDING_APPLICATIONS = 'pending_applications';
    case GENETICS_LOW_STOCK = 'genetics_low_stock';
    case ARTICLES_LOW_STOCK = 'articles_low_stock';
    case ASSOCIATION_STOCK_CEILING = 'association_stock_ceiling';
    // Prompt 285 — the registro de jornada's two loose ends, over the last 31 days (never the dashboard period), on the
    // panel dashboard only and only for holders of `staff.hours.view`.
    case STAFF_OPEN_SHIFTS = 'staff_open_shifts';
    case STAFF_UNCLOCKED_ACTIVITY = 'staff_unclocked_activity';

    /** error | warning | info — how loudly the rail says it. */
    public function severity(): string
    {
        return match ($this) {
            self::STOCK_CEILING_EXCEEDED => 'error',
            self::MEMBERS_OVER_LIMIT, self::ACTIVE_MEMBER_CAP,
            self::UNRECONCILED_TILL, self::BATCHES_EXPIRING,
            self::GENETICS_LOW_STOCK, self::ARTICLES_LOW_STOCK, self::ASSOCIATION_STOCK_CEILING,
            self::STAFF_OPEN_SHIFTS => 'warning',
            self::MEMBERSHIPS_EXPIRING, self::PENDING_APPLICATIONS, self::STAFF_UNCLOCKED_ACTIVITY => 'info',
        };
    }

    /**
     * The counter rail's sentence. A count and a state — never a name (177).
     *
     * The count defaults so this satisfies the project-wide rule that every backed enum exposes a callable
     * translated `label()` (prompt 19, asserted in `LocalizationTest`); every real caller passes one.
     */
    public function label(int $count = 1): string
    {
        return match ($this) {
            self::MEMBERS_OVER_LIMIT => trans_choice(':count socio ha superado su límite|:count socios han superado su límite', $count, ['count' => $count]),
            self::ACTIVE_MEMBER_CAP => __('El club está en su tope de socios activos'),
            self::UNRECONCILED_TILL => trans_choice(':count caja sin cerrar|:count cajas sin cerrar', $count, ['count' => $count]),
            self::BATCHES_EXPIRING => trans_choice(':count lote caduca pronto|:count lotes caducan pronto', $count, ['count' => $count]),
            self::STOCK_CEILING_EXCEEDED => __('Stock por encima del techo legal en esta sede'),
            self::MEMBERSHIPS_EXPIRING => trans_choice(':count membresía vence pronto|:count membresías vencen pronto', $count, ['count' => $count]),
            self::PENDING_APPLICATIONS => trans_choice(':count solicitud pendiente|:count solicitudes pendientes', $count, ['count' => $count]),
            self::GENETICS_LOW_STOCK => trans_choice(':count variedad con stock bajo|:count variedades con stock bajo', $count, ['count' => $count]),
            self::ARTICLES_LOW_STOCK => trans_choice(':count artículo de barra y tienda con stock bajo|:count artículos de barra y tienda con stock bajo', $count, ['count' => $count]),
            self::ASSOCIATION_STOCK_CEILING => __('La asociación tiene más stock en total (sedes y almacén) que el techo orientativo'),
            self::STAFF_OPEN_SHIFTS => trans_choice(':count jornada sin fichar salida|:count jornadas sin fichar salida', $count, ['count' => $count]),
            self::STAFF_UNCLOCKED_ACTIVITY => trans_choice(':count día con actividad sin fichar|:count días con actividad sin fichar', $count, ['count' => $count]),
        };
    }

    /**
     * The COUNTER screen this alert's subjects live on, or null when the counter holds no answer to it.
     *
     * The three nulls are a decision, not an omission, and each is the same decision: **the counter has no
     * subject to land on.** A stock ceiling and an active-member cap are states of the club rather than sets
     * of rows — the remedy is a purchasing or governance decision taken over days, not a tap at the counter —
     * and expiring batches are stock, which the counter reads (185) and never adjusts. They are non-actionable
     * *at the counter* for everybody, and for a user who can also open the panel they go to
     * {@see self::panelUrl()}, which is a filtered resource rather than the panel's front door.
     */
    public function counterRoute(): ?string
    {
        return match ($this) {
            // Socios: the member's record, their membership state and their allowance all already live here
            // (127 + 177), which is why all three member alerts land on the same screen.
            self::MEMBERSHIPS_EXPIRING, self::PENDING_APPLICATIONS, self::MEMBERS_OVER_LIMIT => 'counter.members',
            // Caja already resumes the sede's single open session on arrival, and makes the operator pick
            // when several are open — so this destination was the one that was already right.
            self::UNRECONCILED_TILL => 'counter.till',
            // Low stock (prompt 269) is the same decision as expiring batches: the remedy is a purchase, which the
            // counter does not make. The dispensary picker already badges the variety itself.
            self::BATCHES_EXPIRING, self::STOCK_CEILING_EXCEEDED, self::ACTIVE_MEMBER_CAP,
            self::GENETICS_LOW_STOCK, self::ARTICLES_LOW_STOCK, self::ASSOCIATION_STOCK_CEILING => null,
            // Hours are corrected in the panel's registro (staff.hours.manage), never at the counter.
            self::STAFF_OPEN_SHIFTS, self::STAFF_UNCLOCKED_ACTIVITY => null,
        };
    }

    /**
     * The admin resource this alert's subjects live in — the ONE map, read by both dashboards.
     *
     * The counter hub used to fall back to `url('/')`: the panel's front door, which is the same
     * hand-you-a-haystack move as landing on an empty search box. A resource index is at least the right
     * table.
     *
     * The two hours alerts land on a PAGE — the registro de jornada — not a resource (prompt 285).
     *
     * @return class-string<\Filament\Resources\Resource>|class-string<Page>
     */
    public function panelResource(): string
    {
        return match ($this) {
            self::MEMBERS_OVER_LIMIT, self::ACTIVE_MEMBER_CAP, self::MEMBERSHIPS_EXPIRING => MemberResource::class,
            self::UNRECONCILED_TILL => TillSessionResource::class,
            self::BATCHES_EXPIRING, self::STOCK_CEILING_EXCEEDED, self::ASSOCIATION_STOCK_CEILING => BatchResource::class,
            self::GENETICS_LOW_STOCK => GeneticResource::class,
            self::PENDING_APPLICATIONS => MemberApplicationResource::class,
            self::ARTICLES_LOW_STOCK => ArticleResource::class,
            self::STAFF_OPEN_SHIFTS, self::STAFF_UNCLOCKED_ACTIVITY => RegistroJornada::class,
        };
    }

    public function panelUrl(): string
    {
        // The articles table already has a "Stock bajo" filter — arrive with it on, not on the whole catalogue.
        // Both low-stock tables have a "Stock bajo" filter — arrive with it on, not on the whole catalogue (269/273).
        if ($this === self::ARTICLES_LOW_STOCK || $this === self::GENETICS_LOW_STOCK) {
            return $this->panelResource()::getUrl('index', ['filters' => ['low_stock' => ['isActive' => true]]]);
        }

        return $this->panelResource()::getUrl();
    }

    /**
     * Whether THIS actor may open that resource — the resource's own policy, asked rather than assumed.
     *
     * Being able to open the panel is not the same as being able to open a table in it, and the counter hub
     * was conflating them: a STAFF operator holds panel access and no `viewAny` on Batches, so *"2 lotes
     * caducan pronto"* handed them a 403. An alert that lands somebody on a 403 is worse than one that does
     * not link at all.
     */
    public function panelDestinationIsOpenToActor(): bool
    {
        $target = $this->panelResource();

        return is_subclass_of($target, Page::class) ? $target::canAccess() : $target::canViewAny();
    }
}
