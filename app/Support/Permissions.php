<?php

namespace App\Support;

use App\Enums\Role;

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
        'membership.fee.override', 'membership.fee.collect', 'carencia.waive',
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
        'membership.fee.override', 'membership.fee.collect', 'carencia.waive',
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
    ];

    /** STAFF — per assigned location. Counter + door + basic member intake only. */
    private const STAFF = [
        'pos.use', 'pos.bar', 'checkin.manage',
        'members.view',
        'expenses.record', 'membership.fee.collect', 'till.open',
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
     * @return list<string>
     */
    public static function for(Role $role): array
    {
        return match ($role) {
            Role::OWNER => self::ALL,          // the org superuser
            Role::MANAGER => self::MANAGER,
            Role::STAFF => self::STAFF,
        };
    }
}
