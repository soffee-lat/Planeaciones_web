# Tareas y evidencia

Fecha: 2026-09-07. Fase 0 documental revisada con iteración curricular/comercial y PostgreSQL; implementación no iniciada. Esta entrega se detiene en arquitectura conforme al alcance previo al código. Los checks describen trabajo efectivamente realizado, no funcionalidades existentes.

## Fase 0 — Diseño

- [x] Leer especificación y documentos existentes; detectar conflicto de alcance.
- [x] Preservar PLAN/DATABASE/TASKS originales en docs/legacy.
- [x] Definir arquitectura, módulos, entidades/relaciones, estados y permisos.
- [x] Diseñar tres paneles, IA, archivos, queues, riesgos y exclusiones.
- [x] Definir consumo, idempotencia, correcciones, versiones y margen.
- [x] Consultar documentación oficial de Laravel 13 y Filament 5.
- [x] Revisar coherencia documental y dividir fases pequeñas verificables.

Evidencia: ARCHITECTURE.md, DATABASE.md, WORKFLOWS.md, PERMISSIONS.md, AI_PIPELINE.md, MVP.md y este archivo; PLAN.md como índice. Revisión estática; no instalación ni pruebas de aplicación. La fase anterior de administrador único quedó sustituida, no implementada.

## Fase 1 — Base y acceso

- [ ] Verificar PHP/extensiones, Composer, Node, pdo_pgsql y PostgreSQL; resolver y fijar dependencias.
- [ ] Instalar Laravel/Filament, tres PanelProviders, español, UTC y zona de negocio.
- [ ] Crear identidad/roles, registro, correo, reset, onboarding y Policies base.
- [ ] Configurar pruebas PostgreSQL, factories ficticias, queues y storage privado.
- [ ] Probar login, roles, acceso cruzado y correo no verificado; revisar UI responsive.

Salida: tres accesos aislados con suite de autorización pasando; lockfiles y .env.example sin secretos.

## Fase 2 — Grupos y borradores

- [ ] Catálogo curricular: ocho entidades, integridad por versión, publicación inmutable y administración mínima.
- [ ] ImportCurriculumDraft/CurriculumImportService y comando administrativo conceptual curriculum:import: JSON/CSV con schema versionado según CURRICULUM.md, autorización y creación exclusiva de nuevo borrador.
- [ ] Validar todas las entidades/relaciones, códigos duplicados, referencias inexistentes y destino; transacción todo-o-nada, resumen/errores por ubicación y dry-run sin escrituras; nunca publicar ni sobrescribir versiones.
- [ ] Probar equivalencia JSON/CSV, schema inválido, duplicados, referencias/fases incompatibles, permisos, versión publicada/borrador existente, rollback intermedio, concurrencia y dry-run; fixtures ficticias y publicación editorial separada.
- [ ] Seeders DEMO diseñados en CURRICULUM.md, solo local/test; no cargar currículo real automáticamente.
- [ ] Escuelas/grupos/perfil con grade_id curricular, formato preferido y propiedad validada.
- [ ] NUEVA PLANEACIÓN rápido/avanzado, CurriculumSuggestionService determinista, selección y confirmación con fingerprint.
- [ ] Solicitud con pivotes curriculares, snapshot textual completo por revisión y perfil reutilizado.
- [ ] Probar inmutabilidad incluso al agregar descendientes, mezcla de versiones/grado/fase, propuesta obsoleta/sin coincidencia y acceso a sugerencia ajena.
- [ ] Upload en cuarentena, validación y descarga privada autorizada.
- [ ] Probar ownership anidado, formatos/tamaño y ausencia de recaptura.
- [ ] Medir solicitud con perfil completo y corregir fricción.

Salida: captura aislada y reutilizable e importación estructurada validada hacia borrador, sin publicación automática; aún no procesar solicitudes sin derechos.

## Fase 3 — Planes y pagos

- [ ] PlanVersion: max_planning_days/planning_limit/correction_limit/human_review_limit; cotización U=ceil(D/M), segmentos y confirmación.
- [ ] Periodos, reservas con quantity=U, corrección por ronda/solicitud y límites de grupos.
- [ ] Probar D=M, M+1, varios M, mes/año, sin saldo U, cambio de plan/fechas y reserva concurrente.
- [ ] PaymentGateway, adapters manual/fake, pedidos, eventos y devoluciones.
- [ ] Renovación/cancelación de periodo; UI de plan, uso y pagos propios.
- [ ] Probar concurrencia, doble confirmación, cuota agotada, devolución parcial y expiración.

