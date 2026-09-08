# MVP comercial

## Alcance

Construir una plataforma de tres roles que venda y entregue planeaciones personalizadas con mínimo trabajo administrativo. Esta entrega es arquitectura, no una aplicación desplegable. El modo manual permite validar operación; no satisface por sí solo la meta de automatización.

Incluido: registro/verificación/reset, onboarding, escuela y varios grupos con perfil persistente; solicitudes rápidas y archivos privados; catálogo configurable, periodos y cupos; PaymentGateway con operación manual inicial; pipeline con contratos manual/API; prompts administrables; formato estándar y configuración institucional acotada; versiones, auditoría, checklist y revisión asignada; correcciones por plan; entrega privada; notificaciones internas/email; honorarios por trabajo; dashboard de atención y margen.

Para lanzamiento autoservicio automático: un adapter IA real, un gateway real con sandbox/webhooks/reconciliación, workers y renderer verificados. Hasta entonces etiquetar como piloto asistido, sin prometer cobro recurrente ni 90% automático.

## Fuera por ahora

Microservicios, organizaciones multiescuela, aplicación móvil, marketplace de revisores, nómina, facturación fiscal automática, WhatsApp, referidos/afiliados, editor colaborativo, analítica predictiva, varios proveedores activos a la vez, OCR universal, conversión perfecta de DOCX arbitrarios, prorrateos/cupones/planes por asiento y fine-tuning. Añadir solo si ayuda a vender, reduce trabajo/errores, retiene o permite escalar con evidencia.

## Métricas definidas

- Clientes activos: clientes distintos con periodo pagado vigente. MRR: precio recurrente neto de descuento contractual normalizado a mes (anual/12); excluir cobros únicos y pilotos gratuitos. No confundir con efectivo cobrado.
- Ingresos cobrados: pagos succeeded menos devoluciones confirmadas por fecha efectiva. Ticket promedio: cobros de pedidos pagados / número de pedidos pagados en ventana; mostrar devoluciones aparte y denominador.
- Ingreso asignado a solicitud: (neto del periodo / planning_limit) × planning_units de la solicitud, congelado al consumir. Asignar cada unidad un ordinal contable único del periodo; repartir centavos residuales a los primeros ordinales y sumar los correspondientes a la solicitud. Una restitución libera/revierte la asignación del mismo ordinal con historial, no duplica ingreso. Cupos no usados quedan como ingreso no asignado del periodo, no se redistribuyen retroactivamente. Plan ilimitado queda fuera del catálogo inicial mientras no exista regla de asignación aprobada.
- Margen operativo por solicitud = ingreso asignado - costo IA de todos sus intentos - honorario devengado - comisión asignada - devoluciones asignadas. Comisión del periodo se distribuye con la misma base de cupos; devolución se distribuye proporcionalmente entre asignaciones y saldo no asignado. Marcar provisional mientras falten costos; dato desconocido no es cero. No presentarlo como utilidad fiscal ni contabilidad completa.
- IA: ejecuciones, tasa de fallo, costo estimado/real, costo por planeación y reintentos. Registrar moneda y conversión aplicada.
- Operación: solicitudes/entregas, tiempo desde envío hasta entrega (incluye espera) y tiempo humano activo separado por actividad.
- Revisor: carga activa, aprobaciones, promedio de tiempo activo por revisión completada, aprobaciones / decisiones concluidas, correcciones posteriores / entregas revisadas, incidencias por gravedad. Calidad como indicadores observables, sin score opaco ni ranking con muestras mínimas.
- Automatización: porcentaje de etapas elegibles completadas sin intervención y porcentaje de solicitudes sin intervención administrativa; registrar minutos humanos por solicitud y comparar con línea base manual. No afirmar 90% de trabajo por contar jobs.

Todos los KPI indican ventana, zona America/Mexico_City, numerador/denominador y N/A si denominador cero. Instantes UTC en DB. Consultas y agregaciones simples inicialmente, no data warehouse.

## Criterios de aceptación

1. Cliente configura un grupo y reutiliza todos sus datos; solicitud posterior <3 minutos en prueba con catálogo disponible y propuesta confirmada, sin recaptura curricular.
2. Cliente A jamás accede a B; revisor solo asignación vigente, incluidos archivos y búsquedas.
3. Sin plan/cupo o información no inicia generación; concurrencia no duplica consumo/pago.
4. Flujo IA sin humano y flujo revisado completan hasta descarga; no aprobar checklist incompleto.
5. Fallos/reintentos/correcciones conservan versiones y costos; ninguna entrega sin manifest y aprobación de esa versión.
6. Formato institucional no configurado bloquea render; estándar produce DOCX y PDF legibles, verificados visualmente.
7. Admin resuelve casos fallidos desde dashboard y revisor trabaja en una pantalla.
8. Margen se reconcilia con ejemplos de periodo, cupos, comisiones, microcostos y devoluciones.
9. Piloto con datos ficticios prueba backup/restauración, worker reiniciado y notificaciones sin duplicados.

## Decisiones previas a venta

