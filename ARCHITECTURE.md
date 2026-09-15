# Arquitectura vigente

Actualizado: 2026-09-15.

Este documento describe la arquitectura actual de Planeaciones_web. Las descripciones históricas de fases cerradas quedan en Git y en los documentos de cierre; no deben competir con esta especificación.

## Plataforma

Monolito modular:

- Laravel 13.
- PHP 8.4.
- Filament 5.
- PostgreSQL como única base de datos.
- queues y outbox para trabajo asíncrono.
- almacenamiento privado para insumos y entregables.

Tres paneles aislados:

- `/app`: docente cliente.
- `/review`: docente revisor.
- `/admin`: administración.

No hay microservicios, bases por docente ni lógica de negocio repartida entre controladores. La UI invoca acciones/servicios; las acciones autorizan, validan y transaccionan; PostgreSQL protege invariantes críticas.

## Decisión arquitectónica central

La generación pedagógica es independiente del formato de exportación.

```text
Contexto docente
    +
Solicitud
    +
Currículo confirmado
        ↓
RequestInputVersion inmutable
        ↓
Autorización comercial
        ↓
Generación pedagógica estructurada
        ↓
CanonicalPlan
        ↓
Auditoría / corrección
        ↓
Revisión humana si aplica
        ↓
Approved Canonical Plan
        ↓
PlanningFormatResolver
        ↓
Standard v2 o adaptador institucional
        ↓
DOCX / PDF
        ↓
Delivery
```

El formato no forma parte del input congelado para IA. Un cambio de formato no modifica el plan canónico, no incrementa `input_revision` y no consume otra generación.

## Contextos de dominio

| Contexto | Responsabilidad |
|---|---|
| Identidad | usuarios, roles, verificación, onboarding, aislamiento |
| Perfil docente | escuelas, grupos y datos pedagógicos reutilizables |
| Currículo | catálogo versionado, publicación, elegibilidad y snapshots |
| Comercial | planes, suscripciones, periodos, reservas, consumo y pagos |
| Planeaciones | solicitudes, revisiones de input, estados y versiones |
| IA | contratos, prompts, ejecuciones, auditoría y correcciones |
| Revisión | asignación humana, checklist, decisiones y compensación |
| Documentos | contenido canónico, render, formatos y archivos privados |
| Entrega | publicaciones, descargas y retención |
| Operación | outbox, notificaciones, bloqueos, auditoría y métricas |

## Fuente pedagógica de verdad

Una planeación enviada tiene dos fuentes inmutables:

1. `RequestInputVersion`: contexto y currículo confirmados.
2. `DocumentVersion`: versión canónica generada/corregida.

El catálogo activo del momento no sustituye el snapshot de una solicitud ya enviada. Cambios editoriales posteriores crean otra `CurriculumVersion`.

La IA puede diseñar actividades, secuencias, evaluación, recursos y adecuaciones dentro de su contrato. No puede reescribir contenidos, PDA, fase, grado, campos ni ejes oficiales.

## Currículo

`CurriculumVersion` publicada y sus descendientes son inmutables. La selección para nuevas solicitudes exige además elegibilidad de producción mediante `ProductionCurriculumPolicy`.

Una versión oficial nueva permanece en borrador hasta superar:

- integridad estructural;
- correspondencia fase/grado/contenido/PDA;
- procedencia y metadatos requeridos;
- ausencia de marcadores editoriales explícitos;
- checksum/publicación válida;
- validación editorial antes de activarla como seleccionable.

La propuesta curricular ayuda al docente a encontrar conexiones, pero la confirmación final es explícita.

## Comercial

El sistema reserva derechos antes de iniciar el pipeline.

- `planning`: unidades de planeación.
- `human_review`: unidades de revisión cuando el plan lo exige.
- `client_correction`: rondas de corrección posteriores a entrega.

La reserva y el consumo son idempotentes. Reintentos técnicos, auditorías repetidas por fallos internos y correcciones internas de calidad no generan un segundo consumo comercial.

## IA y calidad

El pipeline trabaja sobre contratos versionados:

- `GeneratedPlanDraftV1` para generación.
- `CanonicalPlanV1` como autoridad interna.
- `AuditResultV1` para auditoría.
- `CorrectionResultV1` para correcciones acotadas.

`CanonicalPlanAssembler` reconstruye la versión interna usando el snapshot, por lo que el proveedor IA nunca se convierte en autoridad curricular.

El modo manual sigue siendo operativo. Un proveedor HTTP real debe usar los mismos contratos, idempotencia, costos y validadores; su existencia en una rama separada no lo convierte automáticamente en parte del flujo productivo.

## Revisión humana

Cuando el derecho congelado exige revisión:

- se asigna un revisor elegible por grado/version/capacidad;
- se congela checklist y versión documental;
- aprobar exige criterios obligatorios completos;
- pedir cambios crea una corrección sobre una versión exacta;
- la versión corregida vuelve a auditoría;
- rechazar/escalar abre atención administrativa sin fabricar una aprobación.

Las aprobaciones pertenecen a una `DocumentVersion` exacta y no se heredan a sus hijas.

## Documentos y formatos

### Standard v2

Es la salida predeterminada y garantizada. Presenta el plan canónico con estructura conocida y renderer controlado.

### Formatos institucionales

Son adaptadores opcionales de exportación. Pueden conservar archivo fuente, mapping, estructuras repetibles y reglas de render, pero operan después de la aprobación pedagógica.

No deben:

- modificar el contenido canónico;
- pedir a la IA que genere según la estructura arbitraria del DOCX;
- imponer campos pedagógicos nuevos al pipeline;
- provocar otra generación al cambiar de formato.

El experimento adaptativo previo se conserva solo como antecedente técnico.

## Estados y procesamiento

`PlanningRequestStatus` gobierna el flujo. Las transiciones se realizan mediante acciones específicas y se registran en `request_state_events`.

Errores técnicos abren `request_blocks` y permiten reintento; no se modelan como cancelaciones falsas. Outbox y claves de operación evitan duplicados.

Ver `WORKFLOWS.md` para la matriz completa.

## Seguridad y privacidad

- aislamiento por propietario y Policies;
- archivos fuera de `public`;
- descargas autenticadas;
- MIME real, hash y estado de escaneo;
- payload IA minimizado;
- no solicitar nombres de alumnos;
- historial inmutable de versiones, aprobaciones, entregas y descargas;
- secretos fuera de logs y documentos generados.

## Prioridad de diseño

Antes de ampliar funciones mayores:

1. cerrar coherencia documental/código;
2. validar currículo oficial Fases 3–5;
3. mantener Standard v2 estable;
4. validar el flujo con docentes reales;
5. integrar proveedor IA real sobre el contrato canónico.

No convertir el diseñador institucional en un clon de Word mientras estas prioridades sigan abiertas.
