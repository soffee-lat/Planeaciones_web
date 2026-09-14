# Mapa de fuentes curriculares — Primaria NEM

## Objetivo

Este documento prepara la carga del catálogo curricular de primaria para la plataforma de planeaciones.
No sustituye la validación editorial ni constituye por sí mismo el catálogo importable definitivo.

## Base normativa

- Plan de Estudio para la educación preescolar, primaria y secundaria — Acuerdo 14/08/22.
- Modificación al Plan de Estudio — Acuerdo 06/08/23.
- Programas Sintéticos de las Fases 2 a 6 — Acuerdo 08/08/23.

## Fuentes SEP para primaria

### Fase 3 — 1.º y 2.º de primaria

Fuente:
https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/06/Programa_Sintetico_Fase_3.pdf

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
https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/06/Programa_Sintetico_Fase_4.pdf

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
https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/06/Programa_Sintetico_Fase_5.pdf

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

Los códigos son internos de la plataforma y NO se presentan como identificadores oficiales SEP.

Currículo:
- MX-NEM-PRIMARIA

Versión:
- MX-NEM-PRIMARIA-2024-V1

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
2. Extraer Fase 3.
3. Ejecutar dry-run.
4. Corregir estructura/códigos.
5. Importar borrador.
6. Repetir para Fases 4 y 5 dentro de la misma CurriculumVersion.
7. Ejecutar validaciones cruzadas.
8. Revisar conteos por fase/campo/grado.
9. Validar una muestra manual contra los PDF.
10. Publicar solo después de revisión.

## Nota sobre un archivo previo del usuario

El archivo `NUE_MD_23_01_merged[1].pdf` localizado en la biblioteca contiene material de Fase 2 / preescolar en sus tablas curriculares y no debe usarse como fuente del catálogo de primaria.
