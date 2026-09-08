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

- [x] Verificar PHP/extensiones, Composer, Node, pdo_pgsql y PostgreSQL; resolver y fijar dependencias.
- [x] Instalar Laravel/Filament, tres PanelProviders, español, UTC y zona de negocio.
- [x] Crear identidad/roles, registro, correo, reset, onboarding y Policies base.
- [x] Configurar pruebas PostgreSQL, factories ficticias, queues y storage privado.
- [x] Probar login, roles, acceso cruzado y correo no verificado; revisar UI responsive.

Salida: tres accesos aislados con suite de autorización pasando; lockfiles y .env.example sin secretos.

### Evidencia Fase 1 — 2026-09-08

Verificado por el agente de cierre, sin iniciar Fase 2.

- Runtime: PHP 8.4.7 con `tools/php.ps1` cargando `.runtime/php.ini` que habilita `pdo_pgsql` y `pgsql`; Laravel 13.31.0; PostgreSQL local escuchando en `127.0.0.1:55432` (base `planeaciones` / `planeaciones_test`, usuario `planeaciones`). `composer.lock` y `package-lock.json` presentes; `.env.example` sin secretos y `.env.testing` con credencial local aislada.
- Config: `config/database.php` con `pgsql` como única conexión activa; `config/queue.php` con `database` por default; `config/filesystems.php` con disco `private` en `storage/app/private` y `serve => false`; locale `es`, timezone UTC. Tres `PanelProvider` (`AppPanelProvider`, `ReviewPanelProvider`, `AdminPanelProvider`) montan `/app`, `/review`, `/admin` sobre `BasePanelProvider` que aplica `emailVerification`, `emailChangeVerification`, `databaseTransactions`, middleware CSRF/sesión y `EnsureActivePanelAccess`.
- Identidad: migración `2026_09_08_000001_add_identity_and_roles.php` añade `roles`, `role_user` (PK compuesta) y columnas `status`+`onboarding_completed_at`. `User` implementa `FilamentUser` y `MustVerifyEmail`; `RoleCode` enum (Customer/Reviewer/Administrator). Registro público sólo desde `/app` vía `RegisterCustomer`; reset y verificación cubiertos por `RequestPasswordReset`, `EditProfile`, `Login` (normaliza email). Onboarding en `App\Filament\App\Pages\Onboarding` con acción `CompleteOnboarding`. `UserPolicy` gobierna `view`/`update`/`completeOnboarding`.
- Suite completa: `.\tools\php.ps1 vendor/phpunit/phpunit/phpunit --testdox` → `OK (28 tests, 142 assertions)` en 9.63 s. Cubre matriz de roles, rechazo de panel cruzado, verificación de correo obligatoria, suspensión y revocación aplicadas a sesión activa, registro público que ignora campos privilegiados, unicidad case-insensitive de email en PostgreSQL, unicidad del pivot `role_user`, CHECK sobre `status`, worker real sobre cola `database`, `afterCommit` en dispatch, row lock entre conexiones y storage privado sin ruta pública ni symlink.
- Revisión visual `/app`: reportada por el agente previo (dashboard, onboarding y responsive móvil del cliente). No re-verificada en este cierre.
- Revisión visual `/review`: servidor `php artisan serve` en `127.0.0.1:8000`. `/review/login` renderiza en español con marca `Planeaciones · Revisión` (acento morado) y enlace de reset. Autenticado, `/review` muestra topbar, avatar, sidebar `Inicio`, título `Espacio de revisión` y las dos secciones placeholder `Hola, {nombre}` y `Tu cuenta, bajo tu control` con botón a `/review/profile`. Contenido operativo intencionalmente ausente (Fases 5–6).
- Revisión visual `/admin`: `/admin/login` con marca `Planeaciones · Administración` (acento ámbar). Autenticado, `/admin` muestra topbar, sidebar `Inicio`, título `Administración` y las mismas dos secciones placeholder apuntando a `/admin/profile`. Sin recursos CRUD expuestos (Fases 2–8).
- Responsive: layout Filament v5 sirve las mismas vistas; NO se ejecutó verificación explícita en viewport móvil sobre `/review` y `/admin` en este cierre (queda como pendiente menor, ver más abajo). El responsive de `/app` fue revisado por el agente previo.
- Aislamiento: la matriz de roles y los rechazos de panel cruzado están cubiertos por `IdentityAccessTest::test_role_access_matrix_is_enforced_on_server`, `test_login_accepts_customer_and_rejects_wrong_panel_and_password`, `test_suspended_and_roleless_accounts_are_denied` y `test_unverified_accounts_cannot_open_any_dashboard`; y por `AccountSecurityTest::test_revoked_role_and_suspension_apply_to_existing_session` y `test_customer_dashboard_contains_no_other_customer_data`. Todos verdes en la corrida actual.
- Archivos modificados en este cierre: `TASKS.md` (esta sección de evidencia). No se tocó código de aplicación; no se corrigió ningún fallo porque la suite ya estaba en verde. Se creó y eliminó un archivo auxiliar temporal `storage/framework/seed_demo_users.php` para poblar dos usuarios demo (`admin@test.local`, `reviewer@test.local`) usados solo en la revisión visual local; no queda commiteado.
- Pendientes menores dentro del alcance de Fase 1: (a) verificación explícita de responsive en viewport móvil para `/review` y `/admin` (Filament es responsive por defecto pero no se probó a mano en este cierre); (b) documentar en README/DEV el uso de `tools/php.ps1` como envoltorio PHP obligatorio para trabajar en Windows con la Postgres local. Ambos no bloquean el cierre lógico de Fase 1.
- Fase 2 NO iniciada. No se creó ninguna migración, modelo, servicio o acción del catálogo curricular ni del importador; los checks de la sección Fase 2 permanecen en `[ ]`.

