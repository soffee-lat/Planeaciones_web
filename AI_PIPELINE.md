# Pipeline IA vigente

Actualizado: 2026-09-15.

Este documento describe el pipeline IA actual. La regla principal es que **la generación pedagógica es canónica e independiente del formato de exportación**.

## Fuente de verdad

El proveedor IA nunca es autoridad curricular.

La entrada de generación se construye desde un `RequestInputVersion` inmutable que contiene el contexto docente y el currículo confirmado. La salida del proveedor usa `GeneratedPlanDraftV1`, que contiene diseño pedagógico y referencias curriculares por código. `CanonicalPlanAssembler` valida esas referencias y reconstruye `CanonicalPlanV1` usando los textos curriculares oficiales del snapshot.

Por tanto:

- la IA no crea ni reescribe contenidos, PDA, campos, ejes, fase o grado oficiales;
- la IA no recibe un DOCX institucional como contrato pedagógico;
- `format_version_id`, `template_contract` y campos derivados de una plantilla no forman parte del input de generación;
- cambiar el formato de salida no vuelve a ejecutar IA ni incrementa `input_revision`.

## Contratos vigentes

- `GeneratedPlanDraftV1`: salida generable del proveedor.
- `CanonicalPlanV1`: representación interna de autoridad para una versión de planeación.
- `AuditResultV1`: resultado estructurado de auditoría.
- `CorrectionResultV1`: corrección estructurada y acotada.

Los schemas viven en `resources/schemas/ai/`. Un cambio incompatible requiere una nueva versión de contrato.

## Flujo

```text
RequestInputVersion
    ↓
DispatchPlanningGeneration
    ↓
AiExecution: generation
    ↓
GeneratedPlanDraftV1
    ↓
CanonicalPlanAssembler
    ↓
DocumentVersion / CanonicalPlanV1
    ↓
AiExecution: audit
    ↓
AuditResultV1
    ├─ pasa → aprobación IA o revisión humana
    └─ falla → corrección interna acotada
                  ↓
             nueva DocumentVersion
                  ↓
             nueva auditoría
```

Una `DocumentVersion` nueva invalida cualquier aprobación perteneciente exclusivamente a su versión padre. Nunca se hereda una aprobación a contenido corregido.

## Generación

Antes de iniciar se valida estado, snapshot vigente, autorización comercial, reserva de planeación y contrato/prompt publicado compatible.

Al iniciar la primera generación se consume la reserva `planning` una sola vez. Los reintentos técnicos no consumen otra unidad.

La salida generada contiene únicamente diseño pedagógico permitido por el contrato: propósito, estructura de sesiones, actividades, evaluación, materiales, apoyos, recursos y referencias a códigos curriculares confirmados.

## Auditoría

La auditoría trabaja sobre una `DocumentVersion` exacta y validada. Comprueba coherencia pedagógica, cobertura curricular, estructura y restricciones del contrato.

`passed=true` exige ausencia de hallazgos. `passed=false` exige hallazgos estructurados.

Si pasa, se crea `Approval(kind=ai)` para esa versión y la solicitud pasa a `APROBADA` si no exige revisión humana, o a `REVISION_HUMANA` si el derecho congelado la exige.

## Correcciones internas

Las correcciones internas de calidad son distintas de las correcciones solicitadas por el cliente.

Solo pueden modificar raíces pedagógicas explícitamente mutables: título/proyecto, diseño pedagógico, sesiones, evaluación, recursos y adecuaciones/apoyos.

No pueden modificar `source`, contexto congelado, `curricular_alignment`, grado, fase, contenidos, PDA, campos ni ejes confirmados.

Una corrección crea una `DocumentVersion` hija y vuelve obligatoriamente a auditoría. Los ciclos internos no consumen `client_correction`.

## Revisión humana

Cuando el plan requiere revisión humana, el revisor trabaja sobre una `DocumentVersion` exacta, con checklist versionado y asignación vigente.

Una solicitud de cambios humanos genera una corrección sobre secciones permitidas, produce una nueva versión y regresa a auditoría antes de volver a revisión humana. La aprobación humana pertenece exclusivamente a la versión revisada.

## Modo manual

`AI_MODE=manual` es el flujo operativo consolidado.

El sistema materializa paquetes privados versionados para generación, auditoría y corrección. Un operador procesa el paquete externamente e importa el resultado. La importación pasa por los mismos schemas, validadores, hashes e invariantes que deberá respetar cualquier proveedor API.

## Modo API

`AI_MODE=api` requiere un adapter real configurado y validado. No existe fallback silencioso a fake o datos ficticios.

La rama `phase-7a-openai-provider` contiene trabajo del borde HTTP, pero no debe considerarse productiva hasta integrarse y probarse sobre esta arquitectura canónica.

Un adapter API debe respetar contratos estructurados, idempotencia, trazabilidad de proveedor/modelo, costos/uso, timeouts ambiguos, minimización de datos y las mismas validaciones server-side del modo manual.

## Idempotencia y procesamiento asíncrono

Las etapas usan `AiExecution`, `operation_key`, outbox, locks y leases.

- doble clic o job repetido reutiliza la operación existente;
- no se mantiene una transacción DB abierta durante una llamada externa;
- una respuesta vieja no puede promoverse sobre otra `input_revision` o `DocumentVersion`;
- fallos técnicos abren `request_blocks` y permiten reintento;
- un fallo técnico no equivale a cancelación comercial;
- costos e intentos fallidos se conservan cuando el proveedor los cobra.

## Prompts

`PromptTemplate` conserva identidad estable y `PromptVersion` publicada es inmutable. Las categorías principales son `generation`, `audit`, `correction` y `document_analysis` cuando corresponda.

Los prompts no deben contener lógica de formato institucional para la generación canónica.

## Documentos y exportación

El renderer se ejecuta después de que una `DocumentVersion` queda aprobada.

```text
Approved Canonical Plan
    ↓
PlanningFormatResolver
    ├─ Standard v2
    └─ Institutional export adapter
    ↓
DOCX / PDF
```

La exportación no modifica el contenido pedagógico ni llama nuevamente a generación IA.

Ver `docs/STANDARD_EXPORT_V2.md` y `ARCHITECTURE.md`.

## Seguridad

- archivos y payloads privados fuera de `public`;
- no enviar nombres de alumnos ni identificadores innecesarios;
- secretos fuera de logs y paquetes;
- contenido del usuario tratado como dato no confiable;
- ningún proveedor recibe herramientas, red o credenciales por medio del contenido de una solicitud.

## Pruebas arquitectónicas mínimas

La suite debe impedir regresiones en estas reglas:

1. generación independiente del formato;
2. currículo oficial reconstruido desde snapshot;
3. corrección incapaz de mutar bloque curricular;
4. aprobación ligada a versión exacta;
5. reintentos sin doble consumo;
6. respuestas obsoletas no promovidas;
7. Standard v2 disponible sin formato institucional;
8. adapter API sujeto a los mismos contratos del modo manual.