Definir precios, periodicidad, cuotas, ventana/cobertura de correcciones, SLA y tarifas de revisor; escoger proveedores IA/pagos y hosting; definir retención y términos/aviso de privacidad; validar calidad pedagógica y plantillas con muestras; cargar/validar catálogo real mediante proceso editorial separado antes de venta, sin ejecutarlo en esta entrega. Son decisiones de producto pendientes, no motivo para impedir el trabajo técnico local. No publicar ni contactar clientes en esta fase.


## Clasificación de la iteración funcional

| Funcionalidad nueva | Clasificación | Justificación |
|---|---|---|
| PostgreSQL como única base, CI e integridad | MVP | Base elegida; evitar divergencias y errores |
| Ocho entidades curriculares, referencias, publicación inmutable y snapshot textual | MVP | Generar/revisar sin recaptura ni cambios históricos |
| Administración mínima de borradores/publicación y búsqueda por grado/campo | MVP | Operar catálogo y reducir errores |
| Importación administrativa de JSON/CSV estructurado con schema versionado, validación transaccional y dry-run | MVP — Fase 2 | Evitar captura manual de cientos de contenidos/PDA; crear solo borrador, nunca publicar |
| Seeders ficticios y pruebas de dos versiones, solo diseño en esta entrega | MVP | Verificar sin introducir datos oficiales incorrectos |
| RÁPIDO con propuesta determinista y confirmación; AVANZADO con selección | MVP | Reducir trabajo humano y facilitar venta autoservicio |
| Perfil/formato reutilizados y NUEVA PLANEACIÓN principal | MVP | Reducir captura y favorecer renovación |
| Estados comprensibles sin detalles IA | MVP | Evitar fricción y consultas administrativas |
| Consumo U por duración, segmentos y unidades de revisión | MVP | Evitar trabajo mensual cobrado como semanal |
| correction_limit por ronda/solicitud, sin ampliación de alcance | MVP | Mantener promesa comercial y controlar costos |
| Margen, carga y honorarios ponderados por unidades | MVP | Mantener rentabilidad y asignación coherentes |
| Sugerencia curricular mediante IA, embeddings o aprendizaje | POST-MVP | Búsqueda/reglas cubren primera versión; generación IA permanece MVP |
| Calendarios escolares, días lectivos/festivos automáticos | POST-MVP | Días naturales transparentes permiten vender sin motor de calendarios |
| Sincronización/actualización externas automáticas, PDF inteligente, scraping, procesamiento automático de documentos curriculares, equivalencias y multigrado | POST-MVP | La carga administrativa estructurada cubre el MVP sin automatizar fuentes heterogéneas |

Se mantiene formato estándar completamente soportado e institucional mediante análisis/mapping/prueba/publicación. Ninguna conversión universal DOCX entra en MVP. No agregar fases mayores: integrar tareas en fases existentes.

## Aceptación adicional

- Importación JSON/CSV únicamente conforme al schema versionado descrito en CURRICULUM.md: validar versión, fases, grados, campos, contenidos, PDA, ejes y relaciones; rechazar duplicados y referencias inexistentes. Transacción todo-o-nada, resumen/errores claros y dry-run sin escrituras. Solo crear nueva versión borrador; no modificar versiones existentes ni publicar automáticamente. El archivo no acredita oficialidad: revisión administrativa/editorial obligatoria antes de publicar.
- Catálogo publicado no admite agregar/editar/eliminar descendientes; segunda versión no cambia solicitud/entrega anterior. Bloquear selecciones de distinto grado/fase/versión y publicación incompleta.
- Rápido y avanzado producen el mismo snapshot y costo cuando seleccionan lo mismo. Propuesta sin coincidencias/obsoleta exige resolución explícita y nunca envía sola.
- U respeta fronteras M, M+1 y varios M; periodos cruzan mes/año, fechas inválidas se rechazan, fines de semana se cuentan según regla visible. Sin cinco días fijos.
- Revisión consume U, asignación carga U y honorario tarifa×U; vueltas cubiertas no duplican. correction_limit es rondas por solicitud, no saldo global ni cupo multiplicado por duración.
- Ingreso y comisiones por unidades cuadran con saldo no asignado y devoluciones; costos desconocidos siguen provisionales. Mostrar también número de solicitudes, separado de unidades vendidas/consumidas.
- /app no devuelve metadatos técnicos de IA. Medir tiempo completo del asistente y sus esperas con perfil guardado; objetivo <3 minutos.

## Ambigüedades resueltas y límites

max_planning_days limita cada unidad, no prohíbe una solicitud extensa; esta ocupa varias unidades del mismo periodo. Se eligen días naturales inclusivos, transparentes en oferta; confirmar encaje comercial en piloto. correction_limit mantiene significado anterior por solicitud con nombre nuevo. Revisiones/carga/honorarios antes contaban documentos: ahora ponderan unidades para evitar subestimar trabajos largos. No mezclar selección editable y snapshot como dos fuentes de verdad.

Sin catálogo real validado no hay lanzamiento curricular autoservicio: el diseño y datos DEMO solo permiten pruebas. Una propuesta por tema es recomendación, no validación oficial; el docente confirma. Si material no está identificado, no afirmar correspondencia de páginas. El alcance sigue un grupo/grado y proyecto por solicitud; varios campos son posibles, multigrado no. No se inicia implementación.
