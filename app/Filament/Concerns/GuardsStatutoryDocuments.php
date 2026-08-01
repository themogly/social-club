<?php

namespace App\Filament\Concerns;

use App\Support\OrganisationIdentity;
use Filament\Notifications\Notification;

/**
 * A statutory document (libro de socios, registro de dispensación, convocatoria, acta) must name the
 * responsible asociación by its LEGAL name (prompt 115). This refuses to generate one when the organisation
 * has no legal name set, telling the admin where to fix it, rather than emitting a document that silently
 * prints the trading name as if it were the legal identity.
 */
trait GuardsStatutoryDocuments
{
    protected static function missingLegalNameNotice(): void
    {
        Notification::make()
            ->danger()
            ->title(__('Falta el nombre legal de la asociación'))
            ->body(__('Añade el nombre legal de la asociación en los ajustes antes de generar un documento estatutario.'))
            ->send();
    }

    /**
     * Guard shorthand: false (and a notice sent) when the org has no legal name. Static so it serves both a
     * Filament Page method and a Resource action closure (which has no bound $this).
     */
    protected static function hasStatutoryIdentity(): bool
    {
        if (! OrganisationIdentity::hasLegalName()) {
            static::missingLegalNameNotice();

            return false;
        }

        return true;
    }
}
