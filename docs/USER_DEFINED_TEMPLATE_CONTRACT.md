# User-defined Template Contract

## Principio

El formato institucional del usuario es soberano.

Planeaciones **no** define una plantilla universal, no obliga a modificar el DOCX y no incorpora reglas de código para escuelas, metodologías, días, grados o formatos concretos. El archivo original se conserva intacto y el sistema mantiene un contrato sidecar que describe cómo trabajar con sus zonas rellenables y estructuras repetibles.

## Flujo

1. El usuario sube su DOCX real, vacío o ya llenado.
2. El inspector identifica zonas, etiquetas, marcadores, ejemplos existentes, filas y tablas estructurales.
3. Planeaciones propone relaciones conocidas cuando tiene suficiente confianza.
4. El usuario confirma cada zona como una de estas opciones:
   - dato conocido del sistema;
   - dato curricular;
   - campo propio del formato generado por IA;
   - manual / no automático.
5. Para un campo propio, el usuario puede indicar el tipo y una instrucción semántica.
6. El usuario puede declarar una fila repetible o un bloque repetible y configurar cada celda como manual, dato conocido o valor generado para cada elemento.
7. Se guarda el mapping/contrato fuera del DOCX.
8. Antes de activar el formato se genera una muestra sobre una **copia del Word original**.
9. Solo una muestra aprobada permite publicar la versión del formato.
10. En generación, el contrato confirmado viaja como `template_contract`; para mappings v3 se activa `adaptive_template_generation_v1` y la IA recibe únicamente el núcleo pedagógico mínimo y los campos que exige ese formato.
11. En render, el contenido vuelve a las zonas confirmadas del mismo DOCX original; las estructuras repetibles clonan el OOXML fuente en lugar de reconstruir el diseño.

## Reglas no negociables

- Un formato nuevo no debe requerir cambiar código.
- El sistema no debe asumir que un documento se parece a otro previamente visto.
- Etiquetas repetidas son zonas distintas mientras tengan localizadores distintos.
- Las sugerencias automáticas nunca son autoridad; el usuario puede corregirlas.
- Una planeación llena es un ejemplo semántico, no una fuente para copiar contenido.
- Firmas, sellos, datos personales y zonas manuales no se inventan.
- Datos curriculares deben salir del catálogo/selección curricular, no de una invención de IA.
- Datos administrativos existentes en el sistema deben resolverse desde el sistema cuando tengan mapping conocido.
- Logos, estilos, tablas, celdas combinadas, encabezados, pies y demás OOXML se preservan porque el renderer parte del archivo original.
- El número de elementos de una estructura repetible lo determina el contenido generado para ese contrato; no existe un número global de días, sesiones, grados o bloques.

## Contrato en tiempo de generación

`PlanningFormatGenerationContext` expone `template_contract` con:

- `authority: user_docx`;
- huella del archivo fuente;
- modo `blank_template` o `filled_example`;
- lista ordenada de campos y su binding al documento;
- path de datos;
- etiqueta;
- tipo;
- fuente (`ai`, `curriculum`, `system`);
- obligatoriedad;
- instrucción;
- ejemplo semántico cuando aplica;
- zonas ignoradas;
- estructuras repetibles y sus bindings hijos;
- reglas de uso.

Para mappings schema v3, el contexto agrega:

- `generation_contract: adaptive_template_generation_v1`;
- `ai_standard_fields`, únicamente para paths estándar cuya fuente sea IA;
- campos `custom.*` estructurales aunque no tengan un anchor simple;
- definición de `item_fields` para tablas y bloques repetibles.

El contrato es deliberadamente independiente de conceptos como “semana”, “sesión”, “ABP”, “multigrado” o “cinco días”. Si esos conceptos existen en un formato, aparecen como campos/zonas de **ese documento**, no como lógica global del producto.

## Contrato adaptativo de salida

Cuando el formato usa mapping v3, `FormatAwareGenerationSchema` construye un schema dinámico en lugar de heredar la forma fija de `GeneratedPlanDraftV1`.

La salida `adaptive_template_generation_v1` contiene:

- `core`: título, propósito, objetivos de aprendizaje, estrategia de evaluación, consideraciones de adaptación y cobertura de PDA;
- `fields`: solo los campos estándar configurados explícitamente con fuente IA;
- `custom`: solo los campos propios requeridos por ese formato, incluidos arreglos de estructuras repetibles.

No existe una propiedad global obligatoria `sessions`. Si el DOCX necesita sesiones, jornadas, momentos, días o cualquier agrupación similar, esa necesidad debe provenir de su propio contrato.

El resultado validado se ensambla como `canonical_adaptive_plan_v1`. Los hechos de sistema, contexto y currículo se reconstruyen desde el snapshot congelado del servidor; la IA no puede reemplazarlos.

## Campos propios

Los campos no cubiertos por el núcleo canónico se guardan bajo `custom.*`. Las claves se derivan de la etiqueta + identidad de la zona, de modo que dos zonas con la misma etiqueta pueden coexistir sin colisionar.

Tipos admitidos:

- `text`
- `long_text`
- `date`
- `list`
- `table`
- `repeating_block`

Para `table` y `repeating_block`, `item_fields` describe las columnas/celdas variables de cada elemento y el JSON Schema cierra los objetos con `additionalProperties: false` cuando existe una definición estructurada.

## Estructuras repetibles y OOXML

El análisis estructural identifica filas y tablas mediante localizadores del documento. El diseñador visual permite marcar:

- `repeat_row`: clona la fila `<w:tr>` original por cada elemento generado;
- `repeat_block`: actualmente puede clonar una fila o una tabla `<w:tbl>` completa.

Cada binding hijo puede obtener su valor de:

- `item`: campo del elemento actual generado por IA;
- `path`: dato conocido del sistema/currículo/canónico;
- manual: conserva la zona sin automatizar.

El renderer clona el OOXML original para preservar formato, bordes, celdas, tipografías y demás propiedades del documento. La implementación actual no modela todavía rangos arbitrarios de párrafos como un bloque repetible; esa ampliación futura deberá conservar el mismo contrato genérico.

## Auditoría y corrección adaptativa

Las versiones `canonical_adaptive_plan_v1` pueden entrar al mismo ciclo de auditoría y corrección interna.

Los roots mutables adaptativos son:

- `planning` (solo `title` y `project_name`);
- `pedagogical_design`;
- `template_fields`;
- `custom`.

`source`, `context` y `curricular_alignment` permanecen inmutables. Una corrección de `custom.*` o `template_fields.*` se valida contra el contrato del formato vigente para esa solicitud y genera una nueva `DocumentVersion` hija antes de reauditar.

## Compatibilidad

Mappings v1/v2 mantienen el contrato histórico y la extensión `custom.*` compatible con `GeneratedPlanDraftV1`.

Mappings v3 activan el contrato adaptativo. `InstitutionalDynamicFieldResolver` permanece únicamente como capa de compatibilidad; la resolución genérica corresponde a `GenericInstitutionalFieldResolver`.

## Evolución

La evolución del contrato puede incorporar selección visual de rangos estructurales más amplios, migración de mappings cuando cambie la huella del DOCX, reglas de ajuste de longitud, crecimiento de filas y estrategias adicionales de repetición OOXML. Estas capacidades deben ampliar el contrato genérico; nunca introducir ramas de código del tipo “si el formato es semanal”, “si es KPN” o “si es multigrado”.
