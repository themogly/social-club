<?php

namespace App\ViewModels;

use App\Models\Organisation;
use App\Support\ActiveScope;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * Registro de Actividades de Tratamiento (RGPD Art. 30). GENERATED from the actual system —
 * the controller identity from the Organisation, retention periods from Settings, and the
 * data categories from what the models really hold — not a hand-typed PDF. Cannabis
 * consumption and therapeutic status are marked explicitly as Article 9 special-category
 * (health) data. Nothing here is legal advice.
 */
class Rat
{
    /**
     * The data controller (responsable del tratamiento) — the active organisation.
     *
     * @return array{name: string, legal_name: ?string, tax_id: ?string, address: ?string, contact_email: ?string}
     */
    public function controller(): array
    {
        $org = Organisation::query()->whereKey(app(ActiveScope::class)->organisationId())->first();

        return [
            'name' => $org->name ?? (string) config('app.name'),
            'legal_name' => $org?->legal_name,
            'tax_id' => $org?->tax_id,
            'address' => $org?->address,
            'contact_email' => $org?->contact_email,
        ];
    }

    public function generatedAt(): Carbon
    {
        return now();
    }

    /** Shared technical & organisational security measures (RGPD Art. 32). */
    public function securityMeasures(): string
    {
        return __('Cifrado en reposo de datos sensibles (documento de identidad), seudonimización mediante índice ciego, control de acceso por roles, autenticación multifactor disponible, registro de accesos a documentos y registro de auditoría inalterable, URLs firmadas de corta duración, anonimización automática al vencer el periodo de conservación.');
    }

