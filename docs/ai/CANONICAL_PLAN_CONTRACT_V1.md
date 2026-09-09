# Contrato canónico de planeación — v1

## Decisión de arquitectura

La IA **no es la autoridad curricular**. El catálogo publicado y el `request_input_version` confirmado por la persona docente son la fuente de verdad.

Se usan dos contratos:

1. `GeneratedPlanDraftV1`: salida estructurada del proveedor IA. Solo genera contenido pedagógico y referencia códigos curriculares ya presentes en el snapshot.
2. `CanonicalPlanV1`: objeto ensamblado por la aplicación a partir de `InputSnapshot + GeneratedPlanDraftV1 validado`. Es el único contrato consumido por auditoría, corrección, revisión humana y renderer.

La aplicación, no la IA, copia al canonical los textos oficiales de campos, contenidos, PDA y ejes.

## Objetivos

- Un único JSON semántico para toda la cadena.
- Independencia del formato Word/PDF de cada escuela.
- Trazabilidad completa al snapshot pedagógico y comercial.
- Evitar que el modelo invente o reescriba currículo oficial.
- Permitir auditoría automática por paths JSON.
- Permitir correcciones sin tocar la alineación curricular congelada.

## Estructura de CanonicalPlanV1

```text
schema_version
source
planning
context
curricular_alignment      # bloque bloqueado, ensamblado server-side
pedagogical_design        # generado
sessions                  # generado + referencias curriculares validadas
assessment_plan           # generado
resources                 # generado
adaptation_notes          # generado
```

## source

Trazabilidad no necesariamente visible al docente:

- `planning_request_id`
- `input_version_id`
- `input_revision`
- `selection_revision`
- `curriculum_checksum`
- `commercial_snapshot_version`
- `generation_contract_version`

## planning

- `title`
- `project_name` nullable
- `topic` nullable
- `planning_type`: `weekly | project | didactic_sequence | custom`
- `starts_on`
- `ends_on`
- `session_minutes`
- `session_count`

**Regla:** `planning_days` y `planning_units` son datos comerciales y no equivalen al número de clases.

## context

Copia únicamente información disponible en el snapshot:

- escuela y grupo;
- cantidad de estudiantes si existe;
- nivel general;
- características del grupo;
- materiales disponibles;
- eventos o restricciones;
- observaciones docentes.

No se inventan diagnósticos, necesidades especiales, recursos ni eventos.

## curricular_alignment — server-side y bloqueado

Incluye:

- currículo/version/checksum;
- fase;
- grado;
- campos formativos;
- contenidos;
- PDA;
- ejes articuladores;
- `coverage` PDA → sesiones.

Los `full_text`, nombres y códigos provienen del snapshot confirmado. La respuesta de IA **no puede modificarlos**.

Cada sesión puede referenciar únicamente códigos presentes en este bloque.

## pedagogical_design

- `purpose`
- `problem_or_interest` nullable
- `scenario` nullable
- `learning_goals[]`
- `methodology { name, rationale, phases[] }`
- `transversal_connections[]`

Si la solicitud no fija una metodología y no hay base suficiente para inferir una específica, usar `Secuencia didáctica` en vez de atribuir una metodología no solicitada.

## sessions

Cada sesión usa ID estable `S01`, `S02`, etc.

Campos:

- `id`
- `sequence`
- `date` nullable
- `title`
- `estimated_minutes`
- `methodology_phase` nullable
- `specific_goal`
- `field_codes[]`
- `content_codes[]`
- `pda_codes[]`
- `axis_codes[]`
- `moments[]`
- `formative_assessment`
- `differentiation`
- `homework_or_extension` nullable
- `teacher_notes` nullable

### Momentos MVP

Cada sesión contiene exactamente:

- `inicio`
- `desarrollo`
- `cierre`

Cada momento tiene `minutes` y `activities[]`.

Cada actividad contiene:

- `instruction`
- `teacher_action`
- `student_action`
- `organization`: `whole_group | individual | pairs | small_groups | mixed`
- `materials[]`
- `expected_evidence[]`
- `assessment_checks[]`

**Regla:** la suma de minutos de los tres momentos debe ser exactamente `estimated_minutes`.

## assessment_plan

