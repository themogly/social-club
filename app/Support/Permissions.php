<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\RolePermissionOverride;
use Illuminate\Support\Facades\Schema;

/**
 * The single source of truth for the permission catalogue and the role → permission
 * matrix. Every permission any prompt references MUST appear in ALL. Roles/permissions
 * are seeded from here (RolePermissionSeeder); policies and Filament resources check
 * these strings via `$user->can('...')` (spatie registers each as a gate).
 *
 * Distinctions that matter: `limits.override` = authorise a consumption-limit breach
 * at the counter; `checkin.override` = authorise a door check (aforo/age/sanction/debt).
 * `settings.manage.location` lets a manager configure their own premises without the
 * org-wide compliance thresholds (`settings.manage`, owner-only).
 */
class Permissions
{
    /** @var list<string> The complete permission catalogue. */
    public const ALL = [
        // Reports & data
        'reports.view', 'reports.view.all', 'reports.export',
        // Members
        'members.view', 'members.create', 'members.edit', 'members.transfer', 'members.import',
        'member.limits.set', 'member.discount.assign', 'member.documents.view', 'member.sanction',
        'applications.review',
        // Membership
        // 'membership.enrol' (prompt 203) = open a membership at the sede you are working at, on the tier's
        // DEFAULT fee — a new one or a lapsed one restored. Moving a membership between sedes is a different
        // act with another sede's register in it and stays at 'members.transfer'.
        'membership.enrol', 'membership.fee.override', 'membership.fee.collect', 'membership.fee.waive',
        'carencia.waive',
        // Attendance
        'checkin.manage', 'checkin.override',
        // Counter
        'pos.use', 'pos.bar', 'dispensation.void', 'order.void', 'limits.override', 'dispensation.price.override',
        // Catalogue & stock
        'genetics.manage', 'prices.manage', 'stock.manage', 'stock.merma', 'stock.transfer',
        'stock.take', 'articles.manage', 'discounts.manage',
        // Money
        'wallet.adjust', 'till.open', 'till.close', 'cash.bank', 'expenses.record',
        'expenses.approve', 'expenses.overheads', 'expenses.categories', 'purchases.manage',
        // Governance ('minutes.manage' drafts an acta; 'minute.sign' signs it — a narrower, owner-only authority)
        'documents.generate', 'minutes.manage', 'minute.sign', 'register.view',
        // Communications (announcements + events, member PWA)
        'comms.manage',
        // Privacy
        'data.request.handle', 'data.erase',
        // System ('settings.consent' — edit the org-wide consent declarations everyone ticks: a sensitive,
        // legal-content capability held separately from the routine thresholds of 'settings.manage'; prompt 153)
        'locations.manage', 'staff.manage', 'settings.manage', 'settings.manage.location', 'settings.consent', 'audit.view',
        // Prompt 262 — may this role open the admin panel at all? Off => a counter-only login: it signs in at
        // /login and lands on the counter, and any panel URL sends it back there.
        'panel.access',
        // Security (prompt 121): initiate = trip the panic lockdown (staff hold it — they are the ones in the
        // room); manage = run/observe drills, read the runbook, end a drill. A REAL lockdown is never
        // reactivated in-app (off-premises paths only), so there is no "reactivate" permission by design.
        'lockdown.initiate', 'lockdown.manage',
    ];

    /** MANAGER — per assigned location. Broad operational power, minus org-wide compliance/privacy. */
    private const MANAGER = [
        'reports.view', 'reports.export',
        'members.view', 'members.create', 'members.edit', 'members.transfer', 'members.import',
        'member.sanction', 'applications.review',
        'member.documents.view', // prompt 262 — the owner: everyone may open members' ID scans
        'membership.enrol', 'membership.fee.override', 'membership.fee.collect', 'membership.fee.waive',
        'carencia.waive',
        'checkin.manage', 'checkin.override',
        'pos.use', 'pos.bar', 'dispensation.void', 'order.void', 'limits.override', 'dispensation.price.override',
        'genetics.manage', 'prices.manage', 'stock.manage', 'stock.merma', 'stock.transfer',
        'stock.take', 'articles.manage', 'discounts.manage',
        'wallet.adjust', 'till.open', 'till.close', 'cash.bank', 'expenses.record',
        'expenses.approve', 'purchases.manage',
        'documents.generate', 'minutes.manage', 'register.view',
        'comms.manage',
        'settings.manage.location',
        'lockdown.initiate', 'lockdown.manage',
        'panel.access', // prompt 262 — managers use the admin panel by default
    ];

