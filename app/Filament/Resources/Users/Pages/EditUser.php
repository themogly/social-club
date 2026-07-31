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
    }

    protected function afterSave(): void
    {
        /** @var User $user */
        $user = $this->getRecord();
        $after = $user->fresh()?->getRoleNames()->sort()->values()->all() ?? [];

        if ($after !== $this->rolesBefore) {
            (new RecordAuditLog)->handle('user.roles.updated', $user,
                ['roles' => $this->rolesBefore], ['roles' => $after]);
        }
    }
}
