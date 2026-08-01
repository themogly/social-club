<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BreachLog;
use App\Models\Convocatoria;
use App\Models\DataRequest;
use App\Models\Discount;
use App\Models\Dispensation;
use App\Models\DocumentTemplate;
use App\Models\Event;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Genetic;
use App\Models\Location;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\MemberDocument;
use App\Models\MembershipTier;
use App\Models\Minute;
use App\Models\Order;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\TillSession;
use App\Models\User;

/**
 * The single registry of in-app help content (prompt 92). It is CONTENT ONLY — it never changes behaviour,
 * gates an action, or restates a business rule as a number (rules live in code; where help mentions a
 * threshold it names it — "the daily limit" — never a value that is actually a Setting). Every string here
 * is a Spanish source string wrapped in `__()` at render time, so it flows through the prompt-19 lang
 * pipeline and its English is parity-gated; there is no parallel content store to rot.
 *
 * Three surfaces read this: table empty states (keyed by model), the per-screen help panel (topic keyed by
 * model/page), and the glossary of the club's terms of art (which stay Spanish — the glossary EXPLAINS them,
 * it does not translate them away).
 */
class Help
{
    /**
     * Empty-state copy per resource model: what the records are, why they matter, the first action.
     * Keyed by model FQCN so one global Table default (AppServiceProvider) applies it to every table.
     *
     * @var array<class-string, array{heading: string, description: string}>
     */
    public const EMPTY_STATES = [
        Member::class => ['heading' => 'Todavía no hay socios', 'description' => 'Los socios (miembros de la asociación) se dan de alta aquí. Crea el primero o revisa las solicitudes de alta.'],
        MemberApplication::class => ['heading' => 'Sin solicitudes', 'description' => 'Las solicitudes de alta que envían los aspirantes aparecen aquí para su revisión y aprobación.'],
        MemberDocument::class => ['heading' => 'Sin documentos', 'description' => 'La documentación de cada socio (identidad, consentimientos, certificados) se guarda cifrada y se consulta desde su ficha.'],
        MembershipTier::class => ['heading' => 'Sin cuotas de socio', 'description' => 'Una cuota (tarifa) define el importe y periodicidad de la aportación de socio. Crea la primera para poder enrolar socios.'],
        Genetic::class => ['heading' => 'Sin genéticas', 'description' => 'Una genética es la definición de una variedad. Para dispensarla necesitará además un precio por sede y un lote con stock.'],
        Batch::class => ['heading' => 'Sin lotes', 'description' => 'Un lote es stock real de una genética en una sede, con su número, fecha y peso. Registra una compra o una entrada para crear el primero.'],
        Article::class => ['heading' => 'Sin artículos', 'description' => 'Los artículos son productos de barra y tienda (bebidas, comida, merch), contados por unidades. Crea el primero para venderlo en la barra.'],
        Discount::class => ['heading' => 'Sin descuentos', 'description' => 'Los descuentos reducen la aportación de determinados socios (p. ej. terapéuticos). Se aplican en el mostrador automáticamente.'],
        Dispensation::class => ['heading' => 'Sin dispensaciones', 'description' => 'Las dispensaciones (aportaciones por peso) se registran en el mostrador. Esta pantalla es solo de consulta.'],
        Order::class => ['heading' => 'Sin ventas de barra', 'description' => 'Las ventas de barra y tienda se registran en el TPV de barra. Esta pantalla es solo de consulta.'],
        Expense::class => ['heading' => 'Sin gastos', 'description' => 'Los gastos registran salidas de dinero (caja chica y generales). Registra el primero para que cuadren las cuentas.'],
        ExpenseCategory::class => ['heading' => 'Sin categorías de gasto', 'description' => 'Las categorías agrupan los gastos para los informes. Crea las que use el club (alquiler, suministros, etc.).'],
        Purchase::class => ['heading' => 'Sin compras', 'description' => 'Una compra registra la entrada de stock de un proveedor y su coste. Genera el lote y su coste por gramo.'],
        Supplier::class => ['heading' => 'Sin proveedores', 'description' => 'Los proveedores son de quienes el club adquiere género o artículos. Crea el primero para registrar compras.'],
        TillSession::class => ['heading' => 'Sin cajas', 'description' => 'Las sesiones de caja se abren y cierran en el terminal del mostrador. Esta pantalla es solo de supervisión.'],
        Location::class => ['heading' => 'Sin sedes', 'description' => 'Una sede es un local del club, con su propio stock, caja y aforo. Crea la primera para empezar a operar.'],
        User::class => ['heading' => 'Sin usuarios', 'description' => 'Los usuarios son el personal con acceso. Cada uno necesita un rol y una o varias sedes; sin rol no puede entrar al panel.'],
        Minute::class => ['heading' => 'Sin actas', 'description' => 'Un acta es el registro formal de una asamblea o junta. Se numera sola y, una vez firmada, es inmutable.'],
        Convocatoria::class => ['heading' => 'Sin convocatorias', 'description' => 'Una convocatoria cita a la asamblea general y notifica por email a los socios. Crea una y luego emítela.'],
        Announcement::class => ['heading' => 'Sin avisos', 'description' => 'Los avisos son comunicaciones para los socios en su PWA. Publica el primero para que lo vean.'],
        Event::class => ['heading' => 'Sin eventos', 'description' => 'Los eventos aparecen en la PWA del socio y admiten confirmación de asistencia. Crea el primero.'],
        DocumentTemplate::class => ['heading' => 'Sin plantillas', 'description' => 'Las plantillas generan documentos formales (altas, certificados) con datos del socio congelados al emitir.'],
        DataRequest::class => ['heading' => 'Sin solicitudes de datos', 'description' => 'Las solicitudes RGPD (acceso o supresión) de los socios se gestionan aquí. Aparecerán cuando lleguen.'],
        AuditLog::class => ['heading' => 'Sin registros', 'description' => 'El registro de auditoría es un histórico de solo lectura, inalterable, de las acciones sensibles del sistema.'],
        BreachLog::class => ['heading' => 'Sin incidencias', 'description' => 'El registro de brechas documenta incidentes de seguridad o privacidad para el cumplimiento del RGPD.'],
    ];

