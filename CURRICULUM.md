# Catálogo curricular vigente

Actualizado: 2026-09-15.

Este documento describe el módulo curricular implementado y la línea activa de trabajo con currículo oficial de primaria para Fases 3, 4 y 5.

## Principio

El currículo es una fuente de verdad versionada e independiente de la IA y de los formatos de exportación.

Una solicitud enviada conserva un snapshot textual completo del currículo confirmado por el docente. El pipeline genera y audita desde ese snapshot; nunca sustituye silenciosamente la versión curricular por otra más reciente.

## Entidades

| Entidad | Responsabilidad |
|---|---|
| `Curriculum` | identidad estable del currículo |
| `CurriculumVersion` | versión editorial/publicable |
| `EducationalPhase` | fase educativa |
| `Grade` | grado dentro de una fase |
| `FormativeField` | campo formativo |
| `CurricularContent` | contenido oficial |
| `Pda` | proceso de desarrollo de aprendizaje asociado a contenido y grado |
| `ArticulatingAxis` | eje articulador transversal |

Todos los elementos pertenecen a una `CurriculumVersion`. Las FK compuestas y restricciones PostgreSQL evitan mezclar elementos entre versiones.

## Inmutabilidad

Una `CurriculumVersion` publicada y su árbol son inmutables.

No se permite:

- editar textos de una versión publicada;
- agregar o eliminar descendientes;
- mover elementos a otra versión;
- corregir una versión publicada en sitio.

Una corrección editorial crea una nueva versión borrador. Las solicitudes históricas conservan la versión y snapshot originales.

## Publicación y elegibilidad de producción

`published_at` no basta para que una versión pueda usarse en nuevas solicitudes.

La elegibilidad productiva se determina además mediante `ProductionCurriculumPolicy` y validadores específicos.

La versión debe cumplir, entre otras, estas condiciones:

- metadatos de publicación completos;
- checksum válido;
- procedencia identificable;
- árbol estructuralmente consistente;
- grado y fase coherentes;
- contenidos con PDA aplicables;
- ausencia de marcadores editoriales explícitos;
- identidad curricular compatible con producción.

Una versión oficial nueva debe permanecer en borrador hasta superar validación técnica y editorial.

## Marcadores editoriales

Los datos DEMO y placeholders deben bloquear producción.

Ejemplos de marcadores explícitos:

- palabra aislada `demo`;
- `sin validez curricular`;
- `contenido de ejemplo`;
- `pda de ejemplo`;
- `__PENDING_EDITORIAL__`.

La detección no debe bloquear vocabulario pedagógico legítimo. Por ejemplo, `democracia` no contiene la palabra aislada `demo` y no debe rechazarse.

## Currículo oficial Fases 3–5

La línea activa consolida currículo oficial de primaria:

- Fase 3: 1.º y 2.º.
- Fase 4: 3.º y 4.º.
- Fase 5: 5.º y 6.º.

El proceso actual prioriza validar el catálogo ya construido antes de reimportar. Solo debe repetirse una importación cuando exista una razón técnica/editorial concreta y verificable.

La activación de una versión oficial debe realizarse mediante las acciones/comandos previstos, nunca cambiando `selectable_version_id` por SQL directo para saltarse la política de producción.

## Grupos

`groups` conserva la versión curricular y el grado utilizado para crear nuevas solicitudes.

Cambiar de versión curricular requiere remapeo explícito de grado. No se asume que dos versiones comparten IDs ni equivalencias por ordinal.

El formato de exportación no forma parte de la configuración curricular del grupo ni del snapshot pedagógico. Cualquier columna histórica de preferencia de formato se considera compatibilidad y no debe volver a acoplar currículo/generación con documentos.

## Selección curricular de una solicitud

Una solicitud puede seleccionar:

- contenidos;
- PDA compatibles con el grado;
- ejes articuladores;
- campos derivados de los contenidos seleccionados.

Las relaciones deben pertenecer a la misma versión curricular.

Antes de enviar:

1. el grado debe pertenecer a la versión;
2. todo PDA seleccionado debe corresponder al grado;
3. cada contenido seleccionado debe tener al menos un PDA seleccionado aplicable;
4. la selección debe estar confirmada explícitamente por el docente;
5. la versión debe ser elegible para nuevas solicitudes.

## Snapshot curricular

`RequestInputVersion` congela la información necesaria para reproducir la generación:

- identidad y versión curricular;
- procedencia/checksum relevantes;
- fase y grado;
- campos formativos seleccionados/derivados;
- contenidos completos;
- PDA completos;
- ejes seleccionados;
- vínculos contenido–PDA;
- confirmación y revisión de selección;
- contexto pedagógico y datos variables de la solicitud;
- cálculo comercial y manifest de insumos cuando corresponda.

No incluye el formato de exportación como input pedagógico.

El pipeline usa este snapshot, no el catálogo activo al momento de ejecutar un job posterior.

## Sugerencias curriculares

`CurriculumSuggestionService` es independiente de la UI y de la generación IA principal.

Su función es proponer conexiones válidas dentro del grado y versión seleccionados. La propuesta:

- no crea contenido curricular oficial;
- no confirma automáticamente una solicitud;
- no consume una unidad de planeación;
- no puede seleccionar elementos de otro grado/versión;
- se invalida cuando cambia el fingerprint del contexto relevante.

Sin coincidencia suficiente debe devolver falta de coincidencias o permitir búsqueda avanzada, no seleccionar contenido arbitrario.

## Importación administrativa

La importación estructurada existe para crear nuevas versiones borrador desde archivos previamente preparados.

Reglas:

- schema versionado;
- create-only;
- transacción todo-o-nada;
- `dry-run` sin escrituras;
- códigos/referencias validados;
- sin publicación automática;
- sin cambio automático de versión seleccionable;
- sin acreditar oficialidad únicamente por haber importado correctamente.

La importación técnica y la validación editorial son pasos distintos.

## Autoridad de la IA

La IA no puede aportar ni modificar textos oficiales.

`GeneratedPlanDraftV1` puede referenciar elementos curriculares por código y proponer diseño pedagógico. `CanonicalPlanAssembler` vuelve a insertar desde el snapshot los textos y relaciones curriculares de autoridad.

Si la salida IA referencia códigos desconocidos, omite cobertura requerida o intenta modificar el bloque curricular, se rechaza/corrige; nunca se acepta como una nueva verdad curricular.

## Fuera del alcance actual

- multigrado en una sola solicitud;
- equivalencias automáticas entre currículos/versiones;
- scraping de fuentes oficiales;
- sincronización externa automática;
- OCR universal de documentos curriculares;
- embeddings/recomendaciones aprendidas como autoridad curricular.

Estas capacidades solo deben agregarse después de validar el MVP con docentes y sin debilitar la inmutabilidad/snapshot actual.
