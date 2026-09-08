# Flujos y máquina de estados

Estados persistidos con enum PHP en español. Solo TransitionRequest y acciones específicas pueden cambiarlos; no edición libre. Cada transición bloquea solicitud, verifica estado esperado, permisos, guardas, versión de entrada, registra evento y outbox dentro de transacción. Doble clic o job repetido devuelve el resultado previo por operation_key.

## Matriz de transiciones

| Origen | Destino | Guarda y responsable |
|---|---|---|
| BORRADOR | ESPERANDO_INFORMACION / ESPERANDO_PAGO / LISTA_PARA_PROCESAR | Cliente envía; validación de completitud y derechos |
| ESPERANDO_INFORMACION | ESPERANDO_PAGO / LISTA_PARA_PROCESAR | Cliente completa; reevaluar ambos bloqueos |
| ESPERANDO_PAGO | ESPERANDO_INFORMACION / LISTA_PARA_PROCESAR | Pago confirmado y reevaluación, sistema |
| LISTA_PARA_PROCESAR | GENERACION_IA | Snapshot, formato listo y reserva de cupos válidos, job |
| GENERACION_IA | AUDITORIA_IA | Resultado estructurado válido de entrada vigente |
| AUDITORIA_IA | APROBADA | Auditoría pasa, plan sin revisión humana; aprobación AI exacta |
| AUDITORIA_IA | REVISION_HUMANA | Auditoría pasa, plan exige humano; asignar o bloquear sin capacidad |
| AUDITORIA_IA | CORRECCION_IA | Hallazgos corregibles, presupuesto/ciclos disponibles |
| REVISION_HUMANA | APROBADA | Revisor asignado aprueba versión y checklist obligatorio completo |
| REVISION_HUMANA | CORRECCION_IA | Observaciones por sección y solicitud interna creada |
| CORRECCION_IA | AUDITORIA_IA | Nueva versión hija; siempre auditar de nuevo antes de revisión |
| APROBADA | GENERANDO_DOCUMENTO | Aprobaciones vigentes para esa versión y formato publicado |
| GENERANDO_DOCUMENTO | LISTA_PARA_ENTREGAR | DOCX/PDF validados, bytes privados y manifest completo |
| LISTA_PARA_ENTREGAR | ENTREGADA | Publicar entrega única, versión aprobada y archivos disponibles |
| ENTREGADA | CORRECCION_SOLICITADA | Cliente propietario, motivo, versión entregada, ventana y cuota válidas |
| COMPLETADA | CORRECCION_SOLICITADA | Mismas guardas; completar manualmente no recorta ventana contractual |
| CORRECCION_SOLICITADA | CORRECCION_IA | Alcance aceptado, reserva de corrección, snapshot y responsable |
| CORRECCION_SOLICITADA | ENTREGADA / COMPLETADA | Solicitud retirada o fuera de alcance; resolución explícita y liberar reserva |
| ENTREGADA | COMPLETADA | Cliente acepta o termina ventana, sin corrección abierta |
| estados previos a ENTREGADA | CANCELADA | Cliente solo antes de iniciar generación; después administrador con razón y conciliación |

CANCELADA terminal. No cancelar desde estados de corrección posteriores a entrega: cerrar corrección preservando entrega. APROBADA registra aprobación automática o humana según plan, sin salto implícito. Corrección humana pasa por auditoría antes de volver a REVISION_HUMANA; es una ampliación deliberada del flujo base para no entregar una corrección sin auditar.

Rechazar/escalar deja REVISION_HUMANA con bloqueo y decisión registrada. Administrador puede pedir corrección, reasignar o cancelar según las guardas; no omitir checklist ni crear aprobación humana ficticia. Error técnico mantiene estado, crea bloqueo con etapa fallida y permite reintento idempotente; no significa cancelación.

## Envío y consumo

Completitud: propietario verificado, grupo activo, grado válido del catálogo, fechas, proyecto/tema, selecciones curriculares compatibles y confirmadas, evaluación confirmada y formato utilizable; distinguir campo opcional de declaración explícita no aplicable. No inventar contenido faltante. Mostrar todas las causas; priorizar ESPERANDO_INFORMACION si faltan datos y pago simultáneamente.