    /**
     * Per-screen help: what the screen is for, the two or three things you do, and anything with
     * consequences. Keyed by model FQCN (resources) or page FQCN (pages). Body is a list of paragraphs.
     *
     * @var array<class-string, array{title: string, body: list<string>}>
     */
    public const TOPICS = [
        Member::class => ['title' => 'Socios', 'body' => [
            'El libro de socios de la asociación. Desde aquí das de alta socios, editas sus datos y abres su ficha (cuotas, monedero, consumo, documentos).',
            'Un socio necesita una cuota (membresía) activa y estar al corriente para poder recibir dispensaciones en el mostrador.',
        ]],
        Genetic::class => ['title' => 'Genéticas', 'body' => [
            'Define las variedades. Una genética por sí sola NO aparece en el mostrador: necesita además un precio por sede (por gramo, y opcionalmente por octavo) y un lote con stock.',
            'El tipo (flor por peso, o unidades como prerolls) determina cómo se dispensa.',
        ]],
        Batch::class => ['title' => 'Lotes', 'body' => [
            'Cada lote es stock real de una genética en una sede. El stock se mueve siempre por el registro de movimientos, nunca a mano.',
            'Consecuencias: poner un lote en cuarentena o cerrarlo lo retira del mostrador. La retirada muestra quién recibió producto de un lote.',
        ]],
        User::class => ['title' => 'Usuarios', 'body' => [
            'El personal con acceso al panel y al mostrador. Cada usuario necesita un ROL (sin él no entra) y una o más SEDES asignadas; para identificarse en el mostrador necesita un PIN.',
        ]],
        Location::class => ['title' => 'Sedes', 'body' => [
            'Cada sede es un local con su propio stock, caja y aforo. Los ajustes por sede (aforo, exigir check-in, firma) se configuran en su ficha.',
            'Consecuencia: una sede sin precios es un mostrador que no puede dispensar nada.',
        ]],
        Minute::class => ['title' => 'Actas', 'body' => [
            'El libro de actas de asambleas y juntas. La numeración y el quórum se calculan solos.',
            'Consecuencia: firmar un acta la vuelve INMUTABLE. Una corrección no se edita: se crea un acta nueva vinculada.',
        ]],
        Convocatoria::class => ['title' => 'Convocatorias', 'body' => [
            'Convoca la asamblea y notifica por email a los socios. Al EMITIRLA se fija la lista de convocados (a día de hoy) y se envía un email por socio; después es inmutable.',
            'Respeta el plazo mínimo de convocatoria configurado.',
        ]],
        Expense::class => ['title' => 'Gastos', 'body' => [
            'Registra salidas de dinero. La caja chica sale del cajón del mostrador; los gastos generales, no. Por encima del umbral configurado requieren aprobación.',
        ]],
    ];

