<?php

namespace App\Support;

use App\Actions\Pricing\ResolvePrice;
use App\Filament\Pages\Asamblea;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ExportacionContable;
use App\Filament\Pages\FailedJobs;
use App\Filament\Pages\LibroSocios;
use App\Filament\Pages\ManageEnforcement;
use App\Filament\Pages\ManageSettings;
use App\Filament\Pages\Rat;
use App\Filament\Pages\RegistroDispensacion;
use App\Filament\Pages\Reports\AgmPackReportPage;
use App\Filament\Pages\Reports\AttendanceReportPage;
use App\Filament\Pages\Reports\BarSalesReportPage;
use App\Filament\Pages\Reports\ConsumptionReportPage;
use App\Filament\Pages\Reports\DebtorReportPage;
use App\Filament\Pages\Reports\FinancialReportPage;
use App\Filament\Pages\Reports\MembersReportPage;
use App\Filament\Pages\Reports\StockReportPage;
use App\Filament\Pages\Reports\TillReportPage;
use App\Filament\Pages\Seguridad;
use App\Filament\Pages\SystemHealth;
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
     * Each topic declares the `permission` that grants access to its screen — DERIVED from the resource's
     * own policy (prompt 99), never invented. The help index shows a reader only the topics they can act on
     * (`topicsVisibleTo`); `null` means everyone with panel access. NEVER restate a rule as a number here —
     * name the Setting, because the value is configurable and regional.
     *
     * @var array<class-string, array{title: string, body: list<string>, permission: string|null}>
     */
    public const TOPICS = [
        // — Socios y accesos —
        Member::class => ['permission' => 'members.view', 'title' => 'Socios', 'body' => [
            'El libro de socios de la asociación. Desde aquí das de alta socios, editas sus datos y abres su ficha (cuotas, monedero, consumo, documentos).',
            'Un socio necesita una cuota (membresía) activa y estar al corriente para poder recibir dispensaciones en el mostrador.',
        ]],
        MemberApplication::class => ['permission' => 'applications.review', 'title' => 'Solicitudes de alta', 'body' => [
            'La cola de solicitudes que llegan por el formulario de alta. Revisas cada una, compruebas edad y documento, y la apruebas o la rechazas; al aprobar se crea el socio y su primera cuota.',
            'Rechazar no borra la solicitud: queda el registro de la decisión.',
        ]],
        MemberDocument::class => ['permission' => 'member.documents.view', 'title' => 'Documentos del socio', 'body' => [
            'Los documentos de identidad y certificados de los socios. Se guardan cifrados en un disco privado y solo se abren con un enlace firmado de corta duración; cada apertura queda registrada.',
            'Nunca se sirven por una ruta adivinable: es un requisito de protección de datos, no una preferencia.',
        ]],
        MembershipTier::class => ['permission' => 'settings.manage', 'title' => 'Cuotas de socio', 'body' => [
            'Los tipos de cuota (membresía) que puede tener un socio: su importe y su periodicidad.',
            'Estar al corriente de una cuota activa es requisito para dispensar en el mostrador.',
        ]],
        User::class => ['permission' => 'staff.manage', 'title' => 'Usuarios', 'body' => [
            'El personal con acceso al panel y al mostrador. Cada usuario necesita un ROL (sin él no entra) y una o más SEDES asignadas; para identificarse en el mostrador necesita un PIN.',
        ]],
        Location::class => ['permission' => 'settings.manage.location', 'title' => 'Sedes', 'body' => [
            'Cada sede es un local con su propio stock, caja y aforo. Los ajustes por sede (aforo, exigir check-in, firma) se configuran en su ficha.',
            'Consecuencia: una sede sin precios es un mostrador que no puede dispensar nada.',
        ]],

        // — Catálogo y stock —
        Genetic::class => ['permission' => 'genetics.manage', 'title' => 'Genéticas', 'body' => [
            'Define las variedades. Una genética por sí sola NO aparece en el mostrador: necesita además un precio por sede (por gramo, y opcionalmente por octavo) y un lote con stock.',
            'El tipo (flor por peso, o unidades como prerolls) determina cómo se dispensa.',
        ]],
        Batch::class => ['permission' => 'stock.manage', 'title' => 'Lotes', 'body' => [
            'Cada lote es stock real de una genética en una sede. El stock se mueve siempre por el registro de movimientos, nunca a mano.',
            'Consecuencias: poner un lote en cuarentena o cerrarlo lo retira del mostrador. La retirada muestra quién recibió producto de un lote.',
        ]],
        Article::class => ['permission' => 'articles.manage', 'title' => 'Artículos', 'body' => [
            'Los artículos de barra y tienda (bebidas, comida, merch) y las unidades como prerolls o comestibles. Se dispensan o venden por unidad.',
            'El ingreso de barra y tienda va a un libro aparte, nunca a la contabilidad de la asociación; una genética nunca aparece aquí.',
        ]],
        Discount::class => ['permission' => 'discounts.manage', 'title' => 'Descuentos', 'body' => [
            'Los descuentos sobre la aportación: por tramo, por colectivo (p. ej. terapéutico) o asignados a un socio concreto.',
            'Por defecto no se acumulan: se aplica el mejor descuento único. El precio se congela en la dispensación al confirmarla.',
        ]],
        Purchase::class => ['permission' => 'purchases.manage', 'title' => 'Compras', 'body' => [
            'Las compras a proveedores. Una compra de producto lleva su coste por gramo al lote, que es lo que alimenta los informes de coste.',
            'Las compras de material no tocan el stock de genéticas.',
        ]],
        Supplier::class => ['permission' => 'purchases.manage', 'title' => 'Proveedores', 'body' => [
            'Los proveedores a los que se compra producto y material. Se vinculan a cada compra para poder seguir su origen.',
        ]],

        // — Mostrador (dispensación y barra) —
        Dispensation::class => ['permission' => 'pos.use', 'title' => 'Dispensaciones', 'body' => [
            'El registro de dispensaciones: cada entrega de producto por peso a un socio, con su aportación.',
            'Es de solo lectura. Para corregir una, se anula y se crea una nueva vinculada, nunca se edita en silencio.',
        ]],
        Order::class => ['permission' => 'pos.bar', 'title' => 'Barra y tienda', 'body' => [
            'El registro de barra y tienda (Barra y tienda): las ventas por unidad de bebidas, comida y merch.',
            'Es de solo lectura. Una venta se anula, devolviendo la unidad al stock y revirtiendo el monedero; nunca se edita.',
        ]],
        TillSession::class => ['permission' => 'till.open', 'title' => 'Sesiones de caja', 'body' => [
            'Las sesiones de caja de cada terminal: apertura con fondo, movimientos de efectivo y cierre a ciegas.',
            'El efectivo esperado se calcula del registro, nunca se guarda. Esta pantalla es de solo lectura, para supervisión.',
        ]],

        // — Dinero —
        Expense::class => ['permission' => 'expenses.record', 'title' => 'Gastos', 'body' => [
            'Registra salidas de dinero. La caja chica sale del cajón del mostrador; los gastos generales, no.',
            'Por encima del umbral configurado, un gasto requiere aprobación de un responsable.',
        ]],
        ExpenseCategory::class => ['permission' => 'expenses.categories', 'title' => 'Categorías de gasto', 'body' => [
            'Las categorías con las que se clasifican los gastos, para que los informes cuadren.',
            'Cambiar una categoría no reclasifica los gastos ya registrados.',
        ]],

        // — Gobernanza y comunicación —
        Minute::class => ['permission' => 'minutes.manage', 'title' => 'Actas', 'body' => [
            'El libro de actas de asambleas y juntas. La numeración y el quórum se calculan solos.',
            'Consecuencia: firmar un acta la vuelve INMUTABLE. Una corrección no se edita: se crea un acta nueva vinculada.',
        ]],
        Convocatoria::class => ['permission' => 'minutes.manage', 'title' => 'Convocatorias', 'body' => [
            'Convoca la asamblea y notifica por email a los socios. Al EMITIRLA se fija la lista de convocados (a día de hoy) y se envía un email por socio; después es inmutable.',
            'Respeta el plazo mínimo de convocatoria configurado.',
        ]],
        Announcement::class => ['permission' => 'comms.manage', 'title' => 'Avisos', 'body' => [
            'Los avisos que se publican a los socios en su app. Publicar hace visible el aviso; puedes fijarlo arriba.',
        ]],
        Event::class => ['permission' => 'comms.manage', 'title' => 'Eventos', 'body' => [
            'Los eventos del club que se muestran a los socios en su app. Publicarlos los hace visibles y permite recoger asistencia.',
        ]],
        DocumentTemplate::class => ['permission' => 'documents.generate', 'title' => 'Plantillas de documentos', 'body' => [
            'Las plantillas de los documentos que emite el club (certificados, cartas). El documento final lleva los datos del socio y queda guardado como PDF.',
        ]],

        // — Cumplimiento, privacidad y auditoría —
        AuditLog::class => ['permission' => 'audit.view', 'title' => 'Auditoría', 'body' => [
            'El registro de auditoría: quién hizo qué y cuándo. Solo se añade — no se puede editar ni borrar.',
        ]],
        BreachLog::class => ['permission' => 'audit.view', 'title' => 'Brechas de datos', 'body' => [
            'El registro de brechas de seguridad de datos (RGPD): cada incidente, su alcance y las notificaciones hechas.',
            'Documenta la obligación de aviso; registrarla no la sustituye.',
        ]],
        DataRequest::class => ['permission' => 'data.request.handle', 'title' => 'Solicitudes RGPD', 'body' => [
            'Las solicitudes de derechos de los socios (acceso, rectificación, supresión). Cada una lleva su plazo y su resolución registrada.',
        ]],
    ];

    /**
     * Topics for the admin PAGES (dashboards, registers, settings, reports) — keyed by the page class, so
     * the coverage test can walk every page. Same shape and permission rule as TOPICS.
     *
     * @var array<class-string, array{title: string, body: list<string>, permission: string|null}>
     */
    public const PAGE_TOPICS = [
        Dashboard::class => ['permission' => null, 'title' => 'Panel de inicio', 'body' => [
            'La foto del día de tu sede (aforo, caja, dispensaciones) o, si eres propietario/a, la consolidación de toda la organización.',
            'Todas las cifras se consultan en vivo, nunca se guardan en caché.',
        ]],
        Seguridad::class => ['permission' => 'lockdown.manage', 'title' => 'Seguridad', 'body' => [
            'Desde aquí se activa el bloqueo de seguridad ante una amenaza, se ensaya con un simulacro y se consulta el historial de activaciones.',
            'El guion completo de qué hacer y cómo se vuelve a entrar está en el Manual, en «Bloqueo de seguridad».',
        ]],
        ManageSettings::class => ['permission' => 'settings.manage', 'title' => 'Ajustes de la organización', 'body' => [
            'Los umbrales de cumplimiento del club: edad mínima, carencia, límites de gramos, aforo, política de avalador…',
            'Cada umbral es configurable porque la práctica regional varía y la jurisprudencia cambia. Cambiar uno afecta al mostrador de inmediato.',
        ]],
        ManageEnforcement::class => ['permission' => 'settings.manage', 'title' => 'Aplicación de límites', 'body' => [
            'Aquí decides si cada límite BLOQUEA en el mostrador o solo avisa, y quién puede autorizar una excepción.',
            'Un bloqueo no se puede saltar sin una autorización motivada, que queda registrada.',
        ]],
        Rat::class => ['permission' => 'audit.view', 'title' => 'Registro de actividades de tratamiento', 'body' => [
            'El registro RGPD de qué datos trata el club, con qué base legal y durante cuánto tiempo.',
            'Es una obligación documental: registrarla no implica que quede cumplida por sí sola.',
        ]],
        LibroSocios::class => ['permission' => 'register.view', 'title' => 'Libro de socios', 'body' => [
            'El libro oficial de socios: altas y bajas con sus fechas. Conserva a quienes se han dado de baja — es el registro legal de la asociación.',
        ]],
        RegistroDispensacion::class => ['permission' => 'reports.view', 'title' => 'Registro de dispensación', 'body' => [
            'El registro de dispensaciones del período, para consulta e impresión. Refleja lo dispensado; no lo modifica.',
        ]],
        ExportacionContable::class => ['permission' => 'reports.export', 'title' => 'Exportación contable', 'body' => [
            'La exportación de los apuntes para la contabilidad. Separa la aportación de socios del ingreso de barra y tienda, para no mezclar la contabilidad de la asociación.',
        ]],
        SystemHealth::class => ['permission' => 'audit.view', 'title' => 'Estado del sistema', 'body' => [
            'El estado técnico de la plataforma (colas, correo, almacenamiento). Sirve para diagnosticar; no cambia nada del club.',
        ]],
        FailedJobs::class => ['permission' => 'audit.view', 'title' => 'Trabajos fallidos', 'body' => [
            'Los trabajos en segundo plano que han fallado (correos, avisos). Desde aquí se reintentan; un fallo no debe perderse en silencio.',
        ]],
        AgmPackReportPage::class => ['permission' => 'reports.view.all', 'title' => 'Dossier de asamblea', 'body' => [
            'El paquete para la asamblea general: socios, cuentas y actividad del período reunidos en un solo dossier.',
        ]],
        Asamblea::class => ['permission' => 'minutes.manage', 'title' => 'Asamblea', 'body' => [
            'Celebra la asamblea de una convocatoria emitida: registra la asistencia (presente o representado) sobre la lista fijada y observa el quórum en vivo.',
            'Anota el resultado de cada punto del orden del día y luego redacta el acta a partir de lo registrado — no se reescribe a mano. Firmarla la archiva de forma inmutable.',
        ]],
        AttendanceReportPage::class => ['permission' => 'reports.view', 'title' => 'Informe de asistencia', 'body' => [
            'Cuánta gente pasó por la sede y cuándo. Ayuda a dimensionar turnos y a respetar el aforo.',
        ]],
        BarSalesReportPage::class => ['permission' => 'reports.view', 'title' => 'Informe de barra y tienda', 'body' => [
            'El ingreso auxiliar no cannábico (Barra y tienda), en su libro aparte para no mezclarlo con la aportación de socios.',
        ]],
        ConsumptionReportPage::class => ['permission' => 'reports.view', 'title' => 'Informe de consumo', 'body' => [
            'Gramos dispensados por período, dentro de los límites configurados. Refleja lo dispensado, no lo autoriza.',
        ]],
        DebtorReportPage::class => ['permission' => 'reports.view', 'title' => 'Informe de deudores', 'body' => [
            'Los socios con deuda en el monedero o cuota pendiente. Sirve para gestionar cobros; no bloquea por sí solo.',
        ]],
        FinancialReportPage::class => ['permission' => 'reports.view', 'title' => 'Informe económico', 'body' => [
            'Aportaciones, gastos y superávit del período. Es superávit, nunca «beneficio»: la asociación es sin ánimo de lucro.',
        ]],
        MembersReportPage::class => ['permission' => 'reports.view', 'title' => 'Informe de socios', 'body' => [
            'Altas, bajas y composición del padrón de socios en el período.',
        ]],
        StockReportPage::class => ['permission' => 'reports.view', 'title' => 'Informe de inventario', 'body' => [
            'Existencias por lote y sede, mermas y caducidades. Sale del registro de movimientos, no de un recuento a mano.',
        ]],
        TillReportPage::class => ['permission' => 'reports.view', 'title' => 'Informe de caja', 'body' => [
            'Aperturas, cierres y descuadres por sesión. El efectivo esperado sale del registro, no se guarda.',
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

    /**
     * The five task GUIDES (prompt 99) — the multi-step jobs people actually do, each a walkthrough with a
     * consequence flagged at the step where it bites. `permission` is the FIRST step's permission: a guide is
     * hidden from anyone who could not begin it (`guidesVisibleTo`). Numbers are never restated as a rule —
     * name the Setting. The eighth-pricing guide carries `example` so the view renders live figures computed
     * through ResolvePrice (see `eighthExample`), never a hand-typed total that could drift from the till.
     *
     * @var array<string, array{title: string, permission: string|null, intro: string, example?: string, steps: list<array{title: string, body: list<string>}>}>
     */
    public const GUIDES = [
        'lockdown-runbook' => [
            'permission' => 'lockdown.manage',
            'title' => 'Bloqueo de seguridad (atraco): qué hacer',
            'intro' => 'El bloqueo de seguridad cierra el club entero —panel, mostrador y área de socios— y muestra una pantalla anodina de «no disponible», nunca un cartel de «bloqueado»: quien esté en la sala no debe saber que se ha activado. Lo importante no es el botón, es saber cómo se vuelve a entrar.',
            'steps' => [
                ['title' => 'Activarlo', 'body' => [
                    'Cualquier persona del mostrador puede activarlo desde su pantalla; también un responsable desde Seguridad en el panel. Se registra quién y cuándo ANTES de cerrar, y se avisa a los propietarios por correo.',
                    'Que se vea como una avería es deliberado: protege al personal que sigue en la sala.',
                ]],
                ['title' => 'Volver a entrar — hay tres caminos, a propósito', 'body' => [
                    'Enlace del propietario: cada propietario recibe un enlace de un solo uso en su correo. Se reactiva DESDE SU PROPIO MÓVIL, fuera del terminal, cuando sea seguro. No se puede reactivar desde el mostrador.',
                    'Plazo automático: si nadie reactiva, el sistema se reabre solo pasado el plazo configurado (por defecto 24 h), para que el club —que es el responsable de los datos— nunca quede fuera de su propio libro de socios.',
                    'Rotura de cristal: un operador de la plataforma puede reactivarlo por línea de comandos (php artisan lockdown:reactivate), que exige acceso al servidor.',
                ]],
                ['title' => 'Ensayarlo', 'body' => [
                    'Haz un simulacro desde Seguridad. El simulacro cierra las pantallas igual que el real para que el equipo lo viva, pero avisa de que es un simulacro y un propietario puede terminarlo desde el panel al momento.',
                ]],
                ['title' => 'Después', 'body' => [
                    'Durante el bloqueo no se puede acceder a ningún documento de identidad ni foto de socio: los enlaces firmados dejan de funcionar. Al reabrir, revisa el registro de auditoría (quién activó, cómo se reactivó) y valora denunciar según proceda.',
                ]],
            ],
        ],
        'new-location' => [
            'permission' => 'locations.manage',
            'title' => 'Abrir una sede nueva',
            'intro' => 'Dar de alta una sede y dejarla lista para dispensar. El paso que más se olvida es el precio: una sede sin precios es un mostrador que no puede entregar nada.',
            'steps' => [
                ['title' => 'Crea la sede', 'body' => [
                    'Da de alta el local en Sedes. A partir de ahí tiene su propio stock, su caja y su aforo, separados del resto de la organización.',
                ]],
                ['title' => 'Configura sus ajustes', 'body' => [
                    'En la ficha de la sede fijas su aforo, si exige check-in en la puerta y si pide firma en el mostrador. Son ajustes por sede.',
                ]],
                ['title' => 'Pon precio a CADA genética', 'body' => [
                    'Una genética sin precio en esta sede no aparece en su mostrador. Repasa que todas las variedades que vas a dispensar tengan precio por gramo (y de octavo, si lo usas) en la sede nueva.',
                ]],
                ['title' => 'Prepara al personal y los terminales', 'body' => [
                    'Asigna la sede a los usuarios que trabajarán allí y dales un PIN para identificarse en el mostrador. Sin sede asignada y PIN, no pueden abrir caja ni dispensar.',
                ]],
            ],
        ],
        'add-product' => [
            'permission' => 'genetics.manage',
            'title' => 'Poner una variedad a la venta',
            'intro' => 'Para que una variedad aparezca en el mostrador hacen falta tres cosas juntas —genética, precio y lote—; con una que falte, no aparece, y no da error.',
            'steps' => [
                ['title' => 'Crea la genética', 'body' => [
                    'Da de alta la variedad y su tipo: flor por peso, o unidades como prerolls o comestibles. El tipo decide cómo se dispensa.',
                ]],
                ['title' => 'Ponle precio en la sede', 'body' => [
                    'Añade el precio por gramo en la sede y, si usas descuento por cantidad, el precio del octavo. El precio es por sede: repítelo en cada sede donde se dispense.',
                    'Para las unidades (prerolls, comestibles) el precio es por unidad, no por gramo.',
                ]],
                ['title' => 'Registra un lote con stock', 'body' => [
                    'Crea un lote y da entrada al stock por el registro de movimientos. El mostrador elige siempre el lote apto más antiguo (no caducado).',
                ]],
                ['title' => 'Comprueba que aparece en el mostrador', 'body' => [
                    'Una genética Activa y Publicada pero SIN precio en la sede no aparece en el mostrador, y no avisa. El indicador de su ficha te dice qué falta: precio o stock.',
                ]],
            ],
        ],
        'eighth-pricing' => [
            'permission' => 'pos.use',
            'title' => 'Cómo se cobra un octavo',
            'intro' => 'Cómo se cobra un octavo (3,5 g) cuando el socio tiene descuento, y por qué el mostrador nunca cobra de más.',
            'example' => 'eighth',
            'steps' => [
                ['title' => 'El octavo es un descuento por cantidad', 'body' => [
                    'Además del precio por gramo, una genética puede tener un precio de octavo (3,5 g) más barato. Al reunir un octavo en la cesta, el mostrador cobra el octavo en lugar de la suma por gramo.',
                ]],
                ['title' => 'El descuento del socio se aplica encima', 'body' => [
                    'Si el socio tiene descuento, se aplica tanto al precio por gramo como al del octavo. La comparación es descuento contra descuento.',
                ]],
                ['title' => 'Nunca se cobra de más', 'body' => [
                    'El mostrador cobra siempre lo más barato de los dos. Si el precio del octavo quedara por encima de la suma por gramo (un error al teclear, o un precio por gramo rebajado después), se ignora: el socio nunca paga más porque exista un precio de octavo.',
                ]],
                ['title' => 'Un ejemplo, con las cifras del mostrador', 'body' => [
                    'Con estos precios y un descuento del socio, esto es exactamente lo que cobra el mostrador. Las cifras se calculan con la misma operación que la caja, no están escritas a mano.',
                ]],
            ],
        ],
        'taking-payment' => [
            'permission' => 'pos.use',
            'title' => 'Cobrar una aportación',
            'intro' => 'Cobrar una aportación de principio a fin en el mostrador, y qué hacer cuando el sistema bloquea.',
            'steps' => [
                ['title' => 'Identifica al socio', 'body' => [
                    'El mostrador empieza por el socio: sin socio identificado no hay dispensación. Comprueba que aparece al corriente; si no, el sistema te dice el motivo.',
                ]],
                ['title' => 'Añade el producto por peso', 'body' => [
                    'Selecciona la genética y pesa. El mostrador aplica el precio y el descuento del socio, y calcula el octavo por detrás cuando sale a cuenta.',
                ]],
                ['title' => 'Cobra', 'body' => [
                    'Elige efectivo o monedero del socio. Si paga en efectivo, el cambio se muestra para dárselo, pero no se guarda como dato: lo que se registra es la aportación y el efectivo que entra al cajón.',
                ]],
                ['title' => 'Firma, si la sede la exige', 'body' => [
                    'Algunas sedes piden la firma del socio para cerrar la dispensación. Es un ajuste por sede.',
                ]],
                ['title' => 'Si el mostrador bloquea', 'body' => [
                    'Puede bloquear porque el socio no está al corriente, está en carencia, alcanzaría el límite de gramos, no cumple la edad mínima o debe dinero por encima de lo permitido. El sistema dice el motivo exacto.',
                    'Un responsable puede autorizar algunas excepciones, con un motivo, y la autorización queda registrada.',
                ]],
            ],
        ],
        'till-day' => [
            'permission' => 'till.open',
            'title' => 'Abrir y cerrar la caja',
            'intro' => 'Abrir la caja, trabajar el turno y cerrarla a ciegas, que es lo que hace que el descuadre signifique algo.',
            'steps' => [
                ['title' => 'Abre la caja con el fondo', 'body' => [
                    'Al empezar el turno, abre la sesión del terminal declarando el fondo inicial de efectivo. Solo puede haber una sesión abierta por terminal.',
                ]],
                ['title' => 'Identifícate con tu PIN', 'body' => [
                    'Cada movimiento queda a tu nombre por el PIN. Registra las entradas y salidas de efectivo, los gastos de caja chica y los cobros de cuota según ocurren.',
                ]],
                ['title' => 'Cuenta a ciegas al cerrar', 'body' => [
                    'Al cerrar, cuenta el efectivo del cajón ANTES de ver lo que el sistema espera. El esperado se calcula del registro, no se guarda: contar a ciegas es lo que hace real el descuadre.',
                ]],
                ['title' => 'Repesa lo que se ha abierto', 'body' => [
                    'Repesa el producto que se ha tocado en el turno para cuadrar el stock; lo que no se ha contado queda como estaba.',
                ]],
                ['title' => 'Anota el descuadre', 'body' => [
                    'Se revela la diferencia entre lo contado y lo esperado. Si supera la tolerancia configurada, añade una nota explicando por qué. El cierre genera el informe de la sesión.',
                ]],
            ],
        ],
    ];

    /**
     * Raw inputs for the eighth-pricing worked example — the RESULT is never stored here, it is computed
     * live by `eighthExample()` through ResolvePrice so the guide can never drift from the till (prompt 99).
     *
     * @var array{base_per_gram_cents: int, base_eighth_cents: int, discount_bp: int, grams_cg: int}
     */
    public const EIGHTH_EXAMPLE = [
        'base_per_gram_cents' => 1000, // €10,00 / g
        'base_eighth_cents' => 2500,   // €25,00 / octavo
        'discount_bp' => 3000,         // 30 % de descuento del socio
        'grams_cg' => 350,             // 3,5 g = un octavo exacto
    ];

    /** @return array{heading: string, description: string}|null */
    public static function emptyStateFor(string $modelClass): ?array
    {
        return self::EMPTY_STATES[$modelClass] ?? null;
    }

    /**
     * Every resource + page topic, keyed by class.
     *
     * @return array<class-string, array{title: string, body: list<string>, permission: string|null}>
     */
    public static function allTopics(): array
    {
        return self::TOPICS + self::PAGE_TOPICS;
    }

    /** @return array{title: string, body: list<string>, permission: string|null}|null */
    public static function topicFor(string $key): ?array
    {
        return self::TOPICS[$key] ?? self::PAGE_TOPICS[$key] ?? null;
    }

    /**
     * The topics whose screen the reader can actually reach — permission `null` = everyone with panel
     * access. This is what the help index shows: a member of staff never sees the settings or RGPD topics.
     *
     * @return array<class-string, array{title: string, body: list<string>, permission: string|null}>
     */
    public static function topicsVisibleTo(?User $user): array
    {
        return array_filter(self::allTopics(), fn (array $t): bool => self::grants($user, $t['permission']));
    }

    /**
     * The guides the reader could actually START — gated on the FIRST step's permission, so no one is shown
     * a walkthrough whose opening move they cannot perform.
     *
     * @return array<string, array{title: string, permission: string|null, intro: string, example?: string, steps: list<array{title: string, body: list<string>}>}>
     */
    public static function guidesVisibleTo(?User $user): array
    {
        return array_filter(self::GUIDES, fn (array $g): bool => self::grants($user, $g['permission']));
    }

    /** @return array{title: string, permission: string|null, intro: string, example?: string, steps: list<array{title: string, body: list<string>}>}|null */
    public static function guideFor(string $key): ?array
    {
        return self::GUIDES[$key] ?? null;
    }

    /**
     * The eighth-pricing worked example, every figure computed through the SAME arithmetic the counter uses
     * (member discount → ResolvePrice::applyEighthBreaks). The guide shows what the till charges, by
     * construction — a test pins it to ResolvePrice so it can never silently drift. Integer cents throughout.
     *
     * @return array{base_per_gram_cents: int, base_eighth_cents: int, discount_bp: int, grams_cg: int, eff_per_gram_cents: int, eff_eighth_cents: int, per_gram_total_cents: int, charged_cents: int, saving_cents: int}
     */
    public static function eighthExample(): array
    {
        $e = self::EIGHTH_EXAMPLE;
        $effPerGram = $e['base_per_gram_cents'] - (int) round_half_up($e['base_per_gram_cents'] * $e['discount_bp'] / 10_000);
        $effEighth = $e['base_eighth_cents'] - (int) round_half_up($e['base_eighth_cents'] * $e['discount_bp'] / 10_000);
        $perGramTotal = (int) round_half_up($effPerGram * $e['grams_cg'] / 100);
        $charged = app(ResolvePrice::class)->applyEighthBreaks([[
            'grams_cg' => $e['grams_cg'],
            'rate_cents' => $effPerGram,
            'per_gram_total' => $perGramTotal,
            'eighth_price' => $effEighth,
        ]])[0]['total_cents'];

        return [
            'base_per_gram_cents' => $e['base_per_gram_cents'],
            'base_eighth_cents' => $e['base_eighth_cents'],
            'discount_bp' => $e['discount_bp'],
            'grams_cg' => $e['grams_cg'],
            'eff_per_gram_cents' => $effPerGram,
            'eff_eighth_cents' => $effEighth,
            'per_gram_total_cents' => $perGramTotal,
            'charged_cents' => $charged,
            'saving_cents' => $perGramTotal - $charged,
        ];
    }

    private static function grants(?User $user, ?string $permission): bool
    {
        return $permission === null || (bool) $user?->can($permission);
    }

    /** @return array<string, string> */
    public static function glossary(): array
    {
        return self::GLOSSARY;
    }
}
