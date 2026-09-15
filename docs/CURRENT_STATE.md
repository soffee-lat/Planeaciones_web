# Estado actual del proyecto

Actualizado: 2026-09-15.

Este archivo resume la arquitectura vigente de Planeaciones_web.

## Arquitectura vigente

La planeación pedagógica se genera de forma canónica e independiente del formato de salida.

Flujo principal:

Contexto docente -> currículo confirmado -> snapshot inmutable -> autorización comercial -> generación canónica -> auditoría y correcciones -> revisión humana cuando aplica -> aprobación -> exportación Standard v2 o institucional -> DOCX/PDF -> entrega privada.

## Reglas

- La IA no define ni modifica textos curriculares oficiales.
- El formato DOCX no forma parte del input de generación.
- Cambiar el formato de exportación no vuelve a ejecutar IA.
- Standard v2 es la salida predeterminada.
- Los formatos institucionales son adaptadores de exportación.
- Las versiones aprobadas o entregadas son inmutables; las correcciones crean versiones nuevas.
- El catálogo curricular publicado requiere además elegibilidad de producción.

## Estado

Las Fases 1 a 6 están cerradas funcionalmente. El trabajo activo se concentra en currículo oficial de primaria Fases 3 a 5, consolidación del flujo docente y validación del MVP curricular.

La integración de un proveedor IA real debe montarse sobre el contrato canónico y validarse antes de considerarse productiva.

## Prioridad documental

Ante contradicciones, usar este orden: código y tests de la rama activa; este archivo; documentos de arquitectura vigentes; documentos específicos recientes; cierres de fase; Git history y docs/legacy.

TASKS.md debe representar el backlog actual, no el diario completo del proyecto.
