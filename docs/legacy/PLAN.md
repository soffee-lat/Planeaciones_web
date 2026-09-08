# Plan del MVP: planeaciones didácticas

Estado: arquitectura propuesta; implementación pendiente. Fecha: 2026-09-07.

## Objetivo y alcance

Administrar 5–10 docentes con un solo usuario interno. La pantalla inicial debe responder «¿Qué tengo que hacer hoy?». La ficha permanente evita volver a capturar datos; cada periodo conserva su propia solicitud. Meta operativa: menos de 45 minutos humanos por docente y mes.

Incluye captación pública, perfil maestro, solicitudes, documentos privados, pagos manuales, paquete para IA, versiones del resultado, revisión obligatoria, entrega registrada, renovaciones, mensajes copiables, referidos y métricas. No incluye cuentas de docentes, SaaS multicliente, API pública, IA conectada, WhatsApp automático, pasarelas, facturación ni aplicación móvil.

## Arquitectura

- Monolito Laravel 13, PHP 8.4, Filament 5 para `/admin`, Blade para formularios públicos y MySQL 8.4 como objetivo de instalación. Composer resolverá y fijará versiones exactas en el lockfile durante la fase 1.
- Eloquent, Form Requests, enums de estados y policies que exigen usuario administrador. Sin repositorios genéricos, microservicios ni paquete de permisos.
- Acciones de aplicación pequeñas: registrar solicitud, confirmar pago, cambiar estado, generar paquete, aprobar versión y registrar entrega. Las reglas se aplican en servidor y se comparten entre panel y controladores.
- Disco local privado fuera de `public`; descarga autenticada mediante controlador autorizado. No usar enlaces públicos de storage.
- Procesamiento síncrono para este volumen. Renovaciones próximas calculadas al consultar el dashboard; no requieren colas ni tarea programada para aparecer.
- Zona operativa America/Mexico_City; instantes almacenados en UTC y periodos como fechas. Importes DECIMAL en MXN.
- Estructura convencional: `app/Models`, `app/Enums`, `app/Actions`, `app/Services`, `app/Policies`, `app/Http/Requests`, `app/Filament`, `resources/views`, `tests`.

