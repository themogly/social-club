<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\RecordAuditLog;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var list<string> the staff user's roles before the save */
    private array $rolesBefore = [];

    /** Credential hashes before the save — compared, never logged (prompt 163). */
    private ?string $passwordBefore = null;

    private ?string $pinBefore = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    // Role/permission changes are audited (prompt 48) — who holds which role leaves a trace. The diff
    // names roles added/removed; no credential material (password/MFA) ever enters it.
    protected function beforeSave(): void
    {
        /** @var User $user */
        $user = $this->getRecord();
        $this->rolesBefore = $user->getRoleNames()->sort()->values()->all();
        $this->passwordBefore = $user->getRawOriginal('password');
        $this->pinBefore = $user->getRawOriginal('pin');
    }

    protected function afterSave(): void
    {
        /** @var User $user */
        $user = $this->getRecord();
        $fresh = $user->fresh();
        $after = $fresh?->getRoleNames()->sort()->values()->all() ?? [];

        if ($after !== $this->rolesBefore) {
            (new RecordAuditLog)->handle('user.roles.updated', $user,
                ['roles' => $this->rolesBefore], ['roles' => $after]);
        }

        // A credential change gets its OWN entry, so resetting someone's password is never
        // indistinguishable from a routine row edit in the trail (prompt 163). Deliberately no
        // before/after payload: the entry records THAT it happened, to whom, by whom and when — a
        // password hash in an audit row is credential material and must never be stored.
        if ($fresh?->getRawOriginal('password') !== $this->passwordBefore) {
            (new RecordAuditLog)->handle('user.password.updated', $user);
        }

        if ($fresh?->getRawOriginal('pin') !== $this->pinBefore) {
            (new RecordAuditLog)->handle('user.pin.updated', $user);
        }
    }
}