Enviar congela perfil, datos variables, catálogo textual completo seleccionado, cálculo comercial, segmentos, versión de formato, manifest y derechos; valida plan activo/periodo pagado. Reservar planning_units unidades de planeación y, si human_review_required, planning_units unidades de revisión humana en una transacción con bloqueo de periodo. Sin cupo, mostrar bloqueo de derechos en ESPERANDO_PAGO con explicación de que falta plan/cupo, no pago fallido ficticio. No iniciar pipeline. Cambios de perfil no alteran solicitudes enviadas.

Consumir todas las planning_units reservadas al comenzar primera generación; consumir las unidades humanas reservadas al asignar primer ciclo humano. Cancelar antes de inicio libera reservas. Reintentos, correcciones internas y reasignaciones no vuelven a cobrar cupo. Fallo definitivo administrativo puede restituir cupo con acción auditada; estado released una sola vez. Reserva de corrección cliente se consume al iniciar corrección; fallo definitivo admite restitución auditada. Si hay revisión incluida, las vueltas internas del ciclo no gastan otro cupo humano.

Plan vencido impide solicitudes nuevas; trabajo ya aceptado y correcciones cubiertas continúan con derechos congelados. Al renovar crear periodo nuevo, nunca resetear el anterior. Grupo permitido limita grupos activos bajo bloqueo de cliente; reducir plan impide crear nuevos y solicitar hasta archivar excedentes elegidos por cliente, sin borrar historial.

Editar insumos durante producción requiere acción de administrador que detiene nuevas etapas, incrementa input_revision e invalida aprobaciones vigentes para la nueva entrada. Solo subsanar errores dentro del mismo alcance permite reiniciar bajo las mismas reservas; ampliar fechas, cambiar grupo/currículo o agregar otro proyecto exige nueva solicitud y cotización, no una corrección gratuita. Jobs viejos no pueden promover resultado. Historial y costos de ejecuciones obsoletas se conservan.

## Pago y suscripción

PaymentGateway: createCheckout(order,idempotencyKey), getPayment(reference), verifyWebhook(headers,rawBody), refund(payment,amount,key). DTOs normalizados; controladores no deciden derechos. Adapter manual permite registrar comprobante y confirmar administrativamente; adapter fake solo pruebas; adapter real posterior.

Crear pedido con monto/moneda del plan versionado. Confirmación confiable manual o webhook firmado → validar referencia, monto y moneda → bloquear pedido/cliente → deduplicar evento → registrar pago → activar periodo una sola vez → outbox. Redirección del navegador nunca confirma pago. Eventos retrasados no degradan un pago exitoso; discrepancia queda en conciliación. Dos pagos exitosos por el mismo pedido se registran como cobro excedente a resolver, no activan dos periodos.

Renovación: pedido nuevo; activar solo después de pago; fallo produce past_due y aviso. No asumir cobro recurrente hasta integrar consentimiento y proveedor. Cancelación al fin del periodo conserva derechos pagados. Devolución no borra pago ni versiones; solicitud administrativa decide acceso futuro y restitución de cupos, preserva trabajo entregado y ajusta métricas. Devolución pendiente no se cuenta como confirmada.

## Asignación y revisión

Filtrar revisores activos, grade_id autorizado de esa versión curricular, disponibilidad cubriendo ventana y carga en unidades + planning_units <= max_load; daily_max también mide unidades de asignaciones iniciales del día local (incluye completadas, excluye anuladas antes de iniciar). Al publicar nuevo catálogo, autorizaciones de grado requieren remapeo explícito, no herencia por ordinal. Orden: vencimiento de solicitudes, menor carga relativa, asignación más antigua, ID estable. Bloquear candidato y solicitud y recalcular límites antes de asignar. Sin candidato: no_reviewer y alerta; nunca asignar a no autorizado. Reasignación administrativa conserva historial y tarifa, libera carga anterior y verifica nuevo candidato.

Checklist inicial con 15 claves: grado, fechas, contenidos, PDA, campos, ejes, coherencia, dificultad, actividades, inicio/desarrollo/cierre, evaluación, materiales, transversalidad, ortografía, formato. Admin publica versiones configurables; revisión congela una versión. Todos los obligatorios deben ser true en servidor, versión actual y asignación activa. Nueva versión no hereda respuestas ni aprobación. Revisor marca secciones y comentarios, CorrectionService modifica solo alcance solicitado.