### Evidencia adicional Fase 1 — cierre pendientes 2026-09-08

- Responsive `/review` a 390×844 y 375×812 (Playwright viewport, `.runtime/review-390.png`, `.runtime/review-375.png`): topbar con hamburger a la izquierda + avatar a la derecha, sidebar oculta accesible por overlay, título `Espacio de revisión`, dos secciones apiladas verticalmente sin scroll horizontal, botón `Ver mi perfil` con tamaño táctil correcto. Sin defectos.
- Responsive `/admin` a 390×844 y 375×812 (`.runtime/admin-390.png`, `.runtime/admin-375.png`): equivalente a `/review`, con título `Administración` y marca `Planeaciones · Administración` al desplegar el sidebar. Overlay del sidebar verificado interactivamente. Sin defectos.
- Como no hubo problemas responsive reales, no se modificó código de la aplicación; ninguna corrección era necesaria. Los PNG están en `.runtime/` (fuera de Git) como respaldo local del reviewer.
- README actualizado con la sección "Desarrollo local en Windows" que documenta `tools/php.ps1` como envoltorio PHP obligatorio (porque el PHP global no trae `pdo_pgsql`) y ejemplos de ejecución para pruebas, artisan y composer.
- Suite completa re-ejecutada tras los cambios documentales: `OK (28 tests, 142 assertions)` en 10.1 s.
- Fase 2 continúa sin iniciar; ningún archivo bajo `app/`, `database/migrations`, `database/factories`, `app/Actions` o `app/Services` fue creado o modificado en este subcierre.

## Fase 2 — Grupos y borradores

- [x] Catálogo curricular: ocho entidades, integridad por versión, publicación inmutable y administración mínima.
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

### Evidencia Subfase 2A — 2026-09-09

Solo se implementó la primera viñeta ("Catálogo curricular"). El resto de la Fase 2 sigue pendiente.

