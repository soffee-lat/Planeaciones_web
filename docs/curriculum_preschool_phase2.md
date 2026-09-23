# Catálogo curricular de Preescolar — Fase 2

## Alcance

El catálogo canónico de Preescolar usa:

- Currículo: `MX-NEM-PRESCHOOL`
- Nivel educativo: `preschool`
- Fase: `F2`
- Grados internos: `P1`, `P2`, `P3`
- Campos: `LEN`, `SPC`, `ENS`, `DHC`

Los códigos son internos de Planeaciones Soffee y no pretenden ser identificadores oficiales de la SEP.

## Fuente editorial

Documento base:

**Programa de Estudio para la Educación Preescolar: Programa Sintético de la Fase 2**  
Secretaría de Educación Pública  
Primera edición, 2024

Archivo usado para la extracción editorial:

`Programa_Sintetico_Fase-2.pdf`

SHA-256 del archivo fuente:

`f981c30ea9619f5841f4729a6f697951e035eff78c1e1042d02d0d5d663c8702`

Las tablas curriculares se tomaron de las páginas impresas:

| Campo | Código | Páginas | Contenidos | PDA P1 | PDA P2 | PDA P3 | PDA total |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Lenguajes | LEN | 20-26 | 9 | 27 | 30 | 30 | 87 |
| Saberes y Pensamiento Científico | SPC | 32-41 | 9 | 36 | 44 | 51 | 131 |
| Ética, Naturaleza y Sociedades | ENS | 46-51 | 8 | 23 | 26 | 21 | 70 |
| De lo Humano y lo Comunitario | DHC | 56-62 | 8 | 26 | 30 | 29 | 85 |
| **Total** |  |  | **34** | **112** | **130** | **131** | **373** |

## Criterio de extracción

Cada renglón de la columna **Contenidos** se conserva como un `CurricularContent`.

Cada párrafo visualmente separado dentro de las columnas **1.º**, **2.º** y **3.º** se conserva como un PDA independiente del grado correspondiente.

Los renglones que continúan en la página siguiente se reconstruyen antes de crear el PDA. Los guiones introducidos únicamente por corte tipográfico de línea se eliminan; la redacción curricular no se resume ni se reescribe.

`title` y `full_text` de cada Contenido conservan el texto del cuadro curricular. Cada Contenido y PDA incluye `source_locator` con la página impresa donde aparece.

## Ejes articuladores

El Programa Sintético de Fase 2 relaciona Contenidos y PDA con los Ejes articuladores, pero este documento no presenta en estas tablas un catálogo independiente de los siete ejes. Para mantener coherencia con Primaria, `MX-NEM-PRESCHOOL` reutiliza el catálogo de Ejes articuladores ya establecido en Planeaciones Soffee:

1. Inclusión
2. Pensamiento crítico
3. Interculturalidad crítica
4. Igualdad de género
5. Vida saludable
6. Apropiación de las culturas a través de la lectura y la escritura
7. Artes y experiencias estéticas

## Validaciones de importación

El importador aplica reglas especiales a `MX-NEM-PRESCHOOL`:

- `educational_level` debe ser `preschool`.
- La única fase permitida es `F2`.
- Deben existir exactamente `P1`, `P2` y `P3`.
- Deben existir los cuatro Campos formativos.
- Deben existir los siete Ejes articuladores usados por la plataforma.
- Cada Contenido debe tener al menos un PDA para P1, P2 y P3.
- La importación crea únicamente un borrador; nunca publica ni modifica `selectable_version_id`.

## Flujo de publicación

1. Ejecutar `curriculum:import ... --dry-run`.
2. Revisar errores y conteos.
3. Importar el borrador.
4. Comparar muestras de Contenidos/PDA contra el PDF fuente.
5. Publicar la `CurriculumVersion` únicamente después de la revisión editorial.
6. Marcar la versión publicada como seleccionable.
7. Ejecutar una planeación E2E por cada grado de preescolar.