Versiones contrastadas con [Laravel 13](https://laravel.com/docs/13.x/releases) y [Filament 5](https://filamentphp.com/docs/5.x/introduction/installation). Laravel 13 requiere PHP 8.3 o superior; Filament documenta PHP 8.2+, Laravel 11.28+ y Tailwind 4.1+. La resolución real de dependencias será una comprobación de fase 1. Se detectaron PHP, Composer y Node en PATH; no se detectó el cliente MySQL, lo cual no confirma ausencia del servidor. Aún no se probaron conexión, extensiones PHP ni instalación de paquetes.

## Operación y pantallas

Navegación: Hoy, Docentes, Solicitudes, Mensajes, Referidos y Métricas. Pagos, archivos, versiones, revisión, entregas y tiempos se administran dentro de la solicitud, evitando navegar entre módulos.

«Lo que necesita mi atención» será el primer bloque: vencidas, entregas de hoy, correcciones/revisión, información o pago pendiente, solicitudes listas para generar y renovaciones. Cada fila muestra docente, periodo, vencimiento, estado y acción siguiente. Debajo: clientes activos, ingresos cobrados del mes, solicitudes pendientes, conteos por estado y renovaciones próximas. Los estados tendrán texto y color, sin depender solo del color.

La ficha de docente reúne datos generales, grupo, perfil maestro y su historial. La solicitud muestra resumen del periodo, pago, paquete, versiones, checklist, entrega y minutos. Formularios en secciones cortas, validación junto al campo y confirmación de guardado; revisión de escritorio y móvil en cada fase con interfaz.

## Reglas de producción

Flujo habitual:

`NUEVA → ESPERANDO_INFORMACION / ESPERANDO_PAGO → LISTA_PARA_GENERAR → GENERACION_IA → REVISION → LISTA_PARA_ENTREGAR → ENTREGADA`

- NUEVA se evalúa por completitud. Se prioriza ESPERANDO_INFORMACION si faltan datos, aunque también falte pago; ambos indicadores siguen visibles.
- LISTA_PARA_GENERAR exige información mínima completa y pago confirmado por el administrador. Datos mínimos: docente, grado 1–6, periodo válido, tema, contenidos, PDA, campos formativos, ejes, fecha límite y precio. Campos no aplicables admiten declaración explícita; no inventar PDA ni información faltante.
- Generar un paquete guarda una instantánea; pasar a GENERACION_IA es una acción explícita, pues copiar no prueba que se haya generado en la IA externa.
- Subir una versión válida permite REVISION. Rechazar revisión lleva a CORRECCIONES; una nueva versión vuelve a REVISION con checklist vacío. Contabilizar cada devolución a CORRECCIONES.
- LISTA_PARA_ENTREGAR requiere versión con archivos, los 13 controles aprobados y pago confirmado. Se verifica también al entregar, dentro de una transacción, aunque se intente saltar la interfaz.
- ENTREGADA exige fecha, medio y archivos de la versión aprobada. Registrar entrega no envía archivos automáticamente.
- RENOVACION_PENDIENTE se permite solo después de entrega y conserva su registro histórico. Las alertas se calculan a partir de la renovación, sin depender de que alguien cambie este estado.
- Una renovación crea una nueva solicitud relacionada con la anterior; no recicla la solicitud entregada. ARCHIVADA termina la operación conservando documentos e historial.
- Cambios de datos pedagógicos o archivos de una versión invalidan su aprobación antes de entrega; después de entrega se conserva la versión y se crea otra para correcciones. No reemplazar archivos históricos.
- Toda transición se valida contra una matriz permitida y registra estado anterior, nuevo, fecha y responsable. No ofrecer edición libre del enum.

## Paquete para IA

Servicio determinista, sin API: plantilla maestra editable + instantánea del perfil/grupo + datos del periodo + preferencias + instrucciones pedagógicas + manifiesto de archivos. Guardar texto, versión de plantilla y datos usados para reproducibilidad. Un cambio posterior del perfil no modifica paquetes anteriores; se indica si conviene regenerar.

El paquete incluirá contenidos y PDA tal como se recibieron, campos y ejes, evaluación, inicio/desarrollo/cierre, adecuaciones, materiales realistas, formato institucional y verificación de datos faltantes. Los documentos son referencias con nombre, categoría e identificador; no se extraerá texto, OCR ni se enviarán archivos. Una indicación visible recuerda adjuntar manualmente los documentos a la IA externa.

Botones: Generar paquete para IA, COPIAR PROMPT COMPLETO, descargar .txt y .md. Vista de texto seguro, sin ejecutar HTML. Excluir teléfono/correo del prompt por defecto por no aportar a la planeación; el perfil pedagógico sí se incluye. Indicar que no se capturen nombres ni datos identificables de menores.

## Captura pública y archivos

- Alta pública: datos de docente, perfil, periodo y adjuntos; crear docente + perfil + solicitud en transacción. La docente no decide precio, pago, estado interno ni aprobación.
- Repetición del mismo envío: clave de idempotencia para impedir duplicados. Coincidencia de teléfono/correo existente no sobrescribe perfiles ni revela su existencia: respuesta genérica que solicita usar el enlace de seguimiento.
- Cliente existente: token aleatorio de 256 bits, almacenado como hash, con caducidad propuesta de 30 días, revocable y de un solo uso exitoso. Vinculado a una docente y opcionalmente a renovación. Consumo atómico al crear la solicitud; un error permite corregir y reenviar. No da acceso a historial ni descargas. Enlaces generados y copiados por el administrador.
- CSRF, límites de frecuencia por IP/token, honeypot y tiempo mínimo razonable, límites de longitud y cantidad de archivos; mensajes accesibles para recuperar errores. No incorporar captcha externo inicialmente.
- Permitir DOC/DOCX, PDF, JPG, PNG y WEBP; máximo propuesto 10 archivos de 15 MB por envío. Validar extensión, MIME y tamaño del lado servidor, incluidos temporales de Livewire. Rechazar ejecutables, SVG y documentos con macros; DOC legado requiere validación específica, nunca ejecución ni previsualización activa.
- Nombres aleatorios y nombre original solo como metadato escapado. Descargar como attachment con nosniff, sin rutas aportadas por el cliente. Si falla la transacción, limpiar los archivos nuevos; limpiar temporales abandonados mediante comando de mantenimiento documentado.
- Autenticación interna sin registro abierto, HTTPS en despliegue, cookies seguras, APP_DEBUG=false, secretos fuera de Git y tokens fuera de logs; Referrer-Policy no-referrer en formularios con token.

## Renovaciones, mensajes y métricas

Propuesta inicial: siguiente periodo empieza al día siguiente del fin del actual; solicitar renovación 7 días antes. Fechas editables por vacaciones o periodos no mensuales. Una sola renovación por solicitud entregada, creada al registrar entrega, incluso si esta fue tardía. Ventana «próximas»: vencidas y las de los siguientes 7 días; solo docentes activas y renovaciones pendientes. Crear la siguiente solicitud cierra la renovación en la misma transacción; cancelar registra fecha y motivo.

Plantillas editables: información, pago pendiente, lista, entrega, renovación y referido. Sustitución por lista permitida de variables `{{nombre}}`, `{{grado}}`, `{{periodo}}`, `{{precio}}` y `{{enlace}}`. Sin evaluar código; vista previa y copiar, mostrando variables desconocidas. Descuento de referido se registra y se aplica manualmente al precio, sin automatismo.

Métricas sin tablas agregadas: ingresos = pagos confirmados por fecha de pago; clientes activos = estado actual; nuevos = altas del mes; renovaciones = renovaciones convertidas en el mes; cancelaciones = eventos de desactivación del mes; ingreso promedio = ingresos del mes / docentes distintas con pago confirmado ese mes (0 cuando no hay). Entregadas se cuentan por registros de entrega, aunque cambie después el estado.

Tiempo humano: entradas de minutos por fecha, solicitud y actividad (información, generación, revisión, corrección, entrega, administración), captura rápida desde la solicitud. Suma mensual por docente de todas sus solicitudes; alerta desde 45 minutos. Correcciones = transiciones a CORRECCIONES. Métricas son aproximadas y dependen de registrar tiempo; no incluir espera de IA como trabajo humano. Mantener vistas de tiempo por solicitud y por docente/mes.

## Fases y criterios de salida

1. Base técnica: instalar y fijar dependencias, MySQL de desarrollo/pruebas, login Filament, esquema, factories/seeders ficticios y autorización. Salida: migraciones, autenticación y bloqueo de accesos probados.
2. Captura: docentes, perfil, solicitudes, adjuntos privados y los dos formularios públicos. Salida: alta completa y renovación por token sin duplicar ni filtrar documentos.
3. Operación: pagos, transiciones, dashboard de atención y tiempos. Salida: información y pago bloquean generación; prioridades y cobros correctos.
4. Paquete para IA: plantilla, instantáneas, copiar y descargas. Salida: paquete reproducible con todos los campos y manifiesto privado.
5. Producción y entrega: versiones, checklist y entrega. Salida: imposible entregar una versión sin controles completos; nueva versión no hereda aprobación.
6. Seguimiento: renovaciones, seis mensajes, referidos y métricas. Salida: renovación sin duplicados, copia correcta y KPI mensual verificable.
7. Piloto: recorrido completo con datos ficticios de 5–10 docentes, revisión responsive, pruebas de seguridad y documentación de puesta en marcha/backup/restauración. Salida: flujo de alta a renovación verificado y riesgos reales registrados.

Cada fase implementa, prueba reglas importantes, corrige, revisa interfaz y actualiza TASKS.md con evidencia. Usar PHPUnit/Laravel y pruebas Livewire/Filament; pruebas de integración sobre MySQL para restricciones y concurrencia. No marcar una fase completa si falta su verificación visual. Documentar bloqueos sin presentar comprobaciones no ejecutadas como aprobadas.

## Supuestos a validar durante el piloto

Precio se establece internamente; un pago completo por solicitud inicialmente, sin contabilidad de abonos. Renovación a 7 días y vigencia de token de 30 días son valores configurables. No se publica ni se contacta a docentes al preparar el MVP. Despliegue requiere definir hosting PHP/MySQL, dominio, HTTPS y ubicación de copias; no bloquea construir en local.
