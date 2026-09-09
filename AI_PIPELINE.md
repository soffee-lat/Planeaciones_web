# Pipeline IA y procesamiento asíncrono

## Contratos

### Contrato canónico v1

Fase 4A separa deliberadamente la salida generable del proveedor de la autoridad curricular. `GeneratedPlanDraftV1` contiene únicamente diseño pedagógico y referencias por código; no puede aportar textos curriculares oficiales. `CanonicalPlanAssembler` combina ese draft validado con el `RequestInputVersion` congelado y construye `CanonicalPlanV1`. Los textos, códigos, fase, grado, contenidos, PDA y ejes del bloque `curricular_alignment` proceden exclusivamente del snapshot confirmado.

Contratos versionados en `resources/schemas/ai/`; decisión y reglas completas en `docs/ai/CANONICAL_PLAN_CONTRACT_V1.md`. Cambiar la forma del JSON requiere una nueva versión de contrato. Auditoría, corrección, revisión y renderer futuros reciben el canonical, no la respuesta cruda del proveedor. `curricular_alignment.coverage` se calcula server-side desde las referencias de las sesiones y todo PDA confirmado debe quedar cubierto.

La validación v1 combina JSON Schema con invariantes PHP: IDs/secuencias de sesión, inicio-desarrollo-cierre, suma de minutos, instrumentos y referencias curriculares contra el snapshot. `date` de sesión permanece nullable y las unidades comerciales no equivalen al número de sesiones.

### Materialización Fase 4B — arranque manual

`DispatchPlanningGeneration` materializa únicamente `LISTA_PARA_PROCESAR → GENERACION_IA`. Bajo lock de `PlanningRequest` valida autorización comercial, PromptVersion generation activa/publicada y snapshot vigente; consume una sola vez la reserva `planning`, crea `AiExecution` pending/manual, `RequestStateEvent` de sistema y `outbox_events` con `event_key` único dentro de la misma transacción. La reserva `human_review` no se consume al generar: sigue reservada hasta la etapa de revisión.

El outbox no llama una API. `ai:process-outbox` reclama eventos mediante lease, reconstruye `GenerationInput` desde snapshots inmutables y comprueba hashes del manifest. En modo manual renderiza el prompt exacto y crea un paquete JSON privado versionado por `AiExecution`; solo entonces la ejecución queda `waiting_manual`. La escritura de bytes se hace fuera de la transacción que persiste el paquete para no mantener locks durante I/O. Repetir dispatch, claim o package build es idempotente.

Fallo técnico no cambia la solicitud a un estado ficticio de error ni libera unidades ya consumidas: el evento queda reintentable, se abre `request_blocks(code=ai_failed, stage=generation)` con detalle sanitizado y el cliente continúa viendo un estado público de preparación. Al procesarse correctamente el evento se resuelve ese bloqueo. `AI_MODE=api` falla antes de consumir derechos mientras no exista adapter real; integración HTTP permanece en Fase 7.

### Materialización Fase 4C — resultado manual y versión canónica

`ai:import-generation-result` recibe el JSON `GeneratedPlanDraftV1` de una `AiExecution` generation/manual que ya está `waiting_manual`. El payload se valida primero; luego `CanonicalPlanAssembler` lo combina con `RequestInputVersion` vigente y vuelve a imponer referencias curriculares, cobertura PDA, fechas y demás invariantes. El resultado validado se persiste como `DocumentVersion(status=validated)` inmutable dentro de un `Document` lógico único por solicitud. `content_hash` cubre el canonical y `source_payload_hash` conserva la identidad del draft sin duplicar el payload crudo.

La importación es transaccional e idempotente. La ejecución generation pasa a `succeeded` solo después de crear la versión y enlazar `resulting_version_id`; proveedor/modelo/costo real se registran únicamente si el operador los conoce, de lo contrario quedan `null`. Repetir el mismo draft devuelve la misma versión; un draft diferente para una ejecución ya cerrada produce conflicto y no reescribe historial. `human_review` continúa reservado.

Antes de cambiar `GENERACION_IA → AUDITORIA_IA`, 4C exige una `PromptVersion` audit activa/publicada y crea una `AiExecution(stage=audit,status=pending)` congelando `source_version_id`, hash, revisión y correlación. **No** crea paquete de auditoría, no ejecuta `AuditService` y no llama API: esa materialización pertenece a 4D. PostgreSQL protege `DocumentVersion`, resultado succeeded y la transición posterior mediante triggers/FK diferidos para impedir un estado `AUDITORIA_IA` sin resultado documental coherente.

