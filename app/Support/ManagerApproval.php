<?php

namespace App\Support;

use App\Models\User;

/**
 * Prompt 333 — the owner: "If they're managers, don't require reasons for adjusting a price or for waiving a fee, as a
 * default setting. Instead just put 'manager approved'."
 *
 * `reasons.optional` (*Aprobar sin motivo*): OWNER and MANAGER by default, never STAFF, editable on *Roles y
 * permisos*. Whoever holds it may leave the reason of a PRICE ADJUSTMENT or a FEE WAIVER as "Aprobado por
 * responsable", which is then the reason stored. The record is never anonymous: the dispensation's
 * `price_override_by`, the waiver's operator and the audit row still name the person, and the audit payload says the
 * reason was waived through this permission. Nothing else changes — every other reason prompt still requires one.
 */
final class ManagerApproval
{
    public const PERMISSION = 'reasons.optional';

    /** The audit payload key recording that the reason came from the permission, not from typing. */
    public const AUDIT_KEY = 'reason_permission';

    /** The reason stored when a holder gives none. */
    public static function reason(): string
    {
        return __('Aprobado por responsable');
    }

    public static function allows(?User $user): bool
    {
        return $user?->can(self::PERMISSION) ?? false;
    }

    /** Was this reason the permission's own text, given by someone who holds it? */
    public static function applies(?User $user, ?string $reason): bool
    {
        return self::allows($user) && trim((string) $reason) === self::reason();
    }
}
