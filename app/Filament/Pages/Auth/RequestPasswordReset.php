<?php

namespace App\Filament\Pages\Auth;

use App\Support\Email;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Password-reset request. Like the login page, it normalises the submitted email (lowercase + trim) before
 * the broker looks the user up and keys the reset token — so a reset works for the lowercase-stored address
 * on any driver, not by accident of the collation (prompt 146). The reset flow is otherwise unchanged.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    /**
     * Prompt 288 — the reset email is a queued notification, so a TRANSPORT failure happens in the worker (retried there).
     * What can fail here is handing it to the queue (Redis down, say): that is a readable message on the form, never a
     * 500. Validation errors still reach the form as usual.
     */
    public function request(): void
    {
        try {
            parent::request();
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            Notification::make()
                ->title(__('No se pudo enviar el correo ahora mismo. Inténtalo de nuevo en unos minutos.'))
                ->danger()
                ->send();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'email' => Email::normalise(is_string($data['email'] ?? null) ? $data['email'] : null),
        ];
    }
}