GenerationService.generate(GenerationInput): GenerationResult; AuditService.audit(AuditInput): AuditResult; CorrectionService.correct(CorrectionInput): CorrectionResult; DocumentAnalysisService.analyze(DocumentInput): AnalysisResult. Servicios orquestan validación, prompts y persistencia mediante AiProvider adapter; nunca llamar proveedor desde controlador o componente Filament. DocumentRenderer es contrato separado: generación de contenido no es renderizado de DOCX.

DTO de entrada: request_id, input_revision, perfil pedagógico minimizado, datos variables, snapshot curricular textual confirmado, planning_units y segmentos, manifest de archivos limpios, prompt_version_id, output_schema_version, correlation_id y operation_key. Corrección añade source_version_id, section_keys y observaciones. Salida: contenido estructurado/patch validado, referencias, alertas y metadatos de uso; no HTML ejecutable ni llamadas a herramientas arbitrarias.

AI_MODE=manual resuelve adapter que crea ejecución waiting_manual y paquete privado con texto exacto y manifest. Operador autorizado exporta paquete, procesa fuera y carga resultado identificado con proveedor/modelo real, fecha y costo conocido; si desconocido, null, nunca cero inventado. No enviar datos automáticamente. La carga pasa las mismas validaciones/auditoría/versionado que API; nunca marcar éxito solo por copiar prompt. Espera manual no ocupa worker.

AI_MODE=api requiere adapter configurado y credenciales. Si no existe, error de configuración visible; no hacer fallback silencioso a datos ficticios. Fake determinista solo en tests. Un adapter real se elegirá y probará antes del lanzamiento automático.

## Prompts y trazabilidad

PromptTemplate categorías generation, audit, correction, document_analysis y format_adaptation. PromptVersion publicada inmutable: cuerpo, variables permitidas, schema, número y autor; activar nueva versión no altera ejecuciones existentes. Administrable en /admin, vista previa y validación antes de publicar. Sustitución por lista permitida, sin eval ni Blade arbitrario. Guardar versión exacta, hash y payload renderizado privado con retención y acceso limitado. No hardcodear prompts en UI/jobs.

Cada ejecución conserva modo, proveedor, modelo, prompt/version, fecha, duración, estado, errores sanitizados, costo estimado/real si existe, relación solicitud o formato, revisión de entrada y versión resultante. ai_attempts conserva reintentos, IDs de proveedor y consumo; costo incluye intentos fallidos cobrados. Secretos y payload sensible no van al log general.

## Etapas

1. Validar derechos/reservas, información y archivos; congelar input_revision y formato publicado.
2. DispatchGeneration crea/reutiliza execution por operation_key, consume planning_units una sola vez y pide estructura canónica: objetivos, sesiones con inicio/desarrollo/cierre, materiales, evaluación, adecuaciones y referencias a contenidos/PDA.
3. Validar schema, fechas, campos requeridos, secciones y límites. No promover respuesta truncada o inválida. Crear DocumentVersion inmutable bajo bloqueo; enlazar ejecución y padre.
4. DispatchAudit evalúa esa versión contra entrada y rúbrica; devuelve hallazgos por sección, gravedad y pass/fail. Campos curriculares ausentes → falta información, no invención. Auditoría independiente no garantiza verdad: registrar incertidumbres.
5. Hallazgos corregibles → corrección acotada; hallazgo no corregible → bloqueo y atención. Ciclos máximos y presupuesto configurables. Resultado satisfactorio crea aprobación AI; ruta según derecho human_review_required.
6. Revisión humana cuando corresponda; nuevas observaciones generan patch sobre source_version_id. Rechazar patch fuera de section_keys; si exige modificar otra sección, expandir alcance explícitamente. Nueva hija invalida aprobación para ese resultado y se audita nuevamente.
7. Aprobación requerida completa → DocumentRenderer con formato versionado → validación DOCX/PDF → manifest → entrega y notificación. Render no modifica contenido aprobado silenciosamente.

## Formatos

Estándar administrado por plataforma: estructura conocida, placeholders/tablas controladas, versión de renderer y muestra validada. Institucional: archivo privado → análisis → propuesta de mapping de secciones/campos → configuración humana → render de prueba → validación visual → publicación ready. No utilizar pending/unsupported en producción; ofrecer estándar o bloquear con motivo. Una nueva versión no cambia solicitudes anteriores. OCR universal y fidelidad de cualquier DOCX no forman parte del MVP.

## Queues, transacciones y fallos

Colas ai, documents y notifications con workers separados; database queue suficiente inicialmente. Jobs pequeños por etapa. Transacción guarda estado, ejecución prevista y outbox; dispatcher publica pendientes después de commit. Evento y job llevan clave única por solicitud+input_revision+etapa+versión+corrección. Repetición es no-op si ya completado. Recuperador revisa outbox no publicado; no confiar solo en afterCommit ante caída entre commit y enqueue.

