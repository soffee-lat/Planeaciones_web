# Arquitectura comercial

Fecha: 2026-09-07. Diseño previo al código. La especificación comercial actual sustituye el alcance anterior de administrador único; originales preservados en docs/legacy.

## Decisiones y discrepancias

Monolito modular Laravel 13, PHP 8.4, Filament 5, PostgreSQL y queues. Tres paneles independientes: /app, /review y /admin. Eloquent, acciones transaccionales, Policies y contratos de integración; sin microservicios, repositorios genéricos ni bases por docente. El propietario es el usuario cliente; organizaciones escolares compartidas quedan fuera.

Laravel 13 exige PHP >=8.3: https://laravel.com/docs/13.x/releases . Filament 5 documenta PHP >=8.2, Laravel >=11.28 y Tailwind >=4.1: https://filamentphp.com/docs/5.x/introduction/installation . Consultado el 2026-09-07. Composer y pruebas deben confirmar compatibilidad efectiva y fijar lockfiles en fase 1; no se ha instalado software en esta etapa.

| Inconsistencia o ambigüedad | Decisión propuesta |
|---|---|
| Documentos anteriores excluyen cuentas, planes y colas | Sustituir alcance conservando antecedentes |
| 90% automático frente a modo manual | Manual es contingencia/piloto; medir automatización API real |
| Rama IA omite APROBADA | Auditoría satisfactoria registra aprobación automática antes de renderizar |
| No hay estado de error/rechazo/escalamiento | Bloqueos separados del estado; rechazo y escalamiento mantienen revisión bloqueada |
| Revisiones y correcciones sin regla de consumo | Derechos congelados por periodo; reservar al enviar; fallos internos no gastan correcciones del cliente |
| Corrección después de expirar suscripción | Respetar ventana y derechos congelados de la solicitud |
| DOCX institucional arbitrario | Analizar, mapear, probar y publicar versión compatible antes de generar |
| Pago único por solicitud del diseño anterior | Pedido por compra/renovación, varios intentos y devoluciones |
| Proveedores aún no definidos | Contratos manual/fake primero; adapters reales son requisito de autoservicio automático |

Supuestos: una suscripción activa por cliente, sin acumulación ni prorrateos; cancelación al fin de periodo; cuotas, precios, ventana de corrección, SLA y límites configurables antes de vender. Ningún valor conceptual se convierte en precio real por defecto.

## Contextos y estructura

| Módulo | Responsabilidad |
|---|---|
| Identidad | Registro, correo verificado, roles, acceso y perfil |
| Perfil pedagógico | Escuelas, grupos, onboarding y datos reutilizables |
| Curricular | Catálogo versionado, publicación inmutable y CurriculumSuggestionService; detalle en [CURRICULUM.md](CURRICULUM.md) |
| Comercial | Planes versionados, suscripciones, derechos, consumo, PaymentGateway |
| Planeaciones | Solicitudes, snapshot, estados, bloqueos, correcciones |
| IA | Contratos, prompts versionados, ejecuciones y recuperación |
| Calidad | Asignación, checklist, decisiones, honorarios y métricas |
| Documentos | Archivos privados, formatos, versiones, render y entregas |
| Operación | Auditoría, notificaciones, atención y rentabilidad |

app/Models, Enums, Policies, Actions/{contexto}, Services/{contexto}, Contracts, Jobs, Notifications y Filament/{App,Review,Admin}. UI llama acciones; acciones autorizan, validan y transaccionan. Jobs usan las mismas invariantes con actor sistema explícito. Compartir componentes simples, no interfaces completas entre roles.

## Paneles

/app: acción principal NUEVA PLANEACIÓN, seguida de planeaciones recientes y uso del plan. Asistente breve: grupo y fechas → tema/material/observaciones → propuesta curricular para confirmar o modificar → resumen de unidades y envío. Modo RÁPIDO por defecto; AVANZADO permite búsqueda y selección de catálogo. Precargar grado, grupo, duración de sesión, características, dificultades, necesidades, preferencias, materiales, restricciones y formato institucional desde perfil; no volver a pedirlos. Mostrar solo excepciones que requieren atención. Guardar borrador, permitir volver sin perder información. Historial, plan/pagos, renovación, grupos y perfil como navegación secundaria. No exponer proveedores IA, prompts, tokens ni ejecuciones. Traducir estados según WORKFLOWS.md: Preparando, En revisión, Necesitamos información y Lista para descargar; bloqueos de pago con acción comercial comprensible.

/review: cola propia por vencimiento y contadores pendientes/urgentes/en corrección/aprobadas hoy/tiempo medio. Pantalla única: información original minimizada, resultado, auditoría, checklist, observaciones por sección y aprobar/corregir/rechazar/escalar. Guardar revisión parcial. Historial propio y pagos por trabajo; sin ficha comercial del cliente.

