<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Users\EnsureRoleChangeIsAllowed;
use App\Filament\Concerns\ReturnsToList;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\PinSavedNotice;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateUser extends CreateRecord
{
    use ReturnsToList;

    protected static string $resource = UserResource::class;

    /** Prompt 270 — only an owner creates an owner, whatever the form was made to submit. */
    protected function beforeCreate(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        (new EnsureRoleChangeIsAllowed)->handle($actor, null, (array) ($this->data['roles'] ?? []));
    }

    /** Prompt 322 — a PIN was given: say where to try it, or that it won't work (the sedes are saved by now). */
    protected function afterCreate(): void
    {
        $user = $this->getRecord();
        if ($user instanceof User && $user->hasPin()) {
            PinSavedNotice::send($user->fresh() ?? $user);
        }
    }
}