No mantener transacción DB durante llamada externa. Antes de llamar, reclamar ejecución con bloqueo y lease; después, bloquear y verificar estado, revisión de entrada y versión base. Respuesta antigua queda registrada sin promoverse. No existe exactly-once universal: clave del proveedor cuando soporte idempotencia; ante timeout ambiguo estado uncertain y consulta de estado/reconciliación antes de repetir un cobro.

Timeout HTTP < timeout del job < retry_after de cola, todos configurables y validados en arranque. Reintentos acotados con backoff/jitter para red, 429 y errores temporales; fallos de credencial, schema reiterado o presupuesto agotado bloquean sin bucle. Job único/WithoutOverlapping ayuda, pero bloqueo DB y claves únicas son autoridad. Scheduler detecta leases vencidas, ejecuciones estancadas y failed_jobs; reanuda la etapa válida sin reiniciar toda la solicitud.

Workers supervisados, reiniciados en despliegue y monitoreados. Scheduler reconcilia pagos ambiguos, solicitudes sin revisor, vencimientos, renovaciones y outbox. No emitir aviso cada minuto: deduplicar por evento/ventana. Presupuestos de tokens/costo/ciclos por solicitud y periodo; alertar al llegar al límite. Circuit breaker por proveedor evita tormenta de reintentos; contingencia manual requiere acción explícita y trazabilidad.

## Seguridad y pruebas

Documentos e instrucciones de usuario son datos no confiables: separar del prompt de sistema, sin dar acceso a secretos/red/herramientas al contenido. Extraer solo lo necesario tras escaneo; no incluir identificadores personales por defecto. Texto libre puede contener datos personales: advertencia, detección y revisión de payload según política antes de salir. Sanitizar visualización y bloquear recursos remotos al renderizar.

Tests con fake: flujo IA y revisado; modo manual; prompt exacto; schema inválido; auditoría fallida; corrección fuera de sección; límite de ciclos/costo; timeout ambiguo; job duplicado; worker caído; entrada editada durante ejecución; versión antigua no promovida; render fallido; email fallido sin revertir entrega. Tests reales de adapter acotados en entorno sandbox al integrar proveedor, nunca con datos de alumnos.


## Propuesta curricular previa al pipeline

MVP: CurriculumSuggestionService descrito en CURRICULUM.md, adapter de búsqueda/reglas sobre catálogo del grado y versión publicados. UI → acción autorizada → servicio; no llamar IA directamente. No es GenerationService ni cambia BORRADOR a GENERACION_IA. Propuesta no reserva cupos, no publica texto curricular y requiere confirmación docente. Se guarda curriculum_suggestions con fingerprint completo; timeout no borra borrador y respuesta obsoleta no pisa selección nueva. Búsqueda determinista puede ser síncrona con límite de tiempo; si se añade adapter externo, ejecutarlo mediante queue y mantener exactamente el mismo contrato.

POST-MVP para sugerencias: adapter IA/semántico. De añadirse, nueva categoría de prompt curriculum_suggestion, ejecución enlazada a solicitud borrador y sugerencia, límites de costo/frecuencia, IDs candidatos permitidos y validación posterior. No implementar ahora embeddings, índices vectoriales ni integración adicional. AI_MODE gobierna generación/auditoría/corrección existentes; no impide sugerencias deterministas en modo manual.

GenerationService y AuditService siempre usan snapshot de currículo confirmado al envío, no consultan versión activa para reescribir la solicitud. Validar textos y referencias contra ese snapshot; no inventar PDA ni aceptar IDs nuevos del proveedor. Selección modificada exige nueva confirmación/revisión de entrada. Versiones de currículo retiradas de selección futura siguen siendo válidas para solicitudes históricas.

Render y generación conocen segmentos comerciales: cubrir explícitamente cada tramo de hasta max_planning_days; no truncar un periodo largo a la primera unidad. Una ejecución lógica por etapa puede incluir varios intentos acotados, sin más consumo comercial. El límite de payload/costo se valida antes de reservar; si U no cabe en capacidad configurada, pedir reducir periodo con explicación, no cobrar y después truncar. No introducir subpipelines por unidad en MVP.

La propuesta inicial de evaluación es texto pedagógico sugerido y editable, no parte de un catálogo oficial. Solo la versión confirmada alimenta generación. Materiales sin texto disponible no se presentan como leídos. Frontend recibe datos pedagógicos y estados comprensibles, jamás proveedor/prompt/tokens/execution_id. Información técnica queda en operación autorizada.