/admin: atención primero: pagos fallidos, generaciones fallidas, solicitudes bloqueadas, revisiones vencidas, correcciones pendientes y formatos sin configurar. Cada alerta enlaza acción resolutiva. Después ventas, operación, IA, revisores y margen. Administrar catálogo, versiones de prompts/checklist/formatos, asignación, pagos y auditoría. No permitir editar estados o saldos desde CRUD genérico.

Responsive, teclado, errores próximos al campo y estados con texto además de color. Validar solicitud <3 minutos con perfil completo y catálogo disponible, incluyendo confirmación de propuesta; medir revisión sin cambiar de pantalla.

## Infraestructura, archivos y privacidad

PostgreSQL fuente de verdad; queue database inicialmente, Redis solo por necesidad medida. Workers ai/documents/notifications y scheduler; recuperación en AI_PIPELINE.md. Producción requiere supervisión de workers y backups.

Disco privado fuera de public en desarrollo; almacenamiento privado compatible con S3 en producción si conviene. No storage:link para entregables. Cuarentena → validación y escaneo → disponible. Inicialmente DOCX, PDF, JPEG, PNG y WEBP; otros formatos solo con validador explícito. Rechazar macros, SVG y ejecutables; DOCX se inspecciona con límites de expansión. Límites configurables de tamaño, páginas, cantidad y resolución coherentes en proxy/PHP/Livewire.

Rutas aleatorias, SHA256, MIME real y nombre escapado. Descarga por controlador autenticado y Policy; attachment y nosniff. URL temporal solo tras autorización y TTL corto configurable: puede compartirse hasta expirar. Revisores usan proxy autorizado en cada petición para revocación inmediata al reasignar. No registrar enlaces firmados. Render aislado sin red/macros, con límites de tiempo/memoria; sanitizar HTML generado.

Bytes por versión inmutables. Escritura temporal, promoción y reconciliación de huérfanos; no dar por entregado un archivo incompleto. Backup cifrado de DB y objetos, restauración probada. Definir retención por categoría antes de producción, preservar referencias financieras y anonimizar cuando corresponda. No requerir nombres de alumnos; advertir sobre datos sensibles en textos libres y minimizar payloads enviados a IA.

## Riesgos

Alucinación curricular: conservar contenidos/PDA recibidos, bloquear faltantes y exigir auditoría; no prometer certificación. Formatos complejos: catálogo limitado y muestras verificadas. Duplicados por timeout: idempotencia y reconciliación. Saturación de revisores: asignación atómica y alerta sin capacidad. Costos: presupuestos y circuit breaker. Aislamiento: pruebas adversarias de IDs, archivos y relaciones. Proveedores: adapters y contingencia manual auditada. El 90% es objetivo medido, no garantía.

Revisión documental: alcance, entidades, permisos, estados, pipeline, almacenamiento y fases contrastados entre los siete documentos. Sin código ni pruebas de aplicación en esta etapa.


## Iteración funcional antes de Fase 1

PostgreSQL sustituye MySQL por decisión del usuario; desarrollo, CI y producción usarán PostgreSQL, sin mantener doble compatibilidad. Driver Laravel pgsql y extensión pdo_pgsql; JSONB para estructuras, timestamptz para instantes, DATE para periodos y CHECK para cantidades no negativas. Versión mayor soportada se fija con hosting en Fase 1; no se instala nada ahora. FK compuestas e índices únicos parciales refuerzan pertenencia y unicidad operativa; ver [restricciones PostgreSQL](https://www.postgresql.org/docs/current/ddl-constraints.html) y [JSONB](https://www.postgresql.org/docs/current/datatype-json.html), consultados el 2026-09-07.

Catálogo curricular es módulo del mismo monolito, no servicio separado. CurriculumVersion publicada y todo su árbol son inmutables; solicitudes usan referencias y snapshot textual completo. Catálogo no se limita a un plan curricular codificado. Diseño, cardinalidades, propuestas y seeders ficticios en CURRICULUM.md; no se carga currículo real.

Unidad comercial: hasta max_planning_days días naturales inclusivos por unidad; solicitud consume ceil(días/max_planning_days) unidades. Semántica detallada de planning_limit, human_review_limit y correction_limit en WORKFLOWS.md. El máximo se aplica a cada unidad, no a toda la solicitud; una solicitud extensa consume varias unidades y se divide en segmentos de producción. El docente ve cantidad antes de confirmar. Precios y duración de unidad siguen configurables.

MVP: catálogo/publicación, búsqueda, propuestas deterministas desacopladas, modos rápido/avanzado, perfil reutilizado y consumo proporcional. POST-MVP: sugerencias curriculares IA/semánticas, calendario escolar automático, importación/sincronización y multigrado. Generación IA existente sigue en MVP. Se conserva soporte estándar y análisis/mapping/prueba/publicación institucional; sin DOCX universal.
