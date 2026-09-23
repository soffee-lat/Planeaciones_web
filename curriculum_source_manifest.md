# Mapa de fuentes curriculares — Preescolar y Primaria NEM

## Objetivo

Este documento prepara la carga de los catálogos curriculares de Preescolar y Primaria para la plataforma de planeaciones.
No sustituye la validación editorial ni constituye por sí mismo el catálogo importable definitivo.

Alcance del producto:
- Incluido: Preescolar, Fase 2.
- Incluido: Primaria, Fases 3, 4 y 5.
- Fuera de alcance: Educación Inicial / Fase 1.
- Fuera de alcance: Secundaria / Fase 6.

## Base normativa

- Plan de Estudio para la educación preescolar, primaria y secundaria — Acuerdo 14/08/22.
- Modificación al Plan de Estudio — Acuerdo 06/08/23.
- Programas Sintéticos de las Fases 2 a 6 — Acuerdo 08/08/23.

## Fuentes SEP para preescolar

### Fase 2 — 1.º, 2.º y 3.º de preescolar

Fuente:
https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/06/Programa_Sintetico_Fase-2.pdf

Primera edición: 2024.

El documento organiza Contenidos y Procesos de desarrollo de aprendizaje por 1.º, 2.º y 3.º dentro de la Fase 2. Las secciones tabulares de contenidos/PDA comienzan en las páginas impresas 20 (Lenguajes), 32 (Saberes y Pensamiento Científico), 46 (Ética, Naturaleza y Sociedades) y 56 (De lo Humano y lo Comunitario).

Convención interna propuesta:
- Currículo: MX-NEM-PRESCHOOL
- Fase: F2
- Grados: P1, P2, P3
- Contenidos: F2-{CAMPO}-C001...
- PDA: F2-{GRADO}-{CAMPO}-C001-P01...

La carga debe conservar la columna de grado exacta del Programa Sintético. No inferir un PDA de un grado a otro ni tratar la progresión como lineal o rígida.

Extracción editorial verificada para `curriculum_mx_nem_preschool_v1.json`:
- Fuente local: `Programa_Sintetico_Fase-2.pdf`
- SHA-256 fuente: `f981c30ea9619f5841f4729a6f697951e035eff78c1e1042d02d0d5d663c8702`
- 1 fase: F2
- 3 grados internos: P1, P2, P3
- 4 Campos formativos
- 34 Contenidos: LEN 9, SPC 9, ENS 8, DHC 8
- 373 PDA: P1 112, P2 130, P3 131
- 7 Ejes articuladores compartidos con el currículo NEM
- Páginas fuente de tablas: Lenguajes 20–26; Saberes y Pensamiento Científico 32–41; Ética, Naturaleza y Sociedades 46–51; De lo Humano y lo Comunitario 56–62
- SHA-256 del JSON generado: `532202229710635271fd05f156e49ac3fde4a0042abf4f59a964ab458d378333`

El JSON real se valida mediante `curriculum:import --dry-run` antes de crear el borrador. La importación nunca publica ni modifica `selectable_version_id`.

## Fuentes SEP para primaria

### Fase 3 — 1.º y 2.º de primaria

Fuente:
https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/09/Programa_Sintetico_Fase_3.pdf

Primera edición: 2024.

Rangos impresos útiles para extracción del catálogo:
- Lenguajes: Contenidos y PDA, pp. 24–36.
- Saberes y Pensamiento Científico: Contenidos y PDA, pp. 40–50.
- Ética, Naturaleza y Sociedades: Contenidos y PDA, pp. 54–62.
- De lo Humano y lo Comunitario: Contenidos y PDA, pp. 67–72.

Grados:
- 1.º primaria
- 2.º primaria

### Fase 4 — 3.º y 4.º de primaria

Fuente:
https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/09/Programa_Sintetico_Fase_4.pdf

Primera edición: 2024.

Rangos impresos útiles para extracción del catálogo:
- Lenguajes: Contenidos y PDA, pp. 24–38.
- Saberes y Pensamiento Científico: Contenidos y PDA, pp. 42–52.
- Ética, Naturaleza y Sociedades: Contenidos y PDA, pp. 56–68.
- De lo Humano y lo Comunitario: Contenidos y PDA, pp. 72–78.