Salida: ningún doble cargo lógico ni sobreconsumo; no confundir pago manual con gateway real.

## Fase 4 — Pipeline vertical manual

- [ ] Máquina de estados/bloqueos/eventos y outbox con recuperación.
- [ ] PromptTemplate/PromptVersion, contratos IA y modo manual.
- [ ] Document/DocumentVersion, snapshot curricular confirmado y cobertura de todos los segmentos; auditoría y corrección por sección.
- [ ] Mapper de estados /app sin metadatos de proveedor/prompts/tokens/ejecuciones.
- [ ] Probar ambos itinerarios, saltos ilegales, duplicados y versiones inmutables.

Salida: recorrido reproducible con fake/manual hasta aprobación automática o cola de revisión.

## Fase 5 — Calidad

- [ ] Disponibilidad/capacidad en unidades, grados autorizados por catálogo, asignación atómica y reasignación.
- [ ] Pantalla de revisión única y checklist configurable versionado.
- [ ] Corrección/rechazo/escalamiento, nueva versión y auditoría posterior.
- [ ] Honorarios tarifa×U por ciclo aprobado, liquidación y métricas propias; correcciones cubiertas sin doble pago.
- [ ] Probar checklist incompleto, asignación concurrente, revocación y pago único por trabajo.

Salida: revisión exacta por versión sin reescribir documentos completos ni exponer datos comerciales.

## Fase 6 — Documentos y retención

- [ ] Renderer estándar DOCX/PDF; flujo institucional análisis/mapping/muestra/publicación.
- [ ] Generación en queue, manifest, entrega privada e historial.
- [ ] Corrección cliente por ventana/cuota conservando entrega anterior.
- [ ] Notificaciones internas/email y renovación próxima deduplicadas.
- [ ] Probar render fallido, formato pendiente, descarga ajena, corrección tras expiración y email fallido.
- [ ] Verificar visualmente DOCX/PDF de muestras y navegación cliente/revisor.

Salida: entrega y corrección descargables, trazables y visualmente válidas.

## Fase 7 — Automatización real

- [ ] Elegir e integrar un proveedor IA; presupuesto, timeouts, schema y costos.
- [ ] Elegir gateway real; sandbox, firma, conciliación y reembolso idempotente.
- [ ] Probar timeout ambiguo, evento repetido/desordenado y caída/reinicio de worker.
- [ ] Verificar contingencia manual explícita y datos minimizados.

Salida: pipeline API y compra autoservicio reales verificados en sandbox; no declarar listo antes.

## Fase 8 — Operación y piloto

- [ ] Dashboard de atención con enlaces de resolución, ventas, IA y revisores.
- [ ] Margen ponderado por unidades y conciliación de asignaciones/costos/devoluciones, centavos y restituciones.
- [ ] Suite completa: permisos, ownership, estados, límites, consumo, asignación, revisión, versiones, correcciones, pagos y pipeline.
- [ ] Build de assets y recorrido responsive/teclado con datos ficticios en ambos planes.
- [ ] Configurar workers/scheduler, backup y restauración probada.
- [ ] Definir políticas comerciales, retención, proveedores y calidad antes de venta.
- [ ] Medir tiempos y automatización contra línea base; documentar limitaciones.

Salida: evidencia de pruebas, restauración, calidad documental y costos; publicación fuera de esta entrega.

## Regla de actualización

En cada fase registrar fecha, archivos cambiados, comandos/pruebas ejecutados, resultados, revisión visual cuando aplique y limitaciones. Marcar solo verificaciones ejecutadas. Actualizar este archivo durante todo el desarrollo; no convertir un diseño propuesto en prueba aprobada.

### Evidencia de revisión documental — 2026-09-07

Se verificó existencia y contenido de los siete documentos, equilibrio de bloques Markdown y correspondencia de los nombres de estado/entidades entre flujos, datos y pipeline. Se revisaron manualmente las ramas IA/revisada, corrección con nueva auditoría, consumo idempotente y aislamiento tras reasignación. Los documentos son archivos nuevos sin seguimiento en Git; git diff --check no valida su contenido por sí solo. No se ejecutaron tests de aplicación, migraciones ni instalación de dependencias.