    /**
     * The processing activities, each derived from the real model surface.
     *
     * @return list<array{ref: string, name: string, purpose: string, legal_basis: string, data_categories: list<string>, article_9: bool, recipients: string, transfers: string, retention: string}>
     */
    public function activities(): array
    {
        $memberRetention = $this->retentionText((int) Settings::get('data_retention_days', 1825), true);
        $auditRetention = $this->retentionText((int) Settings::get('audit_retention_days', 3650), false);
        $ttl = (int) Settings::get('signed_url_ttl_seconds', 300);
        $noTransfer = __('Sin transferencias internacionales.');
        $noRecipients = __('Sin cesiones, salvo obligación legal o requerimiento judicial.');

        return [
            [
                'ref' => 'RAT-01',
                'name' => __('Gestión de socios y membresías'),
                'purpose' => __('Alta, mantenimiento y baja de socios; control de avales, cuotas y estado de la membresía.'),
                'legal_basis' => __('Ejecución de la relación asociativa (estatutos) y consentimiento del interesado.'),
                'data_categories' => [
                    __('Identificativos (nombre, nº de socio)'),
                    __('Documento de identidad (cifrado)'),
                    __('Datos de contacto (email, teléfono, dirección)'),
                    __('Fecha de nacimiento'),
                ],
                'article_9' => false,
                'recipients' => $noRecipients,
                'transfers' => $noTransfer,
                'retention' => $memberRetention,
            ],
            [
                'ref' => 'RAT-02',
                'name' => __('Registro de consumo y dispensaciones'),
                'purpose' => __('Registro de la aportación de cannabis por peso a socios, aplicación de límites diarios/mensuales y previsión de consumo declarada.'),
                'legal_basis' => __('Consentimiento explícito para datos de categoría especial (Art. 9.2.a RGPD).'),
                'data_categories' => [
                    __('Consumo de cannabis (peso dispensado, historial)'),
                    __('Condición terapéutica declarada (dato de salud)'),
                    __('Previsión mensual declarada'),
                ],
                'article_9' => true,
                'recipients' => $noRecipients,
                'transfers' => $noTransfer,
                'retention' => $memberRetention,
            ],
            [
                'ref' => 'RAT-03',
                'name' => __('Documentos de identidad y fotografías'),
                'purpose' => __('Verificación de identidad y mayoría de edad; conservación de la copia del documento y la fotografía del socio.'),
                'legal_basis' => __('Obligación de verificar la mayoría de edad y consentimiento del interesado.'),
                'data_categories' => [
                    __('Copia del documento de identidad (cifrada, disco privado)'),
                    __('Fotografía del socio'),
                ],
                'article_9' => false,
                'recipients' => $noRecipients,
                'transfers' => $noTransfer,
                'retention' => __(':base Acceso solo mediante URL firmada (:ttl s) y registrado.', ['base' => $memberRetention, 'ttl' => $ttl]),
            ],
            [
                'ref' => 'RAT-04',
                'name' => __('Cartera, cuotas y tesorería'),
                'purpose' => __('Gestión del monedero del socio, cuotas de membresía, arqueos de caja y contabilidad interna.'),
                'legal_basis' => __('Ejecución de la relación asociativa y obligación legal (contable y fiscal).'),
                'data_categories' => [
                    __('Movimientos económicos y saldo del monedero'),
                    __('Pagos de cuotas'),
                ],
                'article_9' => false,
                'recipients' => __('Asesoría contable y Administración tributaria cuando exista obligación legal.'),
                'transfers' => $noTransfer,
                'retention' => __('El plazo legal de conservación contable y fiscal (con carácter general, varios años).'),
            ],
            [
                'ref' => 'RAT-05',
                'name' => __('Control de acceso y aforo'),
                'purpose' => __('Registro de entradas y salidas del local y control del aforo.'),
                'legal_basis' => __('Interés legítimo en la seguridad del local y control de aforo.'),
                'data_categories' => [
                    __('Registros de entrada/salida (fecha y hora)'),
                ],
                'article_9' => false,
                'recipients' => $noRecipients,
                'transfers' => $noTransfer,
                'retention' => $memberRetention,
            ],
            [
                'ref' => 'RAT-06',
                'name' => __('Comunicaciones a socios'),
                'purpose' => __('Envío de avisos, convocatorias de eventos y notificaciones push a los socios que lo consienten.'),
                'legal_basis' => __('Consentimiento del interesado, revocable en cualquier momento.'),
                'data_categories' => [
                    __('Datos de contacto y preferencias de notificación'),
                ],
                'article_9' => false,
                'recipients' => $noRecipients,
                'transfers' => $noTransfer,
                'retention' => __('Hasta la retirada del consentimiento o la baja del socio.'),
            ],
            [
                'ref' => 'RAT-07',
                'name' => __('Seguridad: auditoría y registro de accesos'),
                'purpose' => __('Registro inalterable de acciones y de accesos a documentos sensibles, para seguridad y trazabilidad.'),
                'legal_basis' => __('Obligación legal e interés legítimo en la seguridad del tratamiento (Art. 32 RGPD).'),
                'data_categories' => [
                    __('Identificador del actor, dirección IP, acción y fecha'),
                    __('Accesos a documentos de identidad'),
                ],
                'article_9' => false,
                'recipients' => $noRecipients,
                'transfers' => $noTransfer,
                'retention' => __(':base — deliberadamente más larga que la de los datos de socio, para poder evidenciar accesos pasados.', ['base' => $auditRetention]),
            ],
        ];
    }

    /** True when at least one activity processes Article 9 special-category data. */
    public function hasArticle9(): bool
    {
        foreach ($this->activities() as $activity) {
            if ($activity['article_9']) {
                return true;
            }
        }

        return false;
    }

    private function retentionText(int $days, bool $sinceLeaving): string
    {
        $years = round($days / 365, 1);
        $yearsText = $years == (int) $years ? (string) (int) $years : number_format($years, 1, ',', '.');

        return $sinceLeaving
            ? __(':days días (~:years años) desde la baja del socio.', ['days' => $days, 'years' => $yearsText])
            : __(':days días (~:years años).', ['days' => $days, 'years' => $yearsText]);
    }
}
