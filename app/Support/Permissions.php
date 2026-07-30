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
        'membership.fee.override', 'carencia.waive',
        // Attendance
        'checkin.manage', 'checkin.override',
        // Counter
        'pos.use', 'pos.bar', 'dispensation.void', 'order.void', 'limits.override',
        // Catalogue & stock
        'genetics.manage', 'prices.manage', 'stock.manage', 'stock.merma', 'stock.transfer',
        'stock.take', 'articles.manage', 'discounts.manage',
        // Money
        'wallet.adjust', 'till.open', 'till.close', 'cash.bank', 'expenses.record',
        'expenses.approve', 'expenses.overheads', 'expenses.categories', 'purchases.manage',
        // Governance
        'documents.generate', 'minutes.manage', 'register.view',
        // Communications (announcements + events, member PWA)
        'comms.manage',
        // Privacy
        'data.request.handle', 'data.erase',
        // System
        'locations.manage', 'staff.manage', 'settings.manage', 'settings.manage.location', 'audit.view',
    ];

    /** MANAGER — per assigned location. Broad operational power, minus org-wide compliance/privacy. */
    private const MANAGER = [
        'reports.view', 'reports.export',
        'members.view', 'members.create', 'members.edit', 'members.transfer', 'members.import',
        'member.sanction', 'applications.review',
        'membership.fee.override', 'carencia.waive',
        'checkin.manage', 'checkin.override',
        'pos.use', 'pos.bar', 'dispensation.void', 'order.void', 'limits.override',
        'genetics.manage', 'prices.manage', 'stock.manage', 'stock.merma', 'stock.transfer',
        'stock.take', 'articles.manage', 'discounts.manage',
        'wallet.adjust', 'till.open', 'till.close', 'cash.bank', 'expenses.record',
        'expenses.approve', 'purchases.manage',
        'documents.generate', 'register.view',
        'comms.manage',
        'settings.manage.location',
    ];

    /** STAFF — per assigned location. Counter + door + basic member intake only. */
    private const STAFF = [
        'pos.use', 'pos.bar', 'checkin.manage',
        'members.view', 'members.create',
        'expenses.record', 'till.open',
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