## Iteración funcional — diseño terminado, sin código

- [x] Mantener monolito/tres paneles y adoptar PostgreSQL como única base objetivo.
- [x] Diseñar catálogo curricular versionado, inmutabilidad del árbol, pivotes y snapshot textual en CURRICULUM.md.
- [x] Diseñar seeders ficticios, sin crear scripts ni cargar información curricular real.
- [x] Diseñar NUEVA PLANEACIÓN rápido/avanzado, servicio determinista desacoplado y perfil/formato reutilizados.
- [x] Definir unidad calendar_days_v1, consumo proporcional, correcciones por ronda, revisión/carga/honorarios ponderados.
- [x] Clasificar adiciones MVP/POST-MVP sin ampliar fases mayores; conservar límites de formatos.

Documentos afectados: ARCHITECTURE.md, DATABASE.md, WORKFLOWS.md, AI_PIPELINE.md, MVP.md, TASKS.md y nuevo CURRICULUM.md. PERMISSIONS.md y archivos históricos se mantienen intactos. Las tareas técnicas anteriores siguen pendientes.

### Migraciones que habrá que crear (no creadas ahora)

1. Catálogo: curricula, curriculum_versions, educational_phases, grades, formative_fields, curricular_contents, pdas, articulating_axes; claves compuestas, publicación y triggers de inmutabilidad. selectable_version_id después de curriculum_versions.
2. Grupos/perfil: curriculum_version_id/grade_id y preferred_format_id; agregar FK de formato después de crear formatos. reviewer_grades para autorizaciones por versión.
3. Solicitudes: versión curricular, grado, modo, revisión/confirmación y evaluación sugerida; request_curricular_contents, request_pdas y request_articulating_axes; snapshot textual en request_input_versions. curriculum_suggestions con fingerprint y estrategia.
4. Comercial: cuatro límites PlanVersion, planning_days/planning_units/commercial_calculation_snapshot, planning_request_segments y reservas con cantidades. No dejar correction_limit_per_request duplicado.
5. Calidad/finanzas: units_snapshot, tarifa por unidad/total en asignación y trabajo; units y ordinales de asignación de ingreso. Mantener historial/idempotencia existentes.
6. PostgreSQL: tipos timestamptz/JSONB/NUMERIC/bigint, CHECK y FK; índices únicos parciales de suscripción vigente, asignación activa y corrección abierta; completar FK circulares al final.

Como no hay aplicación ni migraciones iniciales, incorporar estos campos al crear tablas; no escribir ALTER ni importación MySQL innecesarios. Mantener las demás migraciones base ya planeadas (usuarios, pagos, documentos, jobs, etc.). Fase 1 fija versión PostgreSQL y prueba conexión/extensiones; Fases 2–6 materializan las extensiones correspondientes.

### Verificación documental de esta iteración

Se revisaron referencias a MySQL, correction_limit_per_request, cupo humano fijo y captura curricular manual: solo quedan menciones históricas o explicaciones de sustitución. Se verificaron los siete documentos afectados, enlaces locales existentes y bloques Markdown equilibrados mediante revisión estática. Se contrastaron además consumo U, carga/honorarios y corrección sin doble cobro entre documentos. No se han ejecutado tests de aplicación, migraciones ni seeders; PostgreSQL es una decisión de diseño todavía no provisionada. La próxima acción de implementación requiere un nuevo turno autorizado: NO comenzar Fase 1 en esta entrega.

### Ajuste documental — importación estructurada

- [x] Diferenciar importación administrativa JSON/CSV (MVP/Fase 2) de sincronización, PDF inteligente, scraping y procesamiento automático (POST-MVP).
- [x] Diseñar servicio/acción/comando, schema, transacción, dry-run, resumen y errores; crear solo borrador con publicación editorial independiente.

Solo CURRICULUM.md, MVP.md y TASKS.md afectados por este ajuste. No requiere nuevas tablas: usa catálogo y auditoría existentes. No se implementó el comando, no se importaron datos y no se inició Fase 1; las verificaciones de ejecución de Fase 2 permanecen pendientes.