Grados:
- 3.º primaria
- 4.º primaria

### Fase 5 — 5.º y 6.º de primaria

Fuente:
https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/09/Programa_Sintetico_Fase_5.pdf

Primera edición: 2024.

Rangos impresos útiles para extracción del catálogo:
- Lenguajes: Contenidos y PDA, pp. 22–36.
- Saberes y Pensamiento Científico: Contenidos y PDA, pp. 40–54.
- Ética, Naturaleza y Sociedades: Contenidos y PDA, pp. 59–78.
- De lo Humano y lo Comunitario: Contenidos y PDA, pp. 83–86.

Grados:
- 5.º primaria
- 6.º primaria

## Campos formativos

1. Lenguajes
2. Saberes y Pensamiento Científico
3. Ética, Naturaleza y Sociedades
4. De lo Humano y lo Comunitario

## Ejes articuladores

1. Inclusión
2. Pensamiento crítico
3. Interculturalidad crítica
4. Igualdad de género
5. Vida saludable
6. Apropiación de las culturas a través de la lectura y la escritura
7. Artes y experiencias estéticas

## Convención propuesta de códigos internos

Para evitar colisiones semánticas entre niveles:
- Preescolar usa P1, P2, P3.
- Primaria conserva G1, G2, G3, G4, G5, G6.

Los códigos son internos de la plataforma y NO se presentan como identificadores oficiales SEP.

Currículo:
- MX-NEM-PRIMARY

Versión:
- MX-NEM-PRIMARY-2024-V1

Fases:
- F3
- F4
- F5

Grados:
- G1
- G2
- G3
- G4
- G5
- G6

Campos:
- LEN
- SPC
- ENS
- DHC

Ejes:
- AX-INCLUSION
- AX-CRITICAL
- AX-INTERCULTURAL
- AX-GENDER
- AX-HEALTH
- AX-LITERACY
- AX-ARTS

Contenidos:
- {FASE}-{CAMPO}-C001
- {FASE}-{CAMPO}-C002
- ...

PDA:
- {FASE}-{GRADO}-{CAMPO}-C001-P01
- {FASE}-{GRADO}-{CAMPO}-C001-P02
- ...

Ejemplo:
F3-G2-LEN-C004-P02

## Reglas de carga

- Un contenido pertenece a una sola CurriculumVersion, fase y campo formativo.
- Un PDA pertenece a un contenido y a un grado específico.
- El grado del PDA debe pertenecer a la misma fase del contenido.
- Un contenido puede tener varios PDA por grado.
- No se deben inventar equivalencias entre fases.
- Los ejes se cargan de forma transversal; no se asigna automáticamente un eje a cada contenido salvo que exista una regla editorial explícita.
- Los textos oficiales deben conservarse sin reinterpretación en `full_text`.
- `source_locator` debe indicar fase, campo y página impresa de procedencia.
- Toda carga real entra primero como borrador.
- La publicación requiere validación editorial.
- Una versión publicada no se modifica: las correcciones crean otra CurriculumVersion.

## Estrategia para la primera carga real

1. Validar el contrato JSON definitivo que implemente la Subfase 2A.
2. Extraer Fase 2 como currículo independiente `MX-NEM-PRESCHOOL`.
3. Ejecutar dry-run y validar 1.º, 2.º y 3.º de preescolar por campo.
4. Corregir estructura/códigos e importar el borrador de Preescolar.
5. Revisar conteos, relaciones contenido/PDA y una muestra manual contra el PDF oficial.
6. Publicar Preescolar únicamente después de revisión editorial.
7. Mantener Primaria como `MX-NEM-PRIMARY`, con Fases 3, 4 y 5 en su propia CurriculumVersion.
8. Ejecutar las mismas validaciones cruzadas para Primaria.
9. No importar Fase 1 ni Fase 6 en estos currículos.
10. Toda corrección posterior crea una nueva CurriculumVersion; nunca se modifica una publicada.

## Nota sobre un archivo previo del usuario

El archivo `NUE_MD_23_01_merged[1].pdf` localizado en la biblioteca contiene material de Fase 2 / preescolar en sus tablas curriculares y no debe usarse como fuente del catálogo de primaria.