Aprobar crea trabajo pagable una vez: planning_units × tarifa por unidad congelada; units_snapshot y total_fee_minor quedan fijos. Correcciones cubiertas e internas del mismo alcance forman parte de ese trabajo; no generan otro honorario ni consumo humano automático. Ciclos internos no generan pagos extra. Reasignación antes de aprobar no genera honorario automático; excepciones deben quedar como ajuste administrativo explícito, fuera del cálculo ordinario. Admin aprueba liquidación y marca pagado con referencia; sin nómina.

## Entrega y notificaciones

ENTREGADA significa disponible en portal con registro de versión y manifest, no que el correo se leyó. Fallo de email no revierte entrega. Descargas sucesivas no duplican entrega. Corrección conserva acceso a versión anterior y entrega nueva independiente.

Eventos: PaymentConfirmed, InformationRequired, ProcessingStarted, ReviewAssigned, DocumentReady, CorrectionCompleted, RenewalUpcoming. Notificaciones internas y email mediante listeners/jobs; futuros canales por adapter, sin lógica WhatsApp. Deduplicar evento+destinatario+canal; scheduler no repite aviso en cada ejecución. Registrar auditoría para pago, transición, asignación, aprobación, IA, corrección, entrega y descarga sensible.


## NUEVA PLANEACIÓN desde el docente — MVP

1. Pulsa NUEVA PLANEACIÓN. RÁPIDO es el modo inicial; AVANZADO está disponible sin reiniciar el borrador. Elegir grupo precarga grado/fase, duración de sesión, características, dificultades, necesidades, preferencias, materiales disponibles, restricciones y formato preferido. Mostrar resumen compacto con «Cambiar», no un formulario repetido. Si falta perfil esencial, pedir solo ese dato.
2. Indica fechas, tema/proyecto, páginas/material y eventos u observaciones. Estos últimos pueden omitirse si no aplican. Se muestra al instante cuántas unidades ocupará; no pedir datos curriculares de memoria. Archivos se reutilizan por referencia autorizada, no se suben de nuevo. No inferir contenido de páginas de un libro no identificado.
3. En RÁPIDO el servicio propone campos, contenidos, PDA, ejes y evaluación inicial desde catálogo publicado filtrado por grado. Mostrar textos legibles y breve explicación. Docente confirma o cambia selección; sin coincidencia ofrecer búsqueda AVANZADA y conservar lo capturado. En AVANZADO selecciona contenidos/PDA del catálogo con filtros y ejes; campos se derivan y evaluación puede escribirse o aceptar sugerencia. Cambiar modo conserva selecciones compatibles; jamás confirma automáticamente.
4. Revisa resumen de periodo, grupo, selección, formato, unidades requeridas/disponibles y revisión incluida. Confirmación explícita cubre currículo y consumo. Si fechas/perfil/material/catálogo cambiaron desde la propuesta, pedir reconfirmar solo lo afectado. Si el plan cambió, recalcular cotización y volver a confirmar; un webhook no autoriza por sí solo un consumo distinto. Formato institucional pendiente ofrece estándar o esperar configuración, sin prometer render universal.
5. Envía. Servidor revalida perfil, catálogo, fingerprint, derechos y archivos; congela snapshot y reserva unidades atómicamente. No comprar extras ni dividir automáticamente en solicitudes cobradas sin confirmación. Muestra seguimiento en lenguaje sencillo; cuando termina recibe aviso y descarga. Corrección desde entrega con descripción y límites visibles; renovación desde plan/uso al finalizar periodo.

Meta: solicitud repetida <3 minutos, idealmente menos, medida desde pulsar NUEVA PLANEACIÓN hasta confirmación con datos básicos listos, incluyendo respuesta del servicio. No garantizar tiempo de procesamiento posterior ni ocultar espera del asistente en la métrica. Generar propuesta/guardar borrador no consume cupo; límites operativos de frecuencia protegen servicio.

## Unidad comercial y límites — MVP

Regla calendar_days_v1: D = (ends_on - starts_on) + 1, en DATE, días naturales inclusivos; M = PlanVersion.max_planning_days; U = ceil(D/M). M>0, D>0. Una unidad cubre como máximo M días consecutivos para un grupo y un proyecto (puede integrar varios campos). Segmentos consecutivos de hasta M días, último puede ser menor. Una solicitud larga conserva un solo documento/revisión pero consume U unidades y produce secciones por segmento. Fechas no contiguas, cálculo por festivos/días lectivos y varios proyectos independientes por unidad quedan POST-MVP.

