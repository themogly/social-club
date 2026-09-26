<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One deliberate difference from the code's default for a role (prompt 262): `granted` true = the club added a
 * permission the default leaves out; false = it took one away. Written only by `SetRolePermission` /
 * `RestoreRoleDefaults` from Sistema ▸ Roles y permisos, applied by `Permissions::for()` on every sync.
 */
class RolePermissionOverride extends Model
{
    use HasUlids;

    protected $fillable = ['role', 'permission', 'granted', 'set_by'];

    protected function casts(): array
    {
        return ['granted' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
