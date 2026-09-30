<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\RecordAuditLog;
use App\Actions\Users\EnsureRoleChangeIsAllowed;
use App\Actions\Users\TestUserPin;
use App\Filament\Concerns\ReturnsToList;
use App\Filament\Forms\PinInput;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\PinSavedNotice;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class EditUser extends EditRecord
{
    use ReturnsToList;

    protected static string $resource = UserResource::class;

    /** @var list<string> the staff user's roles before the save */
    private array $rolesBefore = [];

    /** Credential hashes before the save — compared, never logged (prompt 163). */
    private ?string $passwordBefore = null;

    private ?string $pinBefore = null;

    protected function getHeaderActions(): array
    {
        return [
            $this->testPinAction(),
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /** Prompt 322 — does this PIN open the counter as this person? Yes or no, for this person only (see TestUserPin). */
    public function testPinAction(): Action
    {
        return Action::make('testPin')->label(__('Probar PIN'))->icon(Heroicon::OutlinedKey)->color('gray')
            ->visible(fn (): bool => Auth::user()?->can('update', $this->getRecord()) ?? false)
            ->modalHeading(fn (): string => __('Probar el PIN de :name', ['name' => (string) $this->getRecord()->getAttribute('name')]))
            ->modalDescription(__('Escribe un PIN para comprobar si es el de esta persona. Solo responde «Coincide» o «No coincide».'))
            ->modalSubmitActionLabel(__('Probar'))
            ->schema([PinInput::make('pin')->label(__('PIN'))->required()])
            ->action(function (array $data): void {
                /** @var User $actor */
                $actor = Auth::user();
                /** @var User $user */
                $user = $this->getRecord();
                $matched = (new TestUserPin)->handle($actor, $user, (string) $data['pin']);

                match ($matched) {
                    true => Notification::make()->success()->title(__('Coincide'))->send(),
                    false => Notification::make()->danger()->title(__('No coincide'))->send(),
                    null => Notification::make()->warning()->title(__('Demasiados intentos. Espera una hora antes de volver a probar un PIN.'))->send(),
                };
            });
    }

    // Role/permission changes are audited (prompt 48) — who holds which role leaves a trace. The diff
    // names roles added/removed; no credential material (password/MFA) ever enters it.
    private ?string $localeBefore = null;

    protected function beforeSave(): void
    {
        /** @var User $user */
        $user = $this->getRecord();
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        (new EnsureRoleChangeIsAllowed)->handle($actor, $user, (array) ($this->data['roles'] ?? []));
        $this->rolesBefore = $user->getRoleNames()->sort()->values()->all();
        $this->passwordBefore = $user->getRawOriginal('password');
        $this->pinBefore = $user->getRawOriginal('pin').'|'.$user->getRawOriginal('pin_lookup'); // prompt 286: either column
        $this->localeBefore = $user->getRawOriginal('locale');
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

        if ($fresh?->getRawOriginal('pin').'|'.$fresh?->getRawOriginal('pin_lookup') !== $this->pinBefore) {
            (new RecordAuditLog)->handle('user.pin.updated', $user);
            if ($fresh instanceof User && $fresh->hasPin()) {
                PinSavedNotice::send($fresh); // prompt 322 — where to try it, or that it won't work
            }
        }

        // Prompt 315 — someone else's language changed: the old and new values, nothing else about them.
        if ($fresh?->getRawOriginal('locale') !== $this->localeBefore) {
            (new RecordAuditLog)->handle('user.locale.changed', $user, ['locale' => $this->localeBefore], ['locale' => $fresh?->getRawOriginal('locale')]);
        }
    }
}