La evaluación se modela como parte del proceso, no como un bloque decorativo.

- `approach = formative`
- `diagnostic` nullable
- `ongoing`
- `closure`
- `instruments[]`

Instrumentos MVP:

- checklist;
- rubric;
- rating_scale;
- observation_record;
- student_product;
- exit_questions;
- other.

No se fuerza una calificación numérica.

## resources

- `physical_materials[]`
- `digital_resources[]`
- `provided_references[]`

No inventar URLs ni afirmar que se consultó un libro/material no identificado.
`provided_references` del canonical se reconstruye server-side desde el snapshot confirmado; un valor homónimo incluido por el proveedor no puede sustituir ni ampliar las referencias aportadas por la persona docente. Si el docente confirmó un nombre de proyecto, ese valor también prevalece sobre cualquier propuesta del draft.

## adaptation_notes

- `based_on_group_profile[]`
- `assumptions[]`
- `missing_information[]`

Las adecuaciones solo pueden derivarse de información declarada. Nunca inferir diagnósticos médicos o discapacidades.

## Reglas de dominio adicionales al JSON Schema

### Currículo

- todos los códigos referenciados existen en el snapshot;
- ningún código externo aparece;
- cada PDA pertenece al contenido referenciado;
- cada PDA corresponde al grado confirmado;
- cada PDA seleccionado aparece al menos en una sesión;
- `curricular_alignment` debe coincidir con el snapshot, no con la respuesta del modelo.

### Sesiones

- IDs únicos y secuenciales;
- `estimated_minutes > 0`;
- inicio + desarrollo + cierre = duración total;
- actividades no vacías;
- materiales compatibles con el input o claramente identificados como material a preparar;
- `date` solo se completa cuando el input determina una fecha suficiente. No inventar calendario escolar ni festivos.

### Evaluación

- cada sesión declara evidencias y criterios;
- cada `instrument_id` existe;
- criterios relacionados con metas/PDA;
- retroalimentación formativa explícita.

## GeneratedPlanDraftV1

La IA genera únicamente:

```text
title
project_name
purpose
problem_or_interest
scenario
learning_goals
methodology
transversal_connections
sessions
assessment_plan
resources
adaptation_notes
```

En sesiones puede enviar referencias por **código**, nunca textos curriculares oficiales.

El servidor valida las referencias y después ensambla `CanonicalPlanV1`.

## Auditoría estructurada (AuditResultV1)

`AuditService`/modo manual devuelve hallazgos estructurados con:

- `code`
- `severity`
- `json_path`
- `explanation`
- `expected_correction`

Categorías mínimas:

- `SCHEMA`
- `CURRICULUM_REFERENCE`
- `CURRICULUM_COVERAGE`
- `PEDAGOGICAL_ALIGNMENT`
- `TIME_CONSISTENCY`
- `ASSESSMENT_ALIGNMENT`
- `AGE_APPROPRIATENESS`
- `GROUP_CONTEXT`
- `MATERIAL_FEASIBILITY`
- `UNSUPPORTED_ASSUMPTION`
- `SAFETY_OR_INCLUSION`
- `LANGUAGE_QUALITY`

## Corrección estructurada (CorrectionResultV1)

`CorrectionResultV1` referencia `source_version_id` y contiene un patch por secciones. El scope se deriva del AuditResult exacto y solo admite:

```text
/planning/title
/planning/project_name
/pedagogical_design
/sessions
/assessment_plan
/resources
/adaptation_notes
```

Debe tener prohibido modificar:

```text
/source
/context
/curricular_alignment
```

Las fechas, IDs, rango, tipo de planeación, minutos y demás datos server-side de `/planning` tampoco son editables por IA. Tras aplicar el patch, el servidor vuelve a construir `GeneratedPlanDraftV1` y `CanonicalPlanV1`; por eso una corrección no puede introducir códigos curriculares ajenos ni saltarse cobertura PDA. Cada versión corregida se reaudita antes de cualquier aprobación.

## Renderer futuro

El renderer nunca pide a la IA “hacer un Word”.

```text
CanonicalPlanV1 + FormatVersion -> DocumentVersion
```

Así la misma planeación puede renderizarse en un formato estándar, institucional o personalizado sin regenerar contenido pedagógico.
