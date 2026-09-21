# Standard Export v2

## Objetivo

La generación pedagógica de una planeación no depende del formato DOCX que se utilizará para entregarla.

La arquitectura separa dos responsabilidades:

1. **Generar y auditar contenido pedagógico canónico.**
2. **Exportar ese contenido aprobado a una presentación concreta.**

Esto evita que un formato institucional incompleto, antiguo o previamente llenado contamine la planeación nueva.

## Flujo principal

```text
Nueva planeación
  -> datos esenciales
  -> conexiones curriculares
  -> confirmar y reservar
  -> generación IA canónica
  -> auditoría / corrección
  -> planeación aprobada
  -> elegir formato de exportación
       -> Formato estándar (predeterminado)
       -> Formato institucional (opcional/avanzado)
  -> DOCX / PDF
```

## Invariantes

### Generación

- `PlanningRequest.format_version_id` no forma parte del input pedagógico congelado.
- `RequestInputVersion.snapshot.request` no contiene `format_version_id`.
- `AiExecution` de etapa `generation` no está ligado a un formato de salida.
- El paquete manual de generación usa el schema base del prompt (`generated_plan_draft_v1`).
- El paquete no contiene `template_contract` institucional ni campos custom derivados de un DOCX.
- Cambiar el formato de exportación no debe incrementar `input_revision` ni consumir una nueva generación IA.

### Exportación

- Si no hay una selección explícita, `PlanningFormatResolver` usa el formato estándar global.
- La selección de formato se realiza únicamente cuando la planeación está aprobada.
- El formato estándar no depende de archivos DOCX aportados por el usuario.
- Los formatos institucionales se conservan como adaptadores de exportación opcionales.

## Standard v2

El renderer estándar organiza el canonical plan para consulta docente, no como un volcado de texto.

Incluye:

- datos generales en tabla;
- propósito y enfoque;
- alineación curricular;
- vista semanal;
- ficha por sesión;
- bloques de Inicio, Desarrollo y Cierre;
- acciones del docente y del alumnado;
- materiales, evidencias y verificaciones;
- evaluación formativa;
- apoyos y accesibilidad;
- instrumentos de evaluación utilizables en forma de tabla/lista de cotejo;
- recursos y notas.

El identificador técnico `standard-v1.0.0` se conserva temporalmente por compatibilidad con historiales y pruebas existentes, aunque la presentación corresponde a Standard v2.

## Formatos institucionales y ejemplos de análisis

Una planeación anterior llena no debe considerarse una plantilla limpia. El análisis institucional puede utilizarse para reconocer estructura y estilo, pero cualquier adaptación que vuelva a usar los bytes originales debe tratarse como una capacidad avanzada hasta disponer de una sanitización completa de contenido histórico.

Los formatos institucionales globales cargados como ejemplos de análisis **no son formatos de salida** y no deben aparecer en las opciones del docente. Sólo pueden exportarse formatos institucionales que pertenezcan explícitamente a la cuenta del usuario.

El camino garantizado y predeterminado del producto es el Standard v2 (formato general).

## Fuera de alcance de esta primera entrega

La primera entrega implementa la selección de formato para la **primera exportación** de una planeación aprobada. La exportación posterior de una misma entrega a múltiples formatos, sin cambiar de estado, requiere ampliar el modelo de artefactos para identificar cada archivo por `format_version_id`/render run y se implementará como una fase separada.