- Migración: `database/migrations/2026_09_09_000001_create_curriculum_catalog.php` crea las 8 tablas (`curricula`, `curriculum_versions`, `educational_phases`, `grades`, `formative_fields`, `curricular_contents`, `pdas`, `articulating_axes`) con claves compuestas `(id, curriculum_version_id)`, FK compuestas para bloquear mezcla entre versiones, y `UNIQUE(curriculum_version_id, code)` por entidad. `curricula.selectable_version_id` como FK circular añadida al final. Triggers PL/pgSQL: `curriculum_element_immutability_guard` (bloquea INSERT/UPDATE/DELETE en hijos si la versión padre está publicada y evita cambiar de versión), `curriculum_version_immutability_guard` (bloquea modificar/borrar versiones publicadas; exige `published_by` y `checksum` no vacío al pasar de borrador a publicado), `curriculum_selectable_version_guard` (rechaza apuntar a versión inexistente, de otro currículo o borrador).
- Modelos + factories: `app/Models/{Curriculum,CurriculumVersion,EducationalPhase,Grade,FormativeField,CurricularContent,Pda,ArticulatingAxis}.php` con relaciones, casts y helpers `isPublished/isDraft` en `CurriculumVersion`. Factories con etiqueta "Datos ficticios, sin validez curricular." y consistencia cruzada (p. ej. `PdaFactory` genera el grado en la misma fase del contenido).
- Publicación: `app/Actions/Curriculum/PublishCurriculumVersion.php` como único camino autorizado. Bloquea la fila raíz de `curricula` y luego la de `curriculum_versions` con `FOR UPDATE`, valida el árbol (entidades no vacías, todo contenido con al menos un PDA, PDA en fase del contenido), calcula un `checksum` SHA-256 sobre el árbol ordenado y marca `published_at`/`published_by`. Lanza `RuntimeException` con códigos semánticos (`CURRICULUM_VERSION_EMPTY_*`, `CURRICULUM_CONTENT_WITHOUT_PDA:codes`, `PDA_GRADE_PHASE_MISMATCH:codes`, `CURRICULUM_VERSION_ALREADY_PUBLISHED`).
- Policies: `AuthorizesCurriculumTree` trait (lectura para cualquier rol activo verificado; escritura sólo Administrator). `CurriculumPolicy`, `CurriculumVersionPolicy` (`update`/`delete`/`publish`/`editTree` sólo si es borrador) y `CurriculumElementPolicy` abstracta reutilizada por las 6 entidades hijas. Auto-descubrimiento de Laravel las asocia por convención.
- Filament admin: recursos `App\Filament\Resources\Curricula\CurriculumResource` y `App\Filament\Resources\CurriculumVersions\CurriculumVersionResource`, registrados en `AdminPanelProvider->resources([...])`. El formulario de currículo restringe `selectable_version_id` a versiones publicadas del mismo currículo (Select con options dinámicas). La tabla de versiones muestra badge "Publicada", `EditAction` y `DeleteAction` ocultas si la versión no es borrador, y `Action::make('publish')` que invoca la acción y traduce errores a notificaciones.
- Seeder DEMO: `database/seeders/CurriculumDemoSeeder.php` (sólo local/testing) crea el currículo `DEMO` con 2 versiones — `DEMO-1` publicada y `DEMO-2` borrador (mismo esqueleto, un `full_text` de contenido cambiado para probar que la publicada se preserva). Verificado con `.\tools\php.ps1 artisan db:seed --class=CurriculumDemoSeeder` y `db:show --counts`: `curricula=1, curriculum_versions=2, educational_phases=4, grades=8, formative_fields=4, curricular_contents=8, pdas=16, articulating_axes=4`. `curriculum.selectable_version_id` apunta a la versión publicada.
- Pruebas nuevas (`tests/Feature/CurriculumCatalogTest.php`, 15 casos, 22 aserciones): publicación válida marca metadata y checksum de 64 caracteres; publicación rechazada por árbol vacío, por contenido sin PDA (`CURRICULUM_CONTENT_WITHOUT_PDA:CT-ORPHAN`), y por PDA en fase incorrecta (`PDA_GRADE_PHASE_MISMATCH`); tras publicar, INSERT/UPDATE/DELETE en hijos y UPDATE en la propia versión lanzan `CURRICULUM_VERSION_PUBLISHED`; grado que apunta a fase de otra versión falla por FK compuesta; `UNIQUE(curriculum_version_id, code)` bloquea códigos duplicados; `selectable_version_id` acepta una versión publicada del mismo currículo y rechaza borrador (`SELECTABLE_VERSION_NOT_PUBLISHED`) o versión de otro currículo (`SELECTABLE_VERSION_MISMATCHED_CURRICULUM`); administrador entra a `/admin/curricula` y `/admin/curriculum-versions` (200), cliente y revisor reciben 403.
- Suite completa post-cambios: `.\tools\php.ps1 vendor/phpunit/phpunit/phpunit --testdox` → `OK (43 tests, 164 assertions)` en 14.5 s. Sin regresiones sobre los 28 casos previos.
- Subfase 2B NO iniciada. No se creó `ImportCurriculumDraft`, `CurriculumImportService`, el comando `curriculum:import`, escuelas/grupos/perfil, NUEVA PLANEACIÓN, sugerencias, solicitudes, uploads ni ninguna migración adicional; los checks restantes de Fase 2 permanecen en `[ ]`.

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