    /**
     * The glossary of the club's terms of art. Spanish is authoritative — these are legally load-bearing
     * (aportación vs venta is an association vs a shop). The English UI keeps the term and explains it.
     *
     * @var array<string, string>
     */
    public const GLOSSARY = [
        'Socio' => 'Miembro de la asociación. Nunca "cliente": el CSC es una asociación de socios, no un comercio.',
        'Aportación' => 'La contribución al coste compartido que hace el socio al recibir producto. Nunca una "venta" ni un "precio de venta": esa distinción es la que separa una asociación legal de una tienda ilegal.',
        'Dispensación' => 'La entrega de producto por peso a un socio, registrada como aportación en el mostrador.',
        'Cuota' => 'La aportación periódica de socio (membresía). Estar al corriente de la cuota es requisito para dispensar.',
        'Carencia' => 'El período de espera obligatorio desde el alta antes de la primera dispensación.',
        'Aforo' => 'El número máximo de personas permitido simultáneamente en una sede.',
        'Arqueo' => 'El recuento del efectivo del cajón al cerrar la caja. Se hace a ciegas: se cuenta antes de ver la cifra esperada.',
        'Merma' => 'Una pérdida de stock (rotura, deterioro, decomiso), registrada como reducción en el inventario.',
        'Avalador' => 'El socio que avala (presenta) a un aspirante para su alta.',
        'Aval' => 'La presentación de un aspirante por parte de un socio avalador.',
        'Acta' => 'El registro formal de una asamblea o junta directiva. Una vez firmada, es inmutable.',
        'Convocatoria' => 'La citación formal a una asamblea, con su orden del día y notificación a los socios.',
        'Libro de socios' => 'El registro oficial de socios de la asociación, con altas y bajas.',
        'Baja' => 'La salida de un socio de la asociación. El libro de socios conserva a quienes se han dado de baja.',
        'Octavo' => 'Un octavo de onza (3,5 g), unidad habitual con posible precio propio como descuento por cantidad.',
        'Barra y tienda' => 'El ingreso auxiliar no cannábico (bebidas, comida, merch), en un libro separado para no mezclarlo con la contabilidad de la asociación.',
        'Superávit' => 'El excedente de ingresos sobre gastos de la asociación (no un "beneficio": el CSC es sin ánimo de lucro).',
    ];

    /** @return array{heading: string, description: string}|null */
    public static function emptyStateFor(string $modelClass): ?array
    {
        return self::EMPTY_STATES[$modelClass] ?? null;
    }

    /** @return array{title: string, body: list<string>}|null */
    public static function topicFor(string $key): ?array
    {
        return self::TOPICS[$key] ?? null;
    }

    /** @return array<string, string> */
    public static function glossary(): array
    {
        return self::GLOSSARY;
    }
}
