# User-defined Template Contract

## Principio

El formato institucional del usuario es soberano.

Planeaciones **no** define una plantilla universal, no obliga a modificar el DOCX y no incorpora reglas de código para escuelas, metodologías, días, grados o formatos concretos. El archivo original se conserva intacto y el sistema mantiene un contrato sidecar que describe cómo trabajar con sus zonas rellenables.

## Flujo

1. El usuario sube su DOCX real, vacío o ya llenado.
2. El inspector identifica zonas, etiquetas, marcadores y ejemplos existentes.
3. Planeaciones propone relaciones conocidas cuando tiene suficiente confianza.
4. El usuario confirma cada zona como una de estas opciones:
   - dato conocido del sistema;
   - dato curricular;
   - campo propio del formato generado por IA;
   - manual / no automático.
5. Para un campo propio, el usuario puede indicar el tipo y una instrucción semántica.
6. Se guarda el mapping/contrato fuera del DOCX.
7. Antes de activar el formato se genera una muestra sobre una **copia del Word original**.
8. Solo una muestra aprobada permite publicar la versión del formato.
9. En generación, el contrato confirmado viaja como `template_contract`; la IA recibe únicamente los requisitos de ese formato.
10. En render, el contenido vuelve a las zonas confirmadas del mismo DOCX original.

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
- reglas de uso.

El contrato es deliberadamente independiente de conceptos como “semana”, “sesión”, “ABP”, “multigrado” o “cinco días”. Si esos conceptos existen en un formato, aparecen como campos/zonas de **ese documento**, no como lógica global del producto.

## Campos propios

Los campos no cubiertos por el núcleo canónico se guardan bajo `custom.*`. Las claves se derivan de la etiqueta + identidad de la zona, de modo que dos zonas con la misma etiqueta pueden coexistir sin colisionar.

Tipos admitidos inicialmente:

- `text`
- `long_text`
- `date`
- `list`
- `table`
- `repeating_block`

`FormatAwareGenerationSchema` extiende el JSON Schema únicamente para los campos propios requeridos por el formato resuelto para esa solicitud.

## Evolución

El contrato puede enriquecerse después con edición visual de zonas no detectadas, grupos y reglas avanzadas de repetición/clonado OOXML. Esas capacidades deben ampliar el contrato genérico; nunca introducir ramas de código del tipo “si el formato es semanal”, “si es KPN” o “si es multigrado”.