    /** STAFF — per assigned location. Counter + door + basic member intake only. */
    private const STAFF = [
        'pos.use', 'pos.bar', 'checkin.manage',
        'members.view',
        // Prompt 262, the owner's instruction: everyone may open members' ID scans, even staff. An Article-9
        // widening, made deliberately: every view is logged with the person's name (the PIN operator at the
        // counter), and the RAT states which roles hold it. NOT `panel.access`: staff are counter-only by default
        // and open a scan from the counter's member record.
        'member.documents.view',
        'expenses.record', 'membership.fee.collect', 'till.open',
        // Prompt 219, the owner's explicit decision, and the 174 shape again: waiving is ROUTINE at this club
        // — therapeutic members, and members already paying at another sede — and one person is usually
        // working, so mirroring `membership.fee.override` (MANAGER+) would mean the common case needs someone
        // who is not there. The route is open BECAUSE it is audited: a waiver is a row with a named operator,
        // a required reason and an audit entry, and it enters no revenue figure. A club that disagrees revokes
        // it from STAFF on Sistema > Roles y permisos (prompt 262 — before it, there was no such screen).
        'membership.fee.waive',
        // Prompt 203, the same reasoning as 174 one step further along: a member whose membership lapsed —
        // or who has never been enrolled HERE — was a dead end whose own remedy text sent the operator to a
        // panel STAFF cannot act in. On a Friday evening with one person working, that means turning the
        // member away. What is opened is the LOCAL, audited, single-writer route on the tier's default fee;
        // fee overrides, tier changes, suspensions, limits and TRANSFERS all stay where they were.
        'membership.enrol',
        'lockdown.initiate', // the panic button — staff are the ones in a robbery (prompt 121)
        // Prompt 174, on the owner's explicit instruction: STAFF may review an APPLICATION. There is normally
        // one member of staff in the club, so requiring a manager would mean nobody could be signed up — the
        // counter-first design fails at its first step. This reverses prompt 122's OVERNIGHT-DEFAULT, whose
        // reasoning ("application review is already manager-gated, so direct enrol should not be more open
        // than the reviewed one") is now superseded from the other direction: the REVIEWED route is the open
        // one, precisely because it is the audited one.
        'applications.review',
        // members.create is STILL deliberately NOT here, and that line is the point. Staff can admit somebody
        // who APPLIED — through the audited path, with the age gate, the duplicate search and the versioned
        // consent capture all enforced — but cannot conjure a member out of nothing through the panel's
        // direct-enrol form, which has none of those. A club that wants on-the-spot staff enrolment grants
        // members.create back to the STAFF role deliberately. See DECISIONS (prompts 122 and 174).
    ];

    /**
     * Grants that reach the compliance and privacy core. The roles page WARNS before giving one to STAFF or MANAGER
     * — it does not block: it is the owner's club (prompt 262).
     *
     * @var list<string>
     */
    public const SENSITIVE = [
        'staff.manage', 'settings.manage', 'settings.consent', 'data.erase', 'data.request.handle', 'audit.view',
        'locations.manage',
    ];

    /**
     * Grants that NEED another to be usable (prompt 265) — a flow that asks for a second permission partway through.
     * The roles page warns (and offers to grant the other in one click); it never changes anything by itself.
     * ONE list, so a new pair is one line here.
     *
     *   · till.close → stock.take: the last close of the day at a sede first requires the blind flower recount
     *     (`TillSession::reweighRequired()`), which is gated on stock.take — without it the close cannot finish.
     *   · pos.use / pos.bar / checkin.manage → till.open: the counter sends a till-less sede to open a till first
     *     (236); without till.open this role can only work once somebody else has opened one.
     *
     * @var array<string, list<string>>
     */
    public const DEPENDENCIES = [
        'till.close' => ['stock.take'],
        'pos.use' => ['till.open'],
        'pos.bar' => ['till.open'],
        'checkin.manage' => ['till.open'],
    ];