| Configuración | Semántica |
|---|---|
| max_planning_days | Máximo de días naturales por unidad, no límite total por solicitud |
| planning_limit | Unidades disponibles por periodo de suscripción; reservar/consumir U |
| human_review_limit | Unidades revisadas disponibles por periodo; reservar/consumir U si human_review_required |
| correction_limit | Número de rondas de corrección del cliente por solicitud enviada, cada ronda sobre su alcance original; reservar/consumir 1 |

correction_limit sustituye correction_limit_per_request como nombre, mantiene alcance por solicitud. No multiplicar rondas por U: cada ronda puede corregir todos los segmentos originales. No hay otra cuota periódica de correcciones en MVP. Trabajo interno por fallos de plataforma no consume esas rondas. Plan revisado publica human_review_required=true y human_review_limit suficiente para planning_limit, sin degradar silenciosamente a IA; plan IA tiene false y human_review_limit=0. Límites finitos en MVP.

Ejemplo puramente ilustrativo, no seeder comercial: M=7, D=7 → U=1; D=8 → U=2; D=28 → U=4. No se hardcodea semana de cinco o siete días. Días naturales incluyen fines de semana y festivos aunque no haya sesiones; es una unidad de cobertura temporal, no conteo de sesiones. La ficha del plan debe decirlo expresamente. Calendario lectivo configurable queda POST-MVP si el piloto demuestra necesidad.

Reserva conjunta exige saldo de U unidades de cada recurso necesario en un solo periodo; no tomar saldo de dos periodos ni acumular remanentes. Si no alcanza, mantener borrador/espera comercial y proponer acortar fechas o contratar/renovar según disponibilidad, sin enviar automáticamente. Propuesta no consume; primera generación consume U una sola vez; cancelación previa libera U; reintento no suma. El cálculo congelado prevalece aunque se publique otro plan.

Un período educativo puede cruzar fechas del periodo de facturación: el derecho se evalúa al envío, U completo se carga al periodo activo y queda congelado. Correcciones conservan fechas y unidades originales. Contenido nuevo o ampliación de periodo requiere nueva solicitud. Si hay edición de fechas antes de generar, cancelar/liberar reserva y volver a confirmar/reservar atómicamente; nunca cambiar la cantidad sin validarla y mostrarla.

## Presentación de estados /app

| Backend o condición | Texto docente / acción |
|---|---|
| BORRADOR | Borrador / Continuar |
| ESPERANDO_INFORMACION o bloqueo que requiere datos | Necesitamos información / Completar lo indicado |
| ESPERANDO_PAGO | Activa tu plan, Completa tu pago o Unidades insuficientes, según causa real |
| LISTA_PARA_PROCESAR, GENERACION_IA, AUDITORIA_IA, CORRECCION_IA, APROBADA, GENERANDO_DOCUMENTO | Preparando |
| REVISION_HUMANA | En revisión |
| CORRECCION_SOLICITADA | Corrección solicitada |
| LISTA_PARA_ENTREGAR | Preparando, hasta publicación efectiva |
| ENTREGADA, COMPLETADA con entrega publicada | Lista para descargar |
| CANCELADA | Cancelada |

Bloqueo técnico nunca muestra proveedor/error/tokens: «Tu planeación está tardando más de lo esperado» y atención interna. No pedir información al docente por un fallo técnico. Durante corrección se conserva acceso visible a la entrega anterior. Mapper de presentación independiente del enum; no renombrar estados de dominio ni exponer metadatos técnicos en respuestas al cliente.

Corrección posterior con humano: reabrir ciclo/asignación original si el revisor sigue elegible y disponible, recalculando carga operativa U pero sin nuevo consumo comercial ni honorario. La asignación pasa a activa y una nueva versión obtiene otra reviews; el trabajo pagable único se conserva. Si el revisor no puede atender, administrador reasigna y registra ajuste operativo de honorario si corresponde, sin cobrar de nuevo al cliente. La capacidad incluye correcciones activas aunque su saldo humano comercial ya esté consumido.
