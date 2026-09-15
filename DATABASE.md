# Modelo de datos vigente

Actualizado: 2026-09-15.

Planeaciones_web usa PostgreSQL como única base de datos. Este documento describe el modelo lógico actual; las migraciones y constraints del código son la autoridad técnica final.

## Convenciones

- tablas y columnas en `snake_case`;
- PK `bigint`;
- FK explícitas y, cuando aplica, compuestas para impedir mezcla de propietario o versión;
- instantes en UTC; fechas pedagógicas como `DATE`;
- dinero comercial en unidades menores enteras + moneda ISO;
- costos IA con precisión decimal y moneda registrada;
- JSONB solo para snapshots, manifests, contratos y estructuras validadas;
- historial crítico append-only o inmutable mediante triggers/constraints PostgreSQL.

## Identidad y perfil docente

Entidades principales:

- `users`, `roles`, `role_user`;
- `schools`;
- `groups`;
- `group_profiles`.

`groups` pertenece a un docente y referencia grado/versión curricular para nuevas solicitudes. El perfil del grupo conserva información pedagógica reutilizable y una revisión propia.

Una preferencia histórica de formato no debe utilizarse como input de generación ni como fuente de verdad del flujo pedagógico.

## Currículo

Tablas principales:

- `curricula`;
- `curriculum_versions`;
- `educational_phases`;
- `grades`;
- `formative_fields`;
- `curricular_contents`;
- `pdas`;
- `articulating_axes`.

Todo elemento pertenece a una `CurriculumVersion`. FK compuestas y triggers impiden mezclar versiones y modificar un árbol publicado.

`curricula.selectable_version_id` indica la versión ofrecida para nuevas solicitudes, pero la elegibilidad productiva también está gobernada por `ProductionCurriculumPolicy`.

## Solicitudes de planeación

Entidades principales:

- `planning_requests`;
- `request_input_versions`;
- relaciones de contenidos/PDA/ejes seleccionados;
- `curriculum_suggestions`;
- `request_state_events`;
- `request_blocks`.

`planning_requests` representa el proceso de negocio. `request_input_versions` representa cada snapshot inmutable de entrada.

El snapshot vigente congela contexto pedagógico, currículo confirmado, materiales/manifest y cálculo comercial. **El formato de exportación no forma parte del input pedagógico canónico.**

Columnas históricas como `planning_requests.format_version_id` pueden permanecer por compatibilidad/exportación, pero no deben condicionar generación, auditoría ni `input_revision`.

## Comercial

Entidades principales:

- `plans`, `plan_versions`;
- `subscriptions`, `subscription_periods`;
- `orders`, `payments`, `payment_events`, `refunds`;
- `usage_reservations`.

Los derechos se congelan por periodo/solicitud. Las reservas principales son:

- `planning`;
- `human_review`;
- `client_correction`.

Reserva y consumo son idempotentes. Los reintentos técnicos y correcciones internas de calidad no crean consumo comercial adicional.

## IA

Entidades principales:

- `prompt_templates`, `prompt_versions`;
- `ai_executions`;
- intentos/uso/costos relacionados;
- `outbox_events` para dispatch asíncrono.

Cada `AiExecution` congela etapa, operación, prompt/contrato, revisión de entrada y, cuando corresponde, versión documental fuente o resultante.

La generación canónica no depende de `format_version_id` ni de un `template_contract` institucional.

## Planeación canónica y versiones

Entidades principales:

- `documents`;
- `document_versions`;
- `approvals`.

`documents` es el contenedor lógico de una planeación. `document_versions` conserva versiones inmutables del contenido canónico.

Una corrección crea una versión hija mediante `parent_version_id`; nunca reescribe la versión anterior.

`approvals` pertenece a una `DocumentVersion` exacta y puede ser de tipo IA o humana. Una versión hija no hereda aprobaciones.

## Revisión humana

El dominio de revisión conserva:

- perfiles/autorizaciones de revisores;
- asignaciones;
- reviews;
- versiones de checklist y respuestas;
- compensación/honorarios cuando corresponde.

Una revisión referencia una versión documental exacta. Reasignaciones y correcciones conservan historial.

## Formatos y render

Entidades principales:

- `files`;
- `institutional_formats`;
- `format_versions`;
- `document_render_runs` y relaciones de archivos generados.

Standard v2 es la salida predeterminada de plataforma. Los formatos institucionales son adaptadores opcionales de exportación.

La relación con formatos ocurre después de la aprobación pedagógica. El renderer consume contenido canónico aprobado; no define lo que la IA debe generar.

## Entrega y retención

Entidades principales:

- `deliveries`;
- `delivery_files`;
- `delivery_downloads`.

Una entrega congela versión, render y archivos exactos. Las correcciones posteriores producen nuevas entregas sin borrar las anteriores.

Los bytes pueden purgarse conforme a retención, pero las filas históricas y relaciones críticas permanecen.

## Correcciones

`correction_requests` diferencia al menos correcciones internas/humanas y correcciones contractuales del cliente.

Una corrección válida:

- referencia versión fuente;
- congela alcance;
- produce una versión hija;
- obliga a nueva auditoría;
- nunca modifica silenciosamente currículo/contexto congelado.

Las correcciones del cliente usan `client_correction`; las internas del pipeline no.

## Integridad PostgreSQL

PostgreSQL protege invariantes que no deben depender solo de Eloquent:

- inmutabilidad curricular publicada;
- coherencia de versión curricular;
- snapshots y autorizaciones comerciales requeridos antes de estados productivos;
- transiciones válidas de reservas;
- inmutabilidad de `DocumentVersion`;
- aprobaciones ligadas a versión exacta;
- historial de entregas/descargas;
- constraints diferidos cuando una transacción necesita actualizar padre e hijos antes del commit.

No utilizar SQL directo para saltarse acciones/policies/triggers en operación normal.

## Fuente de verdad

Ante una discrepancia entre este documento y el esquema real:

1. tests de integridad de la rama activa;
2. migraciones/triggers actuales;
3. modelos/acciones actuales;
4. este documento;
5. documentación histórica.