    /** Why `$permission` needs `$needs`, in the words the roles page shows the owner (prompt 265). */
    public static function dependencyReason(string $permission, string $needs): string
    {
        return match ($permission) {
            'till.close' => __('Para cerrar la caja al final del día también hace falta «:needs».', ['needs' => self::label($needs)]),
            default => __('Sin «:needs», solo podrá trabajar cuando otra persona haya abierto la caja.', ['needs' => self::label($needs)]),
        };
    }

    /**
     * The dependencies a role is missing, as [permission, needs] pairs — for the roles page's warnings.
     *
     * @param  list<string>  $held
     * @return list<array{permission: string, needs: string}>
     */
    public static function missingDependencies(array $held): array
    {
        $missing = [];
        foreach (self::DEPENDENCIES as $permission => $needed) {
            if (! in_array($permission, $held, true)) {
                continue;
            }
            foreach ($needed as $needs) {
                if (! in_array($needs, $held, true)) {
                    $missing[] = ['permission' => $permission, 'needs' => $needs];
                }
            }
        }

        return $missing;
    }

    /**
     * The catalogue by area, for the roles page — the order a person reads it in, not the order it was built.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        return [
            __('Acceso') => ['panel.access'],
            __('Informes') => ['reports.view', 'reports.view.all', 'reports.export'],
            __('Socios') => ['members.view', 'members.create', 'members.edit', 'members.transfer', 'members.import',
                'member.limits.set', 'member.discount.assign', 'member.documents.view', 'member.sanction', 'applications.review'],
            __('Membresías y cuotas') => ['membership.enrol', 'membership.fee.override', 'membership.fee.collect', 'membership.fee.waive', 'carencia.waive'],
            __('Recepción') => ['checkin.manage', 'checkin.override'],
            __('Mostrador') => ['pos.use', 'pos.bar', 'dispensation.void', 'order.void', 'limits.override', 'dispensation.price.override'],
            __('Catálogo y stock') => ['genetics.manage', 'prices.manage', 'stock.manage', 'stock.merma', 'stock.transfer', 'stock.take', 'articles.manage', 'discounts.manage'],
            __('Dinero') => ['wallet.adjust', 'till.open', 'till.close', 'cash.bank', 'expenses.record', 'expenses.approve', 'expenses.overheads', 'expenses.categories', 'purchases.manage'],
            __('Gobierno') => ['documents.generate', 'minutes.manage', 'minute.sign', 'register.view', 'comms.manage'],
            __('Privacidad') => ['data.request.handle', 'data.erase'],
            __('Sistema') => ['locations.manage', 'staff.manage', 'settings.manage', 'settings.manage.location', 'settings.consent', 'audit.view'],
            __('Seguridad') => ['lockdown.initiate', 'lockdown.manage'],
        ];
    }

    /** A permission in plain words — never the raw key on a screen a person reads. */
    public static function label(string $permission): string
    {
        return match ($permission) {
            'panel.access' => __('Acceso al panel de administración'),
            'reports.view' => __('Ver informes de su sede'),
            'reports.view.all' => __('Ver informes de todas las sedes'),
            'reports.export' => __('Exportar informes'),
            'members.view' => __('Ver socios'),
            'members.create' => __('Dar de alta socios directamente (sin solicitud)'),
            'members.edit' => __('Editar socios'),
            'members.transfer' => __('Trasladar socios entre sedes'),
            'members.import' => __('Importar socios'),
            'member.limits.set' => __('Fijar límites de consumo personalizados'),
            'member.discount.assign' => __('Asignar descuentos a socios'),
            'member.documents.view' => __('Ver documentos de identidad de los socios'),
            'member.sanction' => __('Sancionar socios'),
            'applications.review' => __('Revisar y aprobar solicitudes de alta'),
            'membership.enrol' => __('Abrir membresías en su sede'),
            'membership.fee.override' => __('Cambiar el importe de una cuota'),
            'membership.fee.collect' => __('Cobrar cuotas'),
            'membership.fee.waive' => __('Eximir de una cuota (con motivo)'),
            'carencia.waive' => __('Eximir del periodo de carencia'),
            'checkin.manage' => __('Registrar entradas y salidas'),
            'checkin.override' => __('Autorizar una entrada bloqueada'),
            'pos.use' => __('Usar el dispensario'),
            'pos.bar' => __('Usar la barra'),
            'dispensation.void' => __('Anular dispensaciones'),
            'order.void' => __('Anular pedidos de barra'),
            'limits.override' => __('Autorizar superar un límite de consumo'),
            'dispensation.price.override' => __('Ajustar el precio de una dispensación'),
            'genetics.manage' => __('Gestionar genéticas'),
            'prices.manage' => __('Gestionar precios'),
            'stock.manage' => __('Gestionar stock'),
            'stock.merma' => __('Registrar mermas'),
            'stock.transfer' => __('Trasladar stock entre sedes'),
            'stock.take' => __('Hacer recuentos de inventario'),
            'articles.manage' => __('Gestionar artículos de barra y tienda'),
            'discounts.manage' => __('Gestionar descuentos'),
            'wallet.adjust' => __('Ajustar monederos'),
            'till.open' => __('Abrir caja'),
            'till.close' => __('Cerrar caja (arqueo)'),
            'cash.bank' => __('Ingresar efectivo en el banco'),
            'expenses.record' => __('Registrar gastos'),
            'expenses.approve' => __('Aprobar gastos'),
            'expenses.overheads' => __('Registrar gastos generales'),
            'expenses.categories' => __('Gestionar categorías de gasto'),
            'purchases.manage' => __('Gestionar compras y proveedores'),
            'documents.generate' => __('Generar documentos'),
            'minutes.manage' => __('Redactar actas'),
            'minute.sign' => __('Firmar actas'),
            'register.view' => __('Ver los libros de registro'),
            'comms.manage' => __('Gestionar avisos y eventos'),
            'data.request.handle' => __('Atender solicitudes de protección de datos'),
            'data.erase' => __('Borrar (anonimizar) datos de socios'),
            'locations.manage' => __('Gestionar sedes'),
            'staff.manage' => __('Gestionar el personal'),
            'settings.manage' => __('Cambiar los ajustes de la organización'),
            'settings.manage.location' => __('Cambiar los ajustes de su sede'),
            'settings.consent' => __('Editar los textos de consentimiento'),
            'audit.view' => __('Ver el registro de auditoría'),
            'lockdown.initiate' => __('Activar el botón de pánico'),
            'lockdown.manage' => __('Gestionar simulacros de bloqueo'),
            default => $permission,
        };
    }

