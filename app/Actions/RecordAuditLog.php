<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Support\ActiveScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/** Writes one append-only audit entry. The canonical Action pattern reference. */
class RecordAuditLog
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function handle(string $action, ?Model $auditable = null, ?array $before = null, ?array $after = null): AuditLog
    {
        return AuditLog::create([
            'organisation_id' => app(ActiveScope::class)->organisationId(),
            'actor_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 255),
        ]);
    }
}