    /**
     * What the CODE grants a role out of the box (prompt 262: the defaults). The club's own choices are layered on
     * top by {@see for()}.
     *
     * @return list<string>
     */
    public static function defaultsFor(Role $role): array
    {
        return match ($role) {
            Role::OWNER => self::ALL,          // the org superuser
            Role::MANAGER => self::MANAGER,
            Role::STAFF => self::STAFF,
        };
    }

    /**
     * What a role ACTUALLY holds: the code's defaults with the club's overrides applied (prompt 262). The OWNER is
     * always everything — no override applies to it, and the roles page cannot express one. This is what the sync
     * converges on and what drift is measured against, so an owner's deliberate choice survives every deploy and
     * is never reported as drift; a permission removed from the catalogue leaves regardless of any override.
     *
     * @return list<string>
     */
    public static function for(Role $role): array
    {
        $defaults = self::defaultsFor($role);

        if ($role === Role::OWNER || ! Schema::hasTable('role_permission_overrides')) {
            return $defaults;
        }

        $grants = array_fill_keys($defaults, true);

        foreach (RolePermissionOverride::query()->where('role', $role->value)->get() as $override) {
            if (! in_array($override->permission, self::ALL, true)) {
                continue;
            }
            if ($override->granted) {
                $grants[$override->permission] = true;
            } else {
                unset($grants[$override->permission]);
            }
        }

        return array_values(array_filter(self::ALL, fn (string $p): bool => isset($grants[$p])));
    }
}
